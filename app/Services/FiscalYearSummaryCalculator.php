<?php

namespace App\Services;

use App\Models\FiscalYear;
use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Builder;

class FiscalYearSummaryCalculator
{
    /**
     * @return array{
     *     actual: array{total_income: int, total_expense: int, profit: int},
     *     planned: array{total_income: int, total_expense: int, profit: int}
     * }
     */
    public function calculate(FiscalYear $fiscalYear): array
    {
        $amountSummary = $this->calculateAmountSummary($fiscalYear);

        $actualIncome = $amountSummary['actual']['sales']['gross_amount'];
        $actualExpense = $amountSummary['actual']['expenses']['gross_amount'];
        $plannedIncome = $amountSummary['planned']['sales']['gross_amount'];
        $plannedExpense = $amountSummary['planned']['expenses']['gross_amount'];

        return [
            'actual' => [
                'total_income' => $actualIncome,
                'total_expense' => $actualExpense,
                'profit' => $actualIncome - $actualExpense,
            ],
            'planned' => [
                'total_income' => $plannedIncome,
                'total_expense' => $plannedExpense,
                'profit' => $plannedIncome - $plannedExpense,
            ],
        ];
    }

    /**
     * @return array{
     *     actual: array{
     *         sales: array{net_amount: int, tax_amount: int, gross_amount: int},
     *         expenses: array{net_amount: int, tax_amount: int, gross_amount: int}
     *     },
     *     planned: array{
     *         sales: array{net_amount: int, tax_amount: int, gross_amount: int},
     *         expenses: array{net_amount: int, tax_amount: int, gross_amount: int}
     *     }
     * }
     */
    public function calculateAmountSummary(FiscalYear $fiscalYear): array
    {
        return [
            'actual' => [
                'sales' => $this->totalsBy($fiscalYear, false, 'revenue', 'credit'),
                'expenses' => $this->totalsBy($fiscalYear, false, 'expense', 'debit'),
            ],
            'planned' => [
                'sales' => $this->totalsBy($fiscalYear, true, 'revenue', 'credit'),
                'expenses' => $this->totalsBy($fiscalYear, true, 'expense', 'debit'),
            ],
        ];
    }

    /**
     * @return array{net_amount: int, tax_amount: int, gross_amount: int}
     */
    private function totalsBy(
        FiscalYear $fiscalYear,
        bool $isPlanned,
        string $accountType,
        string $entryType
    ): array {
        $totals = JournalEntry::query()
            ->whereHas('transaction', function (Builder $query) use ($fiscalYear, $isPlanned): void {
                $query
                    ->whereBelongsTo($fiscalYear)
                    ->where('is_active', true)
                    ->where('is_planned', $isPlanned);
            })
            ->whereHas('subAccount.account', fn (Builder $query) => $query->where('type', $accountType))
            ->where('type', $entryType)
            ->selectRaw('COALESCE(SUM(net_amount), 0) as summary_net_amount')
            ->selectRaw('COALESCE(SUM(tax_amount), 0) as summary_tax_amount')
            ->selectRaw('COALESCE(SUM(net_amount + COALESCE(tax_amount, 0)), 0) as summary_gross_amount')
            ->first();

        return [
            'net_amount' => (int) ($totals?->summary_net_amount ?? 0),
            'tax_amount' => (int) ($totals?->summary_tax_amount ?? 0),
            'gross_amount' => (int) ($totals?->summary_gross_amount ?? 0),
        ];
    }
}
