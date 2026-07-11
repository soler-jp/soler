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
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class FiscalYear extends Model
{
    use HasFactory;

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
