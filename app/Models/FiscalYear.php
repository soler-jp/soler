<?php

namespace App\Models;

use App\Services\BlueReturnInputRegistrar;
use App\Services\BlueReturnPdf\BlueReturnStatementPdfGenerator;
use App\Services\BlueReturnStatementCalculator;
use App\Services\FiscalYearBalanceCalculator;
use App\Services\FiscalYearCloser;
use App\Services\FiscalYearRolloverDataCalculator;
use App\Services\FiscalYearSummaryCalculator;
use App\Services\OpeningEntryRegistrar;
use App\Services\TransactionRegistrar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class FiscalYear extends Model
{
    use HasFactory;

    private const PURCHASE_ACCOUNT_NAMES = ['仕入金額'];

    protected $fillable = [
        'business_unit_id',
        'year',
        'is_active',
        'is_closed',    // 決算済フラグ
        'closed_at',
        'closed_by',
        'is_taxable',   // 課税事業者ならtrue, 免税事業者なfalse
        'is_tax_exclusive',  // 税抜経理ならtrue, 税込経理ならfalse
        'start_date',
        'end_date',

    ];

    protected $casts = [
        'is_taxable' => 'boolean',
        'is_tax_exclusive' => 'boolean',
        'is_active' => 'boolean',
        'is_closed' => 'boolean',
        'closed_at' => 'datetime',
        'closed_by' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function businessUnit()
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return HasMany<BlueReturnInput, $this>
     */
    public function blueReturnInputs(): HasMany
    {
        return $this->hasMany(BlueReturnInput::class);
    }

    public function journalEntries()
    {
        return $this->hasManyThrough(
            JournalEntry::class,
            Transaction::class
        );
    }

    public function registerTransaction(
        array $transactionData,
        array $journalEntriesData,
        ?TransactionRegistrar $registrar = null
    ): Transaction {
        $registrar ??= app(TransactionRegistrar::class);

        return $registrar->register($this, $transactionData, $journalEntriesData);
    }

    public function calculateSummary(): array
    {
        return app(FiscalYearSummaryCalculator::class)->calculate($this);
    }

    /**
     * @return array<int, array{
     *     key: string,
     *     type: 'account_type'|'amount',
     *     title: string,
     *     variant: string,
     *     account_type?: string,
     *     account_names?: array<int, string>,
     *     excluded_account_names?: array<int, string>,
     *     amount?: int,
     *     note_lines?: array<int, string>
     * }>
     */
    public function managementSummaryCards(): array
    {
        $revenueAmount = $this->monthlyAccountTypeSummaryData(Account::TYPE_REVENUE)['total_amount'];
        $purchaseAmount = $this->monthlyAccountTypeSummaryData(
            Account::TYPE_EXPENSE,
            self::PURCHASE_ACCOUNT_NAMES,
        )['total_amount'];
        $expenseAmount = $this->monthlyAccountTypeSummaryData(
            Account::TYPE_EXPENSE,
            excludedAccountNames: self::PURCHASE_ACCOUNT_NAMES,
        )['total_amount'];

        $cards = [
            $this->managementAccountTypeCard('revenue', '売上', Account::TYPE_REVENUE),
            $this->managementAccountTypeCard(
                'expense',
                '経費',
                Account::TYPE_EXPENSE,
                excludedAccountNames: $purchaseAmount > 0 ? self::PURCHASE_ACCOUNT_NAMES : [],
            ),
        ];

        if ($purchaseAmount <= 0) {
            $cards[] = [
                'key' => 'profit',
                'type' => 'amount',
                'title' => '利益',
                'variant' => 'profit',
                'amount' => $revenueAmount - $expenseAmount,
                'note_lines' => [],
            ];

            return $cards;
        }

        $cards[] = $this->managementAccountTypeCard(
            'purchase',
            '仕入れ',
            Account::TYPE_EXPENSE,
            accountNames: self::PURCHASE_ACCOUNT_NAMES,
        );
        $cards[] = [
            'key' => 'current_difference',
            'type' => 'amount',
            'title' => '今の差し引き',
            'variant' => 'current_difference',
            'amount' => $revenueAmount - $expenseAmount - $purchaseAmount,
            'note_lines' => [
                sprintf('売上から、記録済みの経費と仕入(%s円)を引いた金額です。', number_format($purchaseAmount)),
                '年末に在庫を入力すると、最終的な利益は変わることがあります。',
            ],
        ];

        return $cards;
    }

    /**
     * @param  array<int, string>  $accountNames
     * @param  array<int, string>  $excludedAccountNames
     * @return array{months: array<int, array{year_month: string, label: string, amount: int}>, total_amount: int}
     */
    public function monthlyAccountTypeSummaryData(
        string $accountType,
        array $accountNames = [],
        array $excludedAccountNames = [],
    ): array {
        $months = $this->monthlyAccountTypeSummaries($accountType, $accountNames, $excludedAccountNames);

        return [
            'months' => $months,
            'total_amount' => collect($months)->sum('amount'),
        ];
    }

    /**
     * @param  array<int, string>  $accountNames
     * @param  array<int, string>  $excludedAccountNames
     * @return array{
     *     key: string,
     *     type: 'account_type',
     *     title: string,
     *     variant: string,
     *     account_type: string,
     *     account_names: array<int, string>,
     *     excluded_account_names: array<int, string>
     * }
     */
    private function managementAccountTypeCard(
        string $key,
        string $title,
        string $accountType,
        array $accountNames = [],
        array $excludedAccountNames = [],
    ): array {
        return [
            'key' => $key,
            'type' => 'account_type',
            'title' => $title,
            'variant' => $accountType,
            'account_type' => $accountType,
            'account_names' => $accountNames,
            'excluded_account_names' => $excludedAccountNames,
        ];
    }

    /**
     * @param  array<int, string>  $accountNames
     * @param  array<int, string>  $excludedAccountNames
     * @return array<int, array{year_month: string, label: string, amount: int}>
     */
    public function monthlyAccountTypeSummaries(
        string $accountType,
        array $accountNames = [],
        array $excludedAccountNames = [],
    ): array {
        $normalizedAccountType = $this->normalizeMonthlyAccountType($accountType);
        [$primaryType, $reverseType] = $this->monthlyEntryTypesFor($normalizedAccountType);

        return JournalEntry::query()
            ->with(['transaction:id,date,fiscal_year_id'])
            ->whereHas('transaction', function (Builder $query): void {
                $query
                    ->whereBelongsTo($this)
                    ->where('is_active', true)
                    ->where('is_planned', false);
            })
            ->whereHas('subAccount.account', fn (Builder $query) => $this->applyMonthlyAccountTypeFilters(
                $query,
                $normalizedAccountType,
                $accountNames,
                $excludedAccountNames,
            ))
            ->get(['transaction_id', 'type', 'net_amount', 'tax_amount'])
            ->groupBy(fn (JournalEntry $entry): string => $entry->transaction->date->format('Y-m'))
            ->map(function (Collection $entries, string $yearMonth) use ($primaryType, $reverseType): array {
                $positiveAmount = $entries
                    ->where('type', $primaryType)
                    ->sum(fn (JournalEntry $entry): int => $entry->gross_amount);
                $negativeAmount = $entries
                    ->where('type', $reverseType)
                    ->sum(fn (JournalEntry $entry): int => $entry->gross_amount);
                $amount = $positiveAmount - $negativeAmount;

                return [
                    'year_month' => $yearMonth,
                    'label' => CarbonImmutable::createFromFormat('Y-m', $yearMonth)->isoFormat('YYYY年M月'),
                    'amount' => $amount,
                ];
            })
            ->filter(fn (array $month): bool => $month['amount'] !== 0)
            ->sortBy('year_month')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $accountNames
     * @param  array<int, string>  $excludedAccountNames
     * @return array<int, array{
     *     id: int,
     *     date: string,
     *     amount: int,
     *     payment_amount: int,
     *     description: string,
     *     allocation_note: string,
     *     debit_label: string,
     *     debit_badge_class: string,
     *     credit_label: string,
     *     credit_badge_class: string,
     *     tax_type_label: string,
     *     tax_type_badge_class: string,
     *     counterparty_name: string
     * }>
     */
    public function monthlyAccountTypeTransactions(
        string $accountType,
        string $yearMonth,
        array $accountNames = [],
        array $excludedAccountNames = [],
    ): array {
        $normalizedAccountType = $this->normalizeMonthlyAccountType($accountType);
        [$primaryType, $reverseType] = $this->monthlyEntryTypesFor($normalizedAccountType);
        $monthStart = CarbonImmutable::createFromFormat('Y-m', $yearMonth)->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();

        return Transaction::query()
            ->with([
                'counterparty:id,name',
                'journalEntries.subAccount.account:id,name,type',
            ])
            ->whereBelongsTo($this)
            ->where('is_active', true)
            ->where('is_planned', false)
            ->whereBetween('date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->whereHas('journalEntries.subAccount.account', fn (Builder $query) => $this->applyMonthlyAccountTypeFilters(
                $query,
                $normalizedAccountType,
                $accountNames,
                $excludedAccountNames,
            ))
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(function (Transaction $transaction) use (
                $normalizedAccountType,
                $primaryType,
                $reverseType,
                $accountNames
            ): array {
                $relevantPositiveEntries = $transaction->journalEntries->filter(
                    fn (JournalEntry $entry): bool => $entry->type === $primaryType
                        && $entry->subAccount->account->type === $normalizedAccountType
                        && $this->matchesMonthlyAccountNames($entry->subAccount->account->name, $accountNames)
                );
                $relevantNegativeEntries = $transaction->journalEntries->filter(
                    fn (JournalEntry $entry): bool => $entry->type === $reverseType
                        && $entry->subAccount->account->type === $normalizedAccountType
                        && $this->matchesMonthlyAccountNames($entry->subAccount->account->name, $accountNames)
                );
                $representativeEntries = $relevantPositiveEntries->isNotEmpty()
                    ? $relevantPositiveEntries
                    : $relevantNegativeEntries;
                $paymentAmount = (int) $transaction->journalEntries
                    ->where('type', JournalEntry::TYPE_CREDIT)
                    ->sum(fn (JournalEntry $entry): int => $entry->gross_amount);
                $displayAmount = $this->monthlyDisplayAmount($transaction, $normalizedAccountType);
                $debitLabel = $this->monthlyDebitLabel($transaction, $normalizedAccountType);
                $creditLabel = $this->monthlyEntryLabels($transaction->journalEntries->where('type', JournalEntry::TYPE_CREDIT));

                return [
                    'id' => $transaction->id,
                    'date' => $transaction->date->format('Y-m-d'),
                    'amount' => $displayAmount,
                    'payment_amount' => $paymentAmount,
                    'description' => $this->monthlyTransactionDescription($transaction),
                    'allocation_note' => $this->monthlyAllocationNote(
                        $normalizedAccountType,
                        $paymentAmount,
                        $transaction->business_ratio,
                    ),
                    'debit_label' => $debitLabel,
                    'debit_badge_class' => $this->monthlyBadgeClassForAccount($debitLabel),
                    'credit_label' => $creditLabel,
                    'credit_badge_class' => $this->monthlyBadgeClassForAccount($creditLabel),
                    'tax_type_label' => $this->monthlyTaxTypeLabel($representativeEntries),
                    'tax_type_badge_class' => $this->monthlyTaxTypeBadgeClass($representativeEntries),
                    'counterparty_name' => $transaction->counterparty?->name ?? '',
                ];
            })
            ->filter(fn (array $transaction): bool => $transaction['amount'] !== 0)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $accountNames
     * @param  array<int, string>  $excludedAccountNames
     * @return array<int, array{
     *     year_month: string,
     *     label: string,
     *     amount: int,
     *     transactions: array<int, array{
     *         id: int,
     *         date: string,
     *         amount: int,
     *         payment_amount: int,
     *         description: string,
     *         allocation_note: string,
     *         debit_label: string,
     *         debit_badge_class: string,
     *         credit_label: string,
     *         credit_badge_class: string,
     *         tax_type_label: string,
     *         tax_type_badge_class: string,
     *         counterparty_name: string
     *     }>
     * }>
     */
    public function monthlyAccountTypeTransactionGroups(
        string $accountType,
        array $accountNames = [],
        array $excludedAccountNames = [],
    ): array {
        return collect($this->monthlyAccountTypeSummaries($accountType, $accountNames, $excludedAccountNames))
            ->map(fn (array $month): array => [
                ...$month,
                'transactions' => $this->monthlyAccountTypeTransactions(
                    $accountType,
                    $month['year_month'],
                    $accountNames,
                    $excludedAccountNames,
                ),
            ])
            ->all();
    }

    private function monthlyDisplayAmount(Transaction $transaction, string $accountType): int
    {
        [$primaryType, $reverseType] = $this->monthlyEntryTypesFor($accountType);
        $relevantEntries = $transaction->journalEntries->filter(
            fn (JournalEntry $entry): bool => $entry->subAccount->account->type === $accountType
        );
        $positiveAmount = $relevantEntries
            ->where('type', $primaryType)
            ->sum(fn (JournalEntry $entry): int => $entry->gross_amount);
        $negativeAmount = $relevantEntries
            ->where('type', $reverseType)
            ->sum(fn (JournalEntry $entry): int => $entry->gross_amount);

        return $positiveAmount - $negativeAmount;
    }

    private function monthlyTransactionDescription(Transaction $transaction): string
    {
        return trim((string) ($transaction->description ?? ''));
    }

    private function monthlyAllocationNote(
        string $accountType,
        int $paymentAmount,
        ?int $businessRatio,
    ): string {
        if ($accountType !== Account::TYPE_EXPENSE || $businessRatio === null || $businessRatio >= 100) {
            return '';
        }

        return sprintf(
            '支払い%s円の%d％分',
            number_format($paymentAmount),
            $businessRatio,
        );
    }

    private function normalizeMonthlyAccountType(string $accountType): string
    {
        if (! in_array($accountType, [Account::TYPE_REVENUE, Account::TYPE_EXPENSE], true)) {
            throw new InvalidArgumentException('Unsupported account type.');
        }

        return $accountType;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function monthlyEntryTypesFor(string $accountType): array
    {
        if ($accountType === Account::TYPE_REVENUE) {
            return [JournalEntry::TYPE_CREDIT, JournalEntry::TYPE_DEBIT];
        }

        return [JournalEntry::TYPE_DEBIT, JournalEntry::TYPE_CREDIT];
    }

    /**
     * @param  Collection<int, JournalEntry>  $entries
     */
    private function monthlyEntryLabels(Collection $entries): string
    {
        $labels = $entries
            ->filter(function (JournalEntry $entry): bool {
                return $entry->subAccount->name !== BusinessUnit::HOUSEHOLD_ALLOCATION_SUB_ACCOUNT_NAME;
            })
            ->map(fn (JournalEntry $entry): string => $entry->subAccount->name)
            ->unique()
            ->values();

        if ($labels->isEmpty()) {
            return '-';
        }

        return $labels->implode(' / ');
    }

    private function monthlyDebitLabel(Transaction $transaction, string $accountType): string
    {
        $debitEntries = $transaction->journalEntries->where('type', JournalEntry::TYPE_DEBIT);

        if ($accountType === Account::TYPE_EXPENSE) {
            $label = $this->monthlyEntryLabels($debitEntries);

            if ($label !== '-') {
                return $label;
            }
        }

        return $this->monthlyEntryLabels($debitEntries);
    }

    /**
     * @param  Collection<int, JournalEntry>  $entries
     */
    private function monthlyTaxTypeLabel(Collection $entries): string
    {
        $labels = $entries
            ->pluck('tax_type')
            ->filter()
            ->unique()
            ->map(fn (string $taxType): string => match ($taxType) {
                JournalEntry::TAX_TYPE_TAXABLE_SALES_10,
                JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
                JournalEntry::TAX_TYPE_DEEMED_TAXABLE_SALES_10,
                JournalEntry::TAX_TYPE_DEEMED_TAXABLE_PURCHASES_10 => '10%',
                JournalEntry::TAX_TYPE_TAXABLE_SALES_8,
                JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_8 => '8%',
                JournalEntry::TAX_TYPE_TAX_FREE,
                JournalEntry::TAX_TYPE_NON_TAXABLE => '非課税',
                default => $taxType,
            })
            ->values();

        if ($labels->isEmpty()) {
            return '-';
        }

        return $labels->implode(' / ');
    }

    /**
     * @param  Collection<int, JournalEntry>  $entries
     */
    private function monthlyTaxTypeBadgeClass(Collection $entries): string
    {
        return match ($this->monthlyTaxTypeLabel($entries)) {
            '10%' => 'border-rose-200 bg-rose-50 text-rose-700',
            '8%' => 'border-amber-200 bg-amber-50 text-amber-700',
            '非課税' => 'border-slate-200 bg-slate-50 text-slate-700',
            '不課税' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
            default => 'border-gray-200 bg-gray-50 text-gray-700',
        };
    }

    private function monthlyBadgeClassForAccount(string $label): string
    {
        $styles = [
            'border-sky-200 bg-sky-50 text-sky-700',
            'border-blue-200 bg-blue-50 text-blue-700',
            'border-cyan-200 bg-cyan-50 text-cyan-700',
            'border-indigo-200 bg-indigo-50 text-indigo-700',
            'border-violet-200 bg-violet-50 text-violet-700',
            'border-emerald-200 bg-emerald-50 text-emerald-700',
            'border-rose-200 bg-rose-50 text-rose-700',
            'border-amber-200 bg-amber-50 text-amber-700',
        ];

        return $styles[abs(crc32($label)) % count($styles)];
    }

    /**
     * @param  array<int, string>  $accountNames
     * @param  array<int, string>  $excludedAccountNames
     */
    private function applyMonthlyAccountTypeFilters(
        Builder $query,
        string $accountType,
        array $accountNames,
        array $excludedAccountNames = [],
    ): Builder {
        return $query
            ->where('type', $accountType)
            ->when($accountNames !== [], fn (Builder $builder) => $builder->whereIn('name', $accountNames))
            ->when($excludedAccountNames !== [], fn (Builder $builder) => $builder->whereNotIn('name', $excludedAccountNames));
    }

    /**
     * @param  array<int, string>  $accountNames
     */
    private function matchesMonthlyAccountNames(string $accountName, array $accountNames): bool
    {
        if ($accountNames === []) {
            return true;
        }

        return in_array($accountName, $accountNames, true);
    }

    /**
     * @return array{
     *     profit_and_loss: array<string, int>,
     *     monthly_sales_and_purchases: array{
     *         months: array<int, array{
     *             year_month: string,
     *             label: string,
     *             sales_amount: int,
     *             house_consumption_amount: int,
     *             misc_income_amount: int,
     *             purchases_amount: int
     *         }>,
     *         totals: array{
     *             sales_amount: int,
     *             house_consumption_amount: int,
     *             misc_income_amount: int,
     *             purchases_amount: int
     *         }
     *     },
     *     depreciation_calculation: array{
     *         entries: array<int, array{
     *             fixed_asset_name: string,
     *             quantity: int,
     *             acquisition_year_month: ?string,
     *             depreciation_base_amount: ?int,
     *             depreciation_method: ?string,
     *             useful_life: ?int,
     *             depreciation_rate: ?string,
     *             months: int,
     *             ordinary_amount: int,
     *             total_amount: int,
     *             business_usage_ratio: string|int|float,
     *             deductible_amount: int,
     *             ending_undepreciated_balance: ?int
     *         }>,
     *         totals: array{
     *             ordinary_amount: int,
     *             total_amount: int,
     *             deductible_amount: int,
     *             ledger_depreciation_expense: int,
     *             difference: int
     *         }
     *     },
     *     balance_sheet: array{
     *         income_before_blue_return_deduction: int,
     *         sections: array<string, array{
     *             type: string,
     *             label: string,
     *             opening_total_balance: int,
     *             ending_total_balance: int,
     *             rows: array<int, array{
     *                 account_id: int,
     *                 account_name: string,
     *                 opening_balance: int,
     *                 ending_balance: int,
     *                 rows: array<int, array{
     *                     sub_account_id: int,
     *                     sub_account_name: string,
     *                     opening_balance: int,
     *                     ending_balance: int
     *                 }>
     *             }>
     *         }>,
     *         totals: array{
     *             opening: array{
     *                 asset: int,
     *                 liability: int,
     *                 equity: int
     *             },
     *             ending: array{
     *                 asset: int,
     *                 liability: int,
     *                 equity: int
     *             }
     *         }
     *     }
     * }
     */
    public function calculateBlueReturnStatement(int $blueReturnDeduction): array
    {
        return app(BlueReturnStatementCalculator::class)->calculate($this, $blueReturnDeduction);
    }

    /**
     * 青色申告決算書のPDF（バイナリ文字列）を生成する。
     *
     * @param  array<string, string>  $header  住所・氏名などヘッダー欄の帳簿外情報
     */
    public function generateBlueReturnStatementPdf(int $blueReturnDeduction, array $header = []): string
    {
        return app(BlueReturnStatementPdfGenerator::class)->generate($this, $blueReturnDeduction, $header);
    }

    /**
     * @param  array<string, array<string, mixed>>  $inputs
     * @return Collection<int, BlueReturnInput>
     */
    public function saveBlueReturnInputs(array $inputs): Collection
    {
        return app(BlueReturnInputRegistrar::class)->saveMany($this, $inputs);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public function saveBlueReturnInput(string $key, array $value): BlueReturnInput
    {
        return app(BlueReturnInputRegistrar::class)->save($this, $key, $value);
    }

    public function blueReturnInput(string $key): ?BlueReturnInput
    {
        return $this->blueReturnInputs()->where('key', $key)->first();
    }

    public function calculateAmountSummary(): array
    {
        return app(FiscalYearSummaryCalculator::class)->calculateAmountSummary($this);
    }

    public function calculateBalanceSummary(): array
    {
        return app(FiscalYearBalanceCalculator::class)->calculate($this);
    }

    /**
     * @return array{
     *     next_year: int,
     *     opening_entries: array<int, array{account_name: string, sub_account_name: string, amount: int, type: 'debit'|'credit'}>,
     *     capital_entry: array{account_name: string, sub_account_name: string, amount: int, type: 'debit'|'credit'},
     *     current_profit: int
     * }
     */
    public function calculateRolloverData(): array
    {
        return app(FiscalYearRolloverDataCalculator::class)->calculate($this);
    }

    public function registerOpeningEntry(array $entries): ?Transaction
    {
        return app(OpeningEntryRegistrar::class)->register($this, $entries);
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function close(User $user): self
    {
        return app(FiscalYearCloser::class)->close($this, $user);
    }
}
