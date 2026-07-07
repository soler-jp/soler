<?php

namespace App\Models;

use App\Services\BlueReturnStatementCalculator;
use App\Services\FiscalYearBalanceCalculator;
use App\Services\FiscalYearCloser;
use App\Services\FiscalYearRolloverDataCalculator;
use App\Services\FiscalYearSummaryCalculator;
use App\Services\OpeningEntryRegistrar;
use App\Services\TransactionRegistrar;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
     *     }
     * }
     */
    public function calculateBlueReturnStatement(int $blueReturnDeduction): array
    {
        return app(BlueReturnStatementCalculator::class)->calculate($this, $blueReturnDeduction);
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
