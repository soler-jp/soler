<?php

namespace App\Services;

use App\Auditing\AuditChanges;
use App\Auditing\AuditContext;
use App\Auditing\AuditEvent;
use App\Auditing\AuditTarget;
use App\Auditing\AuditTargetRole;
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

        $validated = $this->validateRevisionPayload($data);
        $transactionOverrides = $validated['transaction'] ?? [];

        return DB::transaction(function () use ($transaction, $user, $transactionOverrides, $validated) {
            return $this->reviseLockedTransaction(
                $this->lockTransactionForRevision($transaction),
                $user,
                $transactionOverrides,
                $validated['journal_entries'],
            );
        }, attempts: 5);
    }

    public function reviseSinglePair(Transaction $transaction, User $user, array $data): Transaction
    {
        $this->authorizeBusinessUnitAccess($transaction, $user, 'この取引を修正する権限がありません。');

        $validated = $this->validateSinglePairRevisionPayload($data);

        return DB::transaction(function () use ($transaction, $user, $validated) {
            $lockedTransaction = $this->lockTransactionForRevision($transaction);
            [$debitEntry, $creditEntry] = $this->resolveSinglePairEntries($lockedTransaction);

            return $this->reviseLockedTransaction(
                $lockedTransaction,
                $user,
                $validated,
                $this->buildSinglePairJournalEntries($debitEntry, $creditEntry, $validated),
            );
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

    protected function validateRevisionPayload(array $data): array
    {
        return Validator::make(
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

    protected function lockTransactionForRevision(Transaction $transaction): Transaction
    {
        return Transaction::query()
            ->with('fiscalYear')
            ->lockForUpdate()
            ->findOrFail($transaction->getKey());
    }

    protected function reviseLockedTransaction(
        Transaction $lockedTransaction,
        User $user,
        array $transactionOverrides,
        array $journalEntries,
    ): Transaction {
        $this->ensureTransactionCanBeRevised($lockedTransaction);

        if ($lockedTransaction->revision()->exists()) {
            throw new \InvalidArgumentException('この取引はすでに修正されています。');
        }

        return AuditContext::within(AuditEvent::TransactionRevised, function () use (
            $lockedTransaction,
            $user,
            $transactionOverrides,
            $journalEntries,
        ): Transaction {
            $revisedTransaction = $this->transactionRegistrar->register(
                $lockedTransaction->fiscalYear,
                $this->buildRevisedTransactionData($lockedTransaction, $user, $transactionOverrides),
                $journalEntries,
                $user,
            );

            $lockedTransaction->forceFill([
                'is_active' => false,
                'deactivated_at' => now(),
                'deactivated_by' => $user->id,
                'deactivation_reason' => '修正による改訂',
            ])->save();

            $revisedTransaction = $revisedTransaction->fresh(['journalEntries', 'revisedFrom']);

            app(AuditLogger::class)->record(
                event: AuditEvent::TransactionRevised,
                targets: [
                    new AuditTarget(AuditTargetRole::Subject, $revisedTransaction),
                    new AuditTarget(AuditTargetRole::Source, $lockedTransaction),
                ],
                actor: $user,
                changes: AuditChanges::forTransactionRevised($revisedTransaction),
                reason: $transactionOverrides['revision_reason'] ?? null,
            );

            return $revisedTransaction;
        });
    }

    /**
     * @param  array<string, mixed>  $transactionOverrides
     * @return array<string, mixed>
     */
    protected function buildRevisedTransactionData(
        Transaction $transaction,
        User $user,
        array $transactionOverrides,
    ): array {
        $revisedTransactionData = [
            'date' => $transaction->date?->toDateString(),
            'description' => $transaction->description,
            'remarks' => $transaction->remarks,
            'is_opening_entry' => $transaction->is_opening_entry,
            'counterparty_id' => $transaction->counterparty_id,
            'created_by' => $user->id,
            'revised_from_transaction_id' => $transaction->id,
            'revision_reason' => $transactionOverrides['revision_reason'],
        ];

        if (array_key_exists('date', $transactionOverrides)) {
            $revisedTransactionData['date'] = $transactionOverrides['date'];
        }

        if (array_key_exists('description', $transactionOverrides)) {
            $revisedTransactionData['description'] = $transactionOverrides['description'];
        }

        return $revisedTransactionData;
    }

    /**
     * @return array{0: JournalEntry, 1: JournalEntry}
     */
    protected function resolveSinglePairEntries(Transaction $transaction): array
    {
        $transaction->loadMissing('journalEntries');

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
