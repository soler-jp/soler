<?php

namespace App\Services;

use App\Models\Counterparty;
use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Builder;

class CounterpartySummaryCalculator
{
    /**
     * @return array{
     *     all: array{
     *         expense: array{
     *             accounts: array<int, array{account_id: int, account_name: string, amount: int}>,
     *             total_amount: int
     *         },
     *         income: array{
     *             accounts: array<int, array{account_id: int, account_name: string, amount: int}>,
     *             total_amount: int
     *         }
     *     },
     *     fiscal_years: array<int, array{
     *         expense: array{
     *             accounts: array<int, array{account_id: int, account_name: string, amount: int}>,
     *             total_amount: int
     *         },
     *         income: array{
     *             accounts: array<int, array{account_id: int, account_name: string, amount: int}>,
     *             total_amount: int
     *         }
     *     }>
     * }
     */
    public function calculate(Counterparty $counterparty): array
    {
        $expenseSummary = $this->summarizeByFiscalYearAndAccount(
            $counterparty,
            JournalEntry::TYPE_DEBIT,
            'expense'
        );

        $incomeSummary = $this->summarizeByFiscalYearAndAccount(
            $counterparty,
            JournalEntry::TYPE_CREDIT,
            'revenue'
        );

        return [
            'all' => [
                'expense' => $expenseSummary['total'],
                'income' => $incomeSummary['total'],
            ],
            'fiscal_years' => $this->mergeFiscalYearSummaries(
                $expenseSummary['years'],
                $incomeSummary['years']
            ),
        ];
    }

    /**
     * @return array{
     *     expense: array{
     *         accounts: array<int, array{account_id: int, account_name: string, amount: int}>,
     *         total_amount: int
     *     },
     *     income: array{
     *         accounts: array<int, array{account_id: int, account_name: string, amount: int}>,
     *         total_amount: int
     *     }
     * }
     */
    public function calculateForFiscalYear(Counterparty $counterparty, int $fiscalYear): array
    {
        $expenseSummary = $this->summarizeByFiscalYearAndAccount(
            $counterparty,
            JournalEntry::TYPE_DEBIT,
            'expense',
            $fiscalYear
        );

        $incomeSummary = $this->summarizeByFiscalYearAndAccount(
            $counterparty,
            JournalEntry::TYPE_CREDIT,
            'revenue',
            $fiscalYear
        );

        return [
            'expense' => $expenseSummary['total'],
            'income' => $incomeSummary['total'],
        ];
    }

    /**
     * @param  array<int, array{accounts: array<int, array{account_id: int, account_name: string, amount: int}>, total_amount: int}>  $expenseYears
     * @param  array<int, array{accounts: array<int, array{account_id: int, account_name: string, amount: int}>, total_amount: int}>  $incomeYears
     * @return array<int, array{
     *     expense: array{
     *         accounts: array<int, array{account_id: int, account_name: string, amount: int}>,
     *         total_amount: int
     *     },
     *     income: array{
     *         accounts: array<int, array{account_id: int, account_name: string, amount: int}>,
     *         total_amount: int
     *     }
     * }>
     */
    protected function mergeFiscalYearSummaries(array $expenseYears, array $incomeYears): array
    {
        $fiscalYears = array_values(array_unique(array_merge(
            array_keys($expenseYears),
            array_keys($incomeYears)
        )));

        sort($fiscalYears);

        $summaries = [];

        foreach ($fiscalYears as $fiscalYear) {
            $summaries[$fiscalYear] = [
                'expense' => $expenseYears[$fiscalYear] ?? $this->emptySideSummary(),
                'income' => $incomeYears[$fiscalYear] ?? $this->emptySideSummary(),
            ];
        }

        return $summaries;
    }

    protected function baseQuery(Counterparty $counterparty): Builder
    {
        return JournalEntry::query()
            ->join('transactions', 'journal_entries.transaction_id', '=', 'transactions.id')
            ->where('transactions.counterparty_id', $counterparty->id)
            ->where('transactions.is_active', true);
    }

    /**
     * @return array{
     *     years: array<int, array{
     *         accounts: array<int, array{account_id: int, account_name: string, amount: int}>,
     *         total_amount: int
     *     }>,
     *     total: array{
     *         accounts: array<int, array{account_id: int, account_name: string, amount: int}>,
     *         total_amount: int
     *     }
     * }
     */
    protected function summarizeByFiscalYearAndAccount(
        Counterparty $counterparty,
        string $entryType,
        string $accountType,
        ?int $fiscalYear = null
    ): array {
        $query = $this->baseQuery($counterparty)
            ->join('fiscal_years', 'transactions.fiscal_year_id', '=', 'fiscal_years.id')
            ->join('sub_accounts', 'journal_entries.sub_account_id', '=', 'sub_accounts.id')
            ->join('accounts', 'sub_accounts.account_id', '=', 'accounts.id')
            ->where('journal_entries.type', $entryType)
            ->where('accounts.type', $accountType)
            ->selectRaw('fiscal_years.year as fiscal_year')
            ->selectRaw('accounts.id as account_id')
            ->selectRaw('accounts.name as account_name')
            ->selectRaw('COALESCE(SUM(journal_entries.net_amount + COALESCE(journal_entries.tax_amount, 0)), 0) as amount')
            ->groupBy('fiscal_years.year', 'accounts.id', 'accounts.name')
            ->orderBy('fiscal_years.year')
            ->orderBy('accounts.name');

        if ($fiscalYear !== null) {
            $query->where('fiscal_years.year', $fiscalYear);
        }

        $rows = $query->get();

        $years = [];
        $totalAccounts = [];
        $totalAmount = 0;

        foreach ($rows as $row) {
            $year = (int) $row->fiscal_year;
            $accountId = (int) $row->account_id;
            $amount = (int) $row->amount;

            $years[$year]['accounts'][] = [
                'account_id' => $accountId,
                'account_name' => (string) $row->account_name,
                'amount' => $amount,
            ];

            $years[$year]['total_amount'] = ($years[$year]['total_amount'] ?? 0) + $amount;
            $totalAmount += $amount;

            if (! isset($totalAccounts[$accountId])) {
                $totalAccounts[$accountId] = [
                    'account_id' => $accountId,
                    'account_name' => (string) $row->account_name,
                    'amount' => 0,
                ];
            }

            $totalAccounts[$accountId]['amount'] += $amount;
        }

        foreach ($years as $year => $summary) {
            $years[$year]['accounts'] = array_values($summary['accounts']);
        }

        $total = [
            'accounts' => array_values($totalAccounts),
            'total_amount' => $totalAmount,
        ];

        usort($total['accounts'], static function (array $left, array $right): int {
            $nameComparison = $left['account_name'] <=> $right['account_name'];

            if ($nameComparison !== 0) {
                return $nameComparison;
            }

            return $left['account_id'] <=> $right['account_id'];
        });

        return [
            'years' => $years,
            'total' => $total,
        ];
    }

    /**
     * @return array{accounts: array<int, array{account_id: int, account_name: string, amount: int}>, total_amount: int}
     */
    protected function emptySideSummary(): array
    {
        return [
            'accounts' => [],
            'total_amount' => 0,
        ];
    }
}
