<?php

namespace App\Services;

use App\Models\Account;
use App\Models\BusinessUnit;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\SubAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Validators\JournalEntryValidator;
use App\Validators\TransactionValidator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TransactionRegistrar
{
    /**
     * 取引と仕訳の登録を行う
     *
     * @param  array  $transactionData  取引情報（例: fiscal_year_id, date, description）
     * @param  array  $journalEntriesData  仕訳情報（複数）（例: sub_account_id, type, net_amount, ...）
     *
     * @throws ValidationException
     */
    public function register(?FiscalYear $fiscalYear, array $transactionData, array $journalEntriesData): Transaction
    {
        // fiscalYear がnullの場合はバリデーションエラー
        if (is_null($fiscalYear)) {
            throw ValidationException::withMessages([
                'fiscal_year_id' => ['Fiscal year is required.'],
            ]);
        }

        if ($fiscalYear->is_closed) {
            throw ValidationException::withMessages([
                'fiscal_year_id' => ['決算済みの会計年度には新規取引を登録できません。'],
            ]);
        }

        // バリデーション（失敗すると ValidationException をスロー）
        $transactionData['fiscal_year_id'] = $fiscalYear->id;
        $transactionData = $this->resolveCounterparty($fiscalYear, $transactionData);
        $transactionData = TransactionValidator::validate($transactionData);

        $this->ensureDateWithinFiscalYear($fiscalYear, $transactionData['date']);

        $normalizedEntries = $this->prepareJournalEntries($fiscalYear, $journalEntriesData);
        $validatedEntries = [];

        foreach ($normalizedEntries as $entry) {
            $validatedEntries[] = JournalEntryValidator::validate($entry, false);
        }

        $this->ensureTaxTypeAllowedForFiscalYear($fiscalYear, $validatedEntries);

        $this->ensureEntriesBelongToBusinessUnit($fiscalYear, $validatedEntries);

        if (empty($journalEntriesData)) {
            throw new \InvalidArgumentException('仕訳データが空です。');
        }

        // ドメインロジック: 仕訳の金額がバランスしているか確認
        $totalDebit = $this->totalWithTax(array_filter($validatedEntries, fn ($e) => $e['type'] === JournalEntry::TYPE_DEBIT));
        $totalCredit = $this->totalWithTax(array_filter($validatedEntries, fn ($e) => $e['type'] === JournalEntry::TYPE_CREDIT));

        if ($totalDebit !== $totalCredit) {
            $diff = $totalDebit - $totalCredit;
            throw new \DomainException(sprintf(
                '仕訳の金額がバランスしていません（借方: %d / 貸方: %d / 差額: %+d）',
                $totalDebit,
                $totalCredit,
                $diff
            ));
        }

        return DB::transaction(function () use ($transactionData, $validatedEntries) {
            $transaction = Transaction::create(TransactionValidator::validate($transactionData));

            foreach ($validatedEntries as $entry) {
                $entry['transaction_id'] = $transaction->id;
                $transaction->journalEntries()->create($entry);
            }

            return $transaction;
        });
    }

    public function totalWithTax(array $entries): int
    {
        return collect($entries)->sum(fn ($e) => (int) ($e['net_amount'] ?? 0) + (int) ($e['tax_amount'] ?? 0));
    }

    public function prepareJournalEntries(FiscalYear $fiscalYear, array $journalEntriesData): array
    {
        $preparedEntries = [];

        foreach ($journalEntriesData as $index => $entry) {
            foreach ($this->prepareJournalEntry($fiscalYear, $entry, $index) as $preparedEntry) {
                $preparedEntries[] = $preparedEntry;
            }
        }

        return $preparedEntries;
    }

    public function buildPlannedTransactionData(Transaction $transaction, array $overrides = []): array
    {
        $base = [
            'date' => $transaction->date?->toDateString(),
            'description' => $transaction->description,
            'remarks' => $transaction->remarks,
            'counterparty_id' => $transaction->counterparty_id,
            'created_by' => $transaction->created_by,
            'recurring_transaction_plan_id' => $transaction->recurring_transaction_plan_id,
        ];

        return array_merge($base, $overrides);
    }

    public function buildPlannedJournalEntries(Transaction $transaction, array $overrides = []): array
    {
        $transaction->loadMissing('journalEntries');

        $debitEntry = $transaction->journalEntries->first(function (JournalEntry $entry): bool {
            return $entry->type === JournalEntry::TYPE_DEBIT && $entry->business_ratio !== null;
        }) ?? $transaction->journalEntries->firstWhere('type', JournalEntry::TYPE_DEBIT);

        $creditEntry = $transaction->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT);

        if ($debitEntry === null || $creditEntry === null) {
            return [];
        }

        $grossAmount = (int) ($overrides['amount'] ?? $creditEntry->net_amount);
        $businessRatio = $overrides['business_ratio'] ?? $debitEntry->business_ratio;

        $debitPlannedEntry = [
            'sub_account_id' => $debitEntry->sub_account_id,
            'type' => JournalEntry::TYPE_DEBIT,
            'gross_amount' => $grossAmount,
            'tax_type' => $debitEntry->tax_type,
        ];

        if ($businessRatio !== null) {
            $debitPlannedEntry['business_ratio'] = $businessRatio;
        }

        return [
            $debitPlannedEntry,
            [
                'sub_account_id' => $overrides['credit_sub_account_id'] ?? $creditEntry->sub_account_id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => $grossAmount,
            ],
        ];
    }

    public function confirmPlanned(Transaction $transaction): Transaction
    {
        if (! $transaction->is_planned) {
            throw new \InvalidArgumentException('この取引は既に本登録されています。');
        }

        $overrides = $this->buildPlannedTransactionData($transaction);

        return app(PlannedTransactionConfirmer::class)->confirm(
            $transaction,
            auth()->user(),
            $overrides,
            $this->buildPlannedJournalEntries($transaction, $overrides),
        );
    }

    /**
     * 取引日が会計年度の期間内であることを確認する
     *
     * fiscal_year_id 基準で集計する処理（年度サマリ・残高集計など）は
     * 取引日が年度期間内であることを前提にしているため、登録時に保証する。
     */
    public function ensureDateWithinFiscalYear(FiscalYear $fiscalYear, mixed $date): void
    {
        $transactionDate = Carbon::parse($date)->startOfDay();

        if ($transactionDate->lt($fiscalYear->start_date) || $transactionDate->gt($fiscalYear->end_date)) {
            throw ValidationException::withMessages([
                'date' => [sprintf(
                    '取引日は会計年度の期間内（%s〜%s）で指定してください。',
                    $fiscalYear->start_date->toDateString(),
                    $fiscalYear->end_date->toDateString(),
                )],
            ]);
        }
    }

    public function resolveCounterparty(FiscalYear $fiscalYear, array $transactionData): array
    {
        $businessUnit = $fiscalYear->businessUnit;
        $counterpartyId = $transactionData['counterparty_id'] ?? null;
        $counterpartyName = $this->normalizeCounterpartyName($transactionData['counterparty_name'] ?? null);
        $registrationNumber = $this->normalizeRegistrationNumber($transactionData['counterparty_registration_number'] ?? null);

        unset($transactionData['counterparty_name'], $transactionData['counterparty_registration_number']);

        if ($counterpartyId !== null && $counterpartyName !== null) {
            throw ValidationException::withMessages([
                'counterparty_name' => ['取引先IDと取引先名は同時に指定できません。'],
            ]);
        }

        if ($counterpartyId !== null) {
            $counterparty = $businessUnit->counterparties()->whereKey($counterpartyId)->first();

            if (! $counterparty) {
                throw ValidationException::withMessages([
                    'counterparty_id' => ['選択中の事業体に属する取引先を指定してください。'],
                ]);
            }

            $transactionData['counterparty_id'] = $counterparty->id;

            return $transactionData;
        }

        if ($counterpartyName === null) {
            unset($transactionData['counterparty_id']);

            return $transactionData;
        }

        $counterparty = $businessUnit->counterparties()->firstOrCreate([
            'name' => $counterpartyName,
        ]);

        if ($registrationNumber !== null && (
            $counterparty->wasRecentlyCreated || blank($counterparty->registration_number)
        )) {
            $counterparty->forceFill([
                'registration_number' => $registrationNumber,
            ])->save();
        }

        $transactionData['counterparty_id'] = $counterparty->id;

        return $transactionData;
    }

    protected function normalizeCounterpartyName(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $name = trim((string) $value);

        return $name === '' ? null : $name;
    }

    protected function normalizeRegistrationNumber(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $number = strtoupper(preg_replace('/\s+/', '', trim((string) $value)));

        if ($number === '') {
            return null;
        }

        if (preg_match('/^T\d{13}$/', $number) === 1) {
            return $number;
        }

        if (preg_match('/^\d{13}$/', $number) === 1) {
            return 'T'.$number;
        }

        throw ValidationException::withMessages([
            'counterparty_registration_number' => ['適格請求書発行事業者登録番号の形式が正しくありません。'],
        ]);
    }

    protected function prepareJournalEntry(FiscalYear $fiscalYear, array $entry, int $index): array
    {
        $hasGrossAmount = array_key_exists('gross_amount', $entry) && ! array_key_exists('net_amount', $entry);
        $hasBusinessRatio = array_key_exists('business_ratio', $entry) && $entry['business_ratio'] !== null;
        $taxTypeProvided = array_key_exists('tax_type', $entry) && $entry['tax_type'] !== null;

        if (! $hasGrossAmount) {
            if ($hasBusinessRatio) {
                throw ValidationException::withMessages([
                    "journal_entries.$index.business_ratio" => ['事業割合は税込入力の借方経費行でのみ指定できます。'],
                ]);
            }

            return [$this->withTaxAmountSource($entry)];
        }

        $grossAmount = (int) $entry['gross_amount'];
        $taxType = $entry['tax_type'] ?? null;

        if ($taxType === null && $fiscalYear->is_taxable) {
            throw ValidationException::withMessages([
                'tax_type' => ['課税事業者の税込入力では消費税区分が必須です。'],
            ]);
        }

        if ($taxType === null) {
            $taxType = $this->defaultTaxTypeForExemptBusiness($entry['type'] ?? null);
        }

        $taxAmountSource = JournalEntry::TAX_AMOUNT_SOURCE_COMPUTED_FROM_GROSS;

        $subAccount = $this->resolveSubAccount($fiscalYear, $entry['sub_account_id'] ?? null);
        $allowsAllocation = $this->canApplyHouseholdAllocation($subAccount, $entry['type'] ?? null, $hasGrossAmount);

        if ($hasBusinessRatio && $subAccount !== null && ! $allowsAllocation) {
            throw ValidationException::withMessages([
                "journal_entries.$index.business_ratio" => ['事業割合は借方の費用科目でのみ指定できます。'],
            ]);
        }

        $businessRatio = $hasBusinessRatio ? (int) $entry['business_ratio'] : null;

        if ($allowsAllocation && $businessRatio === null) {
            $businessRatio = 100;
        }

        if ($businessRatio !== null && ($businessRatio < 1 || $businessRatio > 100)) {
            throw ValidationException::withMessages([
                "journal_entries.$index.business_ratio" => ['事業割合は1〜100の範囲で指定してください。'],
            ]);
        }

        unset($entry['gross_amount']);

        if ($businessRatio === null) {
            [$netAmount, $taxAmount] = $this->splitGrossAmount($grossAmount, $taxType);

            return [
                array_merge($entry, [
                    'net_amount' => $netAmount,
                    'tax_amount' => $taxAmount,
                    'tax_type' => $taxType,
                    'tax_amount_source' => $taxAmountSource,
                ]),
            ];
        }

        $businessGrossAmount = intdiv($grossAmount * $businessRatio, 100);
        $householdGrossAmount = $grossAmount - $businessGrossAmount;
        $allocationGroupId = $businessRatio === 100 ? null : (string) Str::uuid();

        [$businessNetAmount, $businessTaxAmount] = $this->splitGrossAmount($businessGrossAmount, $taxType);

        $businessEntry = array_merge($entry, [
            'net_amount' => $businessNetAmount,
            'tax_amount' => $businessTaxAmount,
            'tax_type' => $taxType,
            'tax_amount_source' => $taxAmountSource,
            'business_ratio' => $businessRatio,
        ]);

        if ($allocationGroupId !== null) {
            $businessEntry['allocation_group_id'] = $allocationGroupId;
        }

        if ($householdGrossAmount === 0) {
            return [$businessEntry];
        }

        $householdAllocationSubAccount = $this->resolveHouseholdAllocationSubAccount($fiscalYear);

        $householdEntry = [
            'sub_account_id' => $householdAllocationSubAccount->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => $householdGrossAmount,
            'tax_amount' => 0,
            'tax_type' => null,
        ];

        if ($allocationGroupId !== null) {
            $householdEntry['allocation_group_id'] = $allocationGroupId;
        }

        return [$businessEntry, $householdEntry];
    }

    protected function withTaxAmountSource(array $entry): array
    {
        if (($entry['tax_type'] ?? null) === null) {
            unset($entry['tax_amount_source']);

            return $entry;
        }

        if (array_key_exists('tax_amount_source', $entry) && $entry['tax_amount_source'] !== null) {
            return $entry;
        }

        $entry['tax_amount_source'] = array_key_exists('tax_amount', $entry)
            ? JournalEntry::TAX_AMOUNT_SOURCE_USER_INPUT
            : JournalEntry::TAX_AMOUNT_SOURCE_DEFAULTED;

        return $entry;
    }

    protected function defaultTaxTypeForExemptBusiness(?string $entryType): string
    {
        return match ($entryType) {
            JournalEntry::TYPE_CREDIT => JournalEntry::TAX_TYPE_DEEMED_TAXABLE_SALES_10,
            default => JournalEntry::TAX_TYPE_DEEMED_TAXABLE_PURCHASES_10,
        };
    }

    protected function splitGrossAmount(int $grossAmount, string $taxType): array
    {
        $rate = match ($taxType) {
            JournalEntry::TAX_TYPE_TAXABLE_SALES_10,
            JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            JournalEntry::TAX_TYPE_DEEMED_TAXABLE_SALES_10,
            JournalEntry::TAX_TYPE_DEEMED_TAXABLE_PURCHASES_10 => 10,
            JournalEntry::TAX_TYPE_TAXABLE_SALES_8,
            JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_8 => 8,
            JournalEntry::TAX_TYPE_EXEMPT,
            JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            JournalEntry::TAX_TYPE_ZERO_RATED => 0,
            default => throw ValidationException::withMessages([
                'tax_type' => ['未対応の消費税区分です。'],
            ]),
        };

        if ($rate === 0) {
            return [$grossAmount, 0];
        }

        $netAmount = intdiv($grossAmount * 100, 100 + $rate);
        $taxAmount = $grossAmount - $netAmount;

        return [$netAmount, $taxAmount];
    }

    public function ensureEntriesBelongToBusinessUnit(FiscalYear $fiscalYear, array $validatedEntries): void
    {
        $businessUnit = $fiscalYear->businessUnit;

        foreach ($validatedEntries as $index => $entry) {
            if (! $businessUnit->hasSubAccount((int) $entry['sub_account_id'])) {
                throw ValidationException::withMessages([
                    "journal_entries.$index.sub_account_id" => ['選択中の事業体に属する補助科目を指定してください。'],
                ]);
            }
        }
    }

    public function ensureTaxTypeAllowedForFiscalYear(FiscalYear $fiscalYear, array $validatedEntries): void
    {
        if (! $fiscalYear->is_taxable) {
            return;
        }

        foreach ($validatedEntries as $index => $entry) {
            $taxType = $entry['tax_type'] ?? null;

            if (in_array($taxType, [
                JournalEntry::TAX_TYPE_DEEMED_TAXABLE_SALES_10,
                JournalEntry::TAX_TYPE_DEEMED_TAXABLE_PURCHASES_10,
            ], true)) {
                throw ValidationException::withMessages([
                    "journal_entries.$index.tax_type" => ['課税事業者の会計年度では見なし消費税区分は使用できません。'],
                ]);
            }
        }
    }

    /**
     * 予定取引を取消する
     *
     * 帳簿の記録ではなく予測の取消なので、確定仕訳は作らず deactivate する。
     * is_planned は true のまま残すことで、定期取引の再生成をブロックする
     * （BusinessUnit::generatePlannedTransactionsForPlan の存在チェックは is_active を見ない）。
     */
    public function cancelPlanned(Transaction $transaction, ?User $user = null): Transaction
    {
        if (! $transaction->is_planned) {
            throw new \InvalidArgumentException('本登録された取引は取消できません。');
        }

        $transaction->deactivate($user, '予定取消');

        return $transaction->fresh();
    }

    protected function resolveSubAccount(FiscalYear $fiscalYear, mixed $subAccountId): ?SubAccount
    {
        if ($subAccountId === null) {
            return null;
        }

        return $fiscalYear->businessUnit->subAccounts()
            ->with('account')
            ->whereKey($subAccountId)
            ->first();
    }

    protected function canApplyHouseholdAllocation(?SubAccount $subAccount, mixed $entryType, bool $hasGrossAmount): bool
    {
        if (! $hasGrossAmount || $subAccount === null || $entryType !== JournalEntry::TYPE_DEBIT) {
            return false;
        }

        return $subAccount->account?->type === Account::TYPE_EXPENSE;
    }

    protected function resolveHouseholdAllocationSubAccount(FiscalYear $fiscalYear): SubAccount
    {
        $ownerDrawAccount = $fiscalYear->businessUnit->accounts()
            ->where('name', '事業主貸')
            ->firstOrFail();

        return $ownerDrawAccount->subAccounts()->firstOrCreate([
            'name' => BusinessUnit::HOUSEHOLD_ALLOCATION_SUB_ACCOUNT_NAME,
        ]);
    }
}
