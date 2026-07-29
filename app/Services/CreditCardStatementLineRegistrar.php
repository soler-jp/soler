<?php

namespace App\Services;

use App\Models\CreditCardStatementLine;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Concerns\AuthorizesBusinessUnitAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreditCardStatementLineRegistrar
{
    use AuthorizesBusinessUnitAccess;

    public function __construct(
        private readonly TransactionRegistrar $transactionRegistrar,
    ) {}

    public function register(CreditCardStatementLine $line, ?User $user, array $attributes): Transaction
    {
        $validated = $this->validateRegisterAttributes($attributes);

        return DB::transaction(function () use ($line, $user, $validated): Transaction {
            $lockedLine = CreditCardStatementLine::query()
                ->with(['statement.creditCard.businessUnit', 'importBatch'])
                ->lockForUpdate()
                ->findOrFail($line->getKey());

            $this->authorizeUser($lockedLine, $user);
            $this->ensureLineCanBeRegistered($lockedLine);

            $transactionDate = $this->resolveTransactionDate($lockedLine);
            $fiscalYear = $this->resolveFiscalYear($lockedLine, $transactionDate);

            $transaction = $this->transactionRegistrar->register(
                $fiscalYear,
                $this->buildTransactionData($lockedLine, $user, $validated, $transactionDate),
                $this->buildJournalEntriesData($lockedLine, $validated),
            );

            $lockedLine->forceFill([
                'transaction_id' => $transaction->id,
                'status' => CreditCardStatementLine::STATUS_REGISTERED,
                'reviewed_by' => $user?->id,
                'reviewed_at' => now(),
                'memo' => $validated['memo'] ?? $lockedLine->memo,
            ])->save();

            return $transaction->fresh(['journalEntries', 'creditCardStatementLines', 'fiscalYear', 'counterparty']);
        }, attempts: 5);
    }

    public function cancelRegistration(CreditCardStatementLine $line, ?User $user, ?string $reason = null): void
    {
        DB::transaction(function () use ($line, $user, $reason): void {
            $lockedLine = CreditCardStatementLine::query()
                ->with(['statement.creditCard.businessUnit', 'transaction.fiscalYear'])
                ->lockForUpdate()
                ->findOrFail($line->getKey());

            $this->authorizeUser($lockedLine, $user);
            $this->ensureLineCanBeCancelled($lockedLine);

            $cancelReason = $this->normalizeOptionalString($reason) ?? 'クレジットカード明細の登録取消';

            $lockedLine->transaction->deactivate($user, $cancelReason);

            $payload = [
                'transaction_id' => null,
                'status' => CreditCardStatementLine::STATUS_UNREVIEWED,
                'reviewed_by' => $user?->id,
                'reviewed_at' => now(),
            ];

            if ($reason !== null) {
                $payload['memo'] = $cancelReason;
            }

            $lockedLine->forceFill($payload)->save();
        }, attempts: 5);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function validateRegisterAttributes(array $attributes): array
    {
        return Validator::make($attributes, [
            'debit_sub_account_id' => ['required', 'exists:sub_accounts,id'],
            'tax_type' => ['nullable', 'string', 'max:255'],
            'business_ratio' => ['nullable', 'integer', 'min:1', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string'],
            'memo' => ['nullable', 'string'],
            'counterparty_id' => ['nullable', 'exists:counterparties,id'],
            'counterparty_name' => ['nullable', 'string', 'max:255'],
        ], [], [
            'debit_sub_account_id' => '借方補助科目',
            'tax_type' => '消費税区分',
            'business_ratio' => '事業割合',
            'description' => '摘要',
            'remarks' => '備考',
            'memo' => 'メモ',
            'counterparty_id' => '取引先',
            'counterparty_name' => '取引先名',
        ])->validate();
    }

    protected function authorizeUser(CreditCardStatementLine $line, ?User $user): void
    {
        $this->authorizeBusinessUnitAccess(
            $line,
            $user,
            'このクレジットカード明細を操作する権限がありません。',
        );
    }

    protected function ensureLineCanBeRegistered(CreditCardStatementLine $line): void
    {
        if (! $line->is_active) {
            throw ValidationException::withMessages([
                'line' => ['無効化された明細行は登録できません。'],
            ]);
        }

        if ($line->status !== CreditCardStatementLine::STATUS_UNREVIEWED) {
            throw ValidationException::withMessages([
                'line' => ['未レビューの明細行だけを登録できます。'],
            ]);
        }

        if ($line->transaction_id !== null) {
            throw ValidationException::withMessages([
                'line' => ['この明細行にはすでに取引が紐づいています。'],
            ]);
        }

        if ($line->credit_card_import_batch_id === null || $line->importBatch === null) {
            throw ValidationException::withMessages([
                'line' => ['取込バッチに紐づかない明細行は登録できません。'],
            ]);
        }

        if (! $line->importBatch->is_active) {
            throw ValidationException::withMessages([
                'line' => ['無効化された取込バッチの明細行は登録できません。'],
            ]);
        }

        if ($line->amount <= 0) {
            throw ValidationException::withMessages([
                'line' => ['0円以下の明細行は登録できません。'],
            ]);
        }

        if ($line->statement?->creditCard?->defaultCreditSubAccountId() === null) {
            throw ValidationException::withMessages([
                'credit_card' => ['クレジットカードの貸方補助科目が設定されていません。'],
            ]);
        }
    }

    protected function ensureLineCanBeCancelled(CreditCardStatementLine $line): void
    {
        if (! $line->is_active) {
            throw ValidationException::withMessages([
                'line' => ['無効化された明細行は登録取消できません。'],
            ]);
        }

        if ($line->status !== CreditCardStatementLine::STATUS_REGISTERED) {
            throw ValidationException::withMessages([
                'line' => ['登録済みの明細行だけを登録取消できます。'],
            ]);
        }

        if ($line->transaction_id === null || $line->transaction === null) {
            throw ValidationException::withMessages([
                'line' => ['紐づく取引が存在しません。'],
            ]);
        }

        if ($line->credit_card_import_batch_id === null) {
            throw ValidationException::withMessages([
                'line' => ['取込バッチに紐づかない明細行は登録取消できません。'],
            ]);
        }
    }

    protected function resolveTransactionDate(CreditCardStatementLine $line): Carbon
    {
        $date = $line->used_on;

        if ($date === null) {
            throw ValidationException::withMessages([
                'date' => ['利用日が設定された明細行だけを登録できます。'],
            ]);
        }

        return Carbon::parse($date)->startOfDay();
    }

    protected function resolveFiscalYear(CreditCardStatementLine $line, Carbon $transactionDate): FiscalYear
    {
        $businessUnit = $line->statement?->creditCard?->businessUnit;

        $fiscalYear = $businessUnit?->fiscalYears()
            ->whereDate('start_date', '<=', $transactionDate->toDateString())
            ->whereDate('end_date', '>=', $transactionDate->toDateString())
            ->first();

        if ($fiscalYear === null) {
            throw ValidationException::withMessages([
                'date' => ['取引日に対応する会計年度が見つかりません。'],
            ]);
        }

        if ($fiscalYear->is_closed) {
            throw ValidationException::withMessages([
                'date' => ['決算済みの会計年度に属する明細行は登録できません。'],
            ]);
        }

        return $fiscalYear;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function buildTransactionData(
        CreditCardStatementLine $line,
        ?User $user,
        array $validated,
        Carbon $transactionDate,
    ): array {
        $description = $this->normalizeOptionalString($validated['description'] ?? null)
            ?? $this->normalizeOptionalString($line->merchant_name)
            ?? $this->normalizeOptionalString($line->description);

        if ($description === null) {
            throw ValidationException::withMessages([
                'description' => ['摘要を決定できません。'],
            ]);
        }

        $transactionData = [
            'date' => $transactionDate->toDateString(),
            'description' => $description,
            'remarks' => $this->normalizeOptionalString($validated['remarks'] ?? null) ?? $this->buildDefaultRemarks($line),
            'created_by' => $user?->id,
            'credit_card_import_batch_id' => $line->credit_card_import_batch_id,
        ];

        $counterpartyId = $validated['counterparty_id'] ?? null;
        $counterpartyName = $this->normalizeOptionalString($validated['counterparty_name'] ?? null);

        if ($counterpartyId !== null) {
            $transactionData['counterparty_id'] = $counterpartyId;
        } elseif ($counterpartyName !== null) {
            $transactionData['counterparty_name'] = $counterpartyName;
        }

        return $transactionData;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<int, array<string, mixed>>
     */
    protected function buildJournalEntriesData(CreditCardStatementLine $line, array $validated): array
    {
        $creditSubAccountId = $line->statement->creditCard->defaultCreditSubAccountId();

        $debitEntry = [
            'sub_account_id' => (int) $validated['debit_sub_account_id'],
            'type' => JournalEntry::TYPE_DEBIT,
            'gross_amount' => (int) $line->amount,
            'tax_type' => $validated['tax_type'] ?? null,
        ];

        if (($validated['business_ratio'] ?? null) !== null) {
            $debitEntry['business_ratio'] = (int) $validated['business_ratio'];
        }

        return [
            $debitEntry,
            [
                'sub_account_id' => $creditSubAccountId,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => (int) $line->amount,
            ],
        ];
    }

    protected function buildDefaultRemarks(CreditCardStatementLine $line): ?string
    {
        $parts = [];

        $merchantName = $this->normalizeOptionalString($line->merchant_name);
        $description = $this->normalizeOptionalString($line->description);

        if ($merchantName !== null) {
            $parts[] = 'カード明細: '.$merchantName;
        }

        if ($description !== null && $description !== $merchantName) {
            $parts[] = '元摘要: '.$description;
        }

        return empty($parts) ? null : implode(' / ', $parts);
    }

    protected function normalizeOptionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
