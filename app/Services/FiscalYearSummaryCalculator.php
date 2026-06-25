<?php

namespace App\Services;

use App\Models\FiscalYear;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
        $actualIncome = $this->sumBy($fiscalYear, false, 'revenue', 'credit');
        $actualExpense = $this->sumBy($fiscalYear, false, 'expense', 'debit');
        $plannedIncome = $this->sumBy($fiscalYear, true, 'revenue', 'credit');
        $plannedExpense = $this->sumBy($fiscalYear, true, 'expense', 'debit');

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

    private function sumBy(
        FiscalYear $fiscalYear,
        bool $isPlanned,
        string $accountType,
        string $entryType
    ): int {
        return (int) $fiscalYear->journalEntries()
            ->whereHas('transaction', fn (Builder $query) => $query->where('is_planned', $isPlanned))
            ->whereHas('subAccount.account', fn (Builder $query) => $query->where('type', $accountType))
            ->where('type', $entryType)
            ->sum(DB::raw('net_amount + COALESCE(tax_amount, 0)'));
    }
}
