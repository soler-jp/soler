<?php

namespace App\Services;

use App\Concerns\AuthorizesBusinessUnitAccess;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TransactionRevisor
{
    use AuthorizesBusinessUnitAccess;

    public function __construct(
        public TransactionRegistrar $transactionRegistrar
    ) {}

    public function revise(Transaction $transaction, User $user, array $data): Transaction
    {
        $this->authorizeBusinessUnitAccess($transaction, $user, 'この取引を修正する権限がありません。');

        $validated = Validator::make(
            $data,
            [
                'transaction.revision_reason' => ['required', 'string', 'max:255'],
                'journal_entries' => ['required', 'array', 'min:1'],
            ],
            [],
            [
                'transaction.revision_reason' => '修正理由',
                'journal_entries' => '仕訳明細',
            ]
        )->validate();

        return DB::transaction(function () use ($transaction, $user, $validated) {
            $lockedTransaction = Transaction::query()
                ->with('fiscalYear')
                ->lockForUpdate()
                ->findOrFail($transaction->getKey());

            $this->ensureTransactionCanBeRevised($lockedTransaction);

            if ($lockedTransaction->revision()->exists()) {
                throw new \InvalidArgumentException('この取引はすでに修正されています。');
            }

            /** @var array<string, mixed> $revisedTransactionData */
            $revisedTransactionData = [
                'date' => $lockedTransaction->date?->toDateString(),
                'description' => $lockedTransaction->description,
                'remarks' => $lockedTransaction->remarks,
                'is_opening_entry' => $lockedTransaction->is_opening_entry,
                'counterparty_id' => $lockedTransaction->counterparty_id,
                'created_by' => $user->id,
                'revised_from_transaction_id' => $lockedTransaction->id,
                'revision_reason' => $validated['transaction']['revision_reason'],
            ];

            $revisedTransaction = $this->transactionRegistrar->register(
                $lockedTransaction->fiscalYear,
                $revisedTransactionData,
                $validated['journal_entries'],
                $user,
            );

            $lockedTransaction->deactivate($user, '修正による改訂');

            return $revisedTransaction->fresh(['journalEntries', 'revisedFrom']);
        }, attempts: 5);
    }

    public function reviseSinglePair(Transaction $transaction, User $user, array $data): Transaction
    {
        $this->authorizeBusinessUnitAccess($transaction, $user, 'この取引を修正する権限がありません。');

        $validated = $this->validateSinglePairRevisionPayload($data);

        return DB::transaction(function () use ($transaction, $user, $validated) {
            $lockedTransaction = Transaction::query()
                ->with(['fiscalYear', 'journalEntries'])
                ->lockForUpdate()
                ->findOrFail($transaction->getKey());

            $this->ensureTransactionCanBeRevised($lockedTransaction);

            if ($lockedTransaction->revision()->exists()) {
                throw new \InvalidArgumentException('この取引はすでに修正されています。');
            }

            [$debitEntry, $creditEntry] = $this->resolveSinglePairEntries($lockedTransaction);

            /** @var array<string, mixed> $revisedTransactionData */
            $revisedTransactionData = [
                'date' => $lockedTransaction->date?->toDateString(),
                'description' => $lockedTransaction->description,
                'remarks' => $lockedTransaction->remarks,
                'is_opening_entry' => $lockedTransaction->is_opening_entry,
                'counterparty_id' => $lockedTransaction->counterparty_id,
                'created_by' => $user->id,
                'revised_from_transaction_id' => $lockedTransaction->id,
                'revision_reason' => $validated['revision_reason'],
            ];

            if (array_key_exists('date', $validated)) {
                $revisedTransactionData['date'] = $validated['date'];
            }

            if (array_key_exists('description', $validated)) {
                $revisedTransactionData['description'] = $validated['description'];
            }

            $revisedTransaction = $this->transactionRegistrar->register(
                $lockedTransaction->fiscalYear,
                $revisedTransactionData,
                $this->buildSinglePairJournalEntries($debitEntry, $creditEntry, $validated),
                $user,
            );

            $lockedTransaction->deactivate($user, '修正による改訂');

            return $revisedTransaction->fresh(['journalEntries', 'revisedFrom']);
        }, attempts: 5);
    }

    protected function ensureTransactionCanBeRevised(Transaction $transaction): void
    {
        if (! $transaction->is_active) {
            throw new \InvalidArgumentException('無効化済みの取引は修正できません。');
        }

        if ($transaction->is_planned) {
            throw new \InvalidArgumentException('予定取引はこの修正機能の対象外です。');
        }

        if ($transaction->is_adjusting_entry) {
            throw new \InvalidArgumentException('決算整理仕訳はこの修正機能の対象外です。');
        }

        if ($transaction->recurring_transaction_plan_id !== null) {
            throw new \InvalidArgumentException('定期取引計画由来の取引はこの修正機能の対象外です。');
        }

        if ($transaction->credit_card_import_batch_id !== null) {
            throw new \InvalidArgumentException('クレジットカード取込由来の取引はこの修正機能の対象外です。');
        }

        if ($transaction->depreciationEntries()->exists()) {
            throw new \InvalidArgumentException('減価償却仕訳はこの修正機能の対象外です。');
        }

        if ($transaction->fiscalYear->is_closed) {
            throw ValidationException::withMessages([
                'transaction' => ['決算済みの会計年度に属する取引は修正できません。'],
            ]);
        }
    }

    protected function validateSinglePairRevisionPayload(array $data): array
    {
        $validator = Validator::make(
            $data,
            [
                'revision_reason' => ['required', 'string', 'max:255'],
                'date' => ['sometimes', 'nullable', 'date'],
                'description' => ['sometimes', 'nullable', 'string', 'max:255'],
                'debit_sub_account_id' => ['sometimes', 'integer', 'exists:sub_accounts,id'],
                'credit_sub_account_id' => ['sometimes', 'integer', 'exists:sub_accounts,id'],
                'tax_type' => ['sometimes', 'nullable', 'in:'.implode(',', JournalEntry::TAX_TYPES)],
                'gross_amount' => ['sometimes', 'integer', 'min:1'],
                'net_amount' => ['sometimes', 'integer', 'min:1'],
                'tax_amount' => ['sometimes', 'integer', 'min:0'],
            ],
            [],
            [
                'revision_reason' => '修正理由',
                'date' => '取引日',
                'description' => '摘要',
                'debit_sub_account_id' => '借方補助科目',
                'credit_sub_account_id' => '貸方補助科目',
                'tax_type' => '消費税区分',
                'gross_amount' => '税込金額',
                'net_amount' => '税抜金額',
                'tax_amount' => '消費税額',
            ]
        );

        $validator->after(function ($validator) use ($data): void {
            $hasGrossAmount = array_key_exists('gross_amount', $data);
            $hasNetAmount = array_key_exists('net_amount', $data);
            $hasTaxAmount = array_key_exists('tax_amount', $data);

            if ($hasGrossAmount && ($hasNetAmount || $hasTaxAmount)) {
                $validator->errors()->add('gross_amount', '税込金額指定と税抜金額/消費税額指定は同時に指定できません。');
            }

            if (! $hasGrossAmount && ! $hasNetAmount && ! $hasTaxAmount) {
                $validator->errors()->add('gross_amount', '税込金額または税抜金額・消費税額を指定してください。');
            }

            if (! $hasGrossAmount && ($hasNetAmount xor $hasTaxAmount)) {
                $validator->errors()->add('net_amount', '税抜金額と消費税額はセットで指定してください。');
            }
        });

        return $validator->validate();
    }

    /**
     * @return array{0: JournalEntry, 1: JournalEntry}
     */
    protected function resolveSinglePairEntries(Transaction $transaction): array
    {
        $debitEntries = $transaction->journalEntries
            ->where('type', JournalEntry::TYPE_DEBIT)
            ->values();
        $creditEntries = $transaction->journalEntries
            ->where('type', JournalEntry::TYPE_CREDIT)
            ->values();

        if ($transaction->journalEntries->count() !== 2 || $debitEntries->count() !== 1 || $creditEntries->count() !== 1) {
            throw new \DomainException('single pair 改訂は借方1行・貸方1行の取引でのみ利用できます。');
        }

        /** @var JournalEntry $debitEntry */
        $debitEntry = $debitEntries->first();
        /** @var JournalEntry $creditEntry */
        $creditEntry = $creditEntries->first();

        return [$debitEntry, $creditEntry];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<int, array<string, int|string>>
     */
    protected function buildSinglePairJournalEntries(
        JournalEntry $debitEntry,
        JournalEntry $creditEntry,
        array $validated,
    ): array {
        $debitSubAccountId = (int) ($validated['debit_sub_account_id'] ?? $debitEntry->sub_account_id);
        $creditSubAccountId = (int) ($validated['credit_sub_account_id'] ?? $creditEntry->sub_account_id);
        $debitTaxType = $validated['tax_type'] ?? $debitEntry->tax_type;

        if (array_key_exists('gross_amount', $validated)) {
            $debitRevisionEntry = [
                'sub_account_id' => $debitSubAccountId,
                'type' => JournalEntry::TYPE_DEBIT,
                'gross_amount' => (int) $validated['gross_amount'],
                'tax_type' => $debitTaxType ?? JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ];

            if ($debitEntry->business_ratio !== null) {
                $debitRevisionEntry['business_ratio'] = (int) $debitEntry->business_ratio;
            }

            return [
                $debitRevisionEntry,
                [
                    'sub_account_id' => $creditSubAccountId,
                    'type' => JournalEntry::TYPE_CREDIT,
                    'gross_amount' => (int) $validated['gross_amount'],
                    'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                ],
            ];
        }

        $netAmount = (int) $validated['net_amount'];
        $taxAmount = (int) $validated['tax_amount'];

        return [
            [
                'sub_account_id' => $debitSubAccountId,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => $netAmount,
                'tax_amount' => $taxAmount,
                'tax_type' => $debitTaxType,
            ],
            [
                'sub_account_id' => $creditSubAccountId,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => $netAmount + $taxAmount,
                'tax_amount' => 0,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ];
    }
}
