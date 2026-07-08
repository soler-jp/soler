<?php

namespace App\Services;

use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use Illuminate\Support\Collection;

class FiscalYearBalanceCalculator
{
    /**
     * @return array{
     *     asset: array{
     *         total_balance: int,
     *         accounts: array<int, array{
     *             account_id: int,
     *             account_name: string,
     *             balance: int,
     *             sub_accounts: array<int, array{
     *                 sub_account_id: int,
     *                 sub_account_name: string,
     *                 balance: int
     *             }>
     *         }>
     *     },
     *     liability: array{
     *         total_balance: int,
     *         accounts: array<int, array{
     *             account_id: int,
     *             account_name: string,
     *             balance: int,
     *             sub_accounts: array<int, array{
     *                 sub_account_id: int,
     *                 sub_account_name: string,
     *                 balance: int
     *             }>
     *         }>
     *     },
     *     equity: array{
     *         total_balance: int,
     *         accounts: array<int, array{
     *             account_id: int,
     *             account_name: string,
     *             balance: int,
     *             sub_accounts: array<int, array{
     *                 sub_account_id: int,
     *                 sub_account_name: string,
     *                 balance: int
     *             }>
     *         }>
     *     }
     * }
     */
    public function calculate(FiscalYear $fiscalYear): array
    {
        return $this->buildSummary($fiscalYear, false);
    }

    /**
     * @return array{
     *     asset: array{
     *         total_balance: int,
     *         accounts: array<int, array{
     *             account_id: int,
     *             account_name: string,
     *             balance: int,
     *             sub_accounts: array<int, array{
     *                 sub_account_id: int,
     *                 sub_account_name: string,
     *                 balance: int
     *             }>
     *         }>
     *     },
     *     liability: array{
     *         total_balance: int,
     *         accounts: array<int, array{
     *             account_id: int,
     *             account_name: string,
     *             balance: int,
     *             sub_accounts: array<int, array{
     *                 sub_account_id: int,
     *                 sub_account_name: string,
     *                 balance: int
     *             }>
     *         }>
     *     },
     *     equity: array{
     *         total_balance: int,
     *         accounts: array<int, array{
     *             account_id: int,
     *             account_name: string,
     *             balance: int,
     *             sub_accounts: array<int, array{
     *                 sub_account_id: int,
     *                 sub_account_name: string,
     *                 balance: int
     *             }>
     *         }>
     *     }
     * }
     */
    public function calculateOpening(FiscalYear $fiscalYear): array
    {
        return $this->buildSummary($fiscalYear, true);
    }

    /**
     * @return Collection<int, object{account_id: int, account_name: string, account_type: string, sub_account_id: int, sub_account_name: string, raw_balance: string|int}>
     */
    protected function queryRows(FiscalYear $fiscalYear, bool $openingOnly = false): Collection
    {
        $query = JournalEntry::query()
            ->join('transactions', 'journal_entries.transaction_id', '=', 'transactions.id')
            ->join('sub_accounts', 'journal_entries.sub_account_id', '=', 'sub_accounts.id')
            ->join('accounts', 'sub_accounts.account_id', '=', 'accounts.id')
            ->where('transactions.fiscal_year_id', $fiscalYear->id)
            ->where('transactions.is_active', true)
            ->where('transactions.is_planned', false)
            ->whereIn('accounts.type', [
                Account::TYPE_ASSET,
                Account::TYPE_LIABILITY,
                Account::TYPE_EQUITY,
            ]);

        if ($openingOnly) {
            $query->where('transactions.is_opening_entry', true);
        }

        return $query
            ->selectRaw('accounts.id as account_id')
            ->selectRaw('accounts.name as account_name')
            ->selectRaw('accounts.type as account_type')
            ->selectRaw('sub_accounts.id as sub_account_id')
            ->selectRaw('sub_accounts.name as sub_account_name')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN journal_entries.type = ? THEN (journal_entries.net_amount + COALESCE(journal_entries.tax_amount, 0)) ELSE -(journal_entries.net_amount + COALESCE(journal_entries.tax_amount, 0)) END), 0) as raw_balance',
                [JournalEntry::TYPE_DEBIT]
            )
            ->groupBy(
                'accounts.id',
                'accounts.name',
                'accounts.type',
                'sub_accounts.id',
                'sub_accounts.name'
            )
            ->orderBy('sub_accounts.id')
            ->get();
    }

    /**
     * @return array{
     *     asset: array{
     *         total_balance: int,
     *         accounts: array<int, array{
     *             account_id: int,
     *             account_name: string,
     *             balance: int,
     *             sub_accounts: array<int, array{
     *                 sub_account_id: int,
     *                 sub_account_name: string,
     *                 balance: int
     *             }>
     *         }>
     *     },
     *     liability: array{
     *         total_balance: int,
     *         accounts: array<int, array{
     *             account_id: int,
     *             account_name: string,
     *             balance: int,
     *             sub_accounts: array<int, array{
     *                 sub_account_id: int,
     *                 sub_account_name: string,
     *                 balance: int
     *             }>
     *         }>
     *     },
     *     equity: array{
     *         total_balance: int,
     *         accounts: array<int, array{
     *             account_id: int,
     *             account_name: string,
     *             balance: int,
     *             sub_accounts: array<int, array{
     *                 sub_account_id: int,
     *                 sub_account_name: string,
     *                 balance: int
     *             }>
     *         }>
     *     }
     * }
     */
    protected function buildSummary(FiscalYear $fiscalYear, bool $openingOnly): array
    {
        $summary = $this->emptySummary();
        $rows = $this->queryRows($fiscalYear, $openingOnly);

        foreach ($rows as $row) {
            $type = (string) $row->account_type;
            $accountId = (int) $row->account_id;
            $subAccountId = (int) $row->sub_account_id;
            $balance = $this->normalizeBalance(
                (int) $row->raw_balance,
                $type
            );

            $summary[$type]['accounts'][$accountId]['account_id'] ??= $accountId;
            $summary[$type]['accounts'][$accountId]['account_name'] ??= (string) $row->account_name;
            $summary[$type]['accounts'][$accountId]['balance'] ??= 0;

            $summary[$type]['accounts'][$accountId]['sub_accounts'][$subAccountId] = [
                'sub_account_id' => $subAccountId,
                'sub_account_name' => (string) $row->sub_account_name,
                'balance' => $balance,
            ];

            $summary[$type]['accounts'][$accountId]['balance'] += $balance;
            $summary[$type]['total_balance'] += $balance;
        }

        return [
            Account::TYPE_ASSET => $this->finalizeTypeSummary($summary[Account::TYPE_ASSET]),
            Account::TYPE_LIABILITY => $this->finalizeTypeSummary($summary[Account::TYPE_LIABILITY]),
            Account::TYPE_EQUITY => $this->finalizeTypeSummary($summary[Account::TYPE_EQUITY]),
        ];
    }

    /**
     * @param  array{total_balance: int, accounts: array<int, array{account_id: int, account_name: string, balance: int, sub_accounts: array<int, array{sub_account_id: int, sub_account_name: string, balance: int}>}>}  $summary
     * @return array{total_balance: int, accounts: array<int, array{account_id: int, account_name: string, balance: int, sub_accounts: array<int, array{sub_account_id: int, sub_account_name: string, balance: int}>}>}
     */
    protected function finalizeTypeSummary(array $summary): array
    {
        $accounts = [];

        foreach ($summary['accounts'] as $account) {
            $account['sub_accounts'] = array_values($account['sub_accounts']);
            $accounts[] = $account;
        }

        usort($accounts, static function (array $left, array $right): int {
            $nameComparison = $left['account_name'] <=> $right['account_name'];

            if ($nameComparison !== 0) {
                return $nameComparison;
            }

            return $left['account_id'] <=> $right['account_id'];
        });

        return [
            'total_balance' => $summary['total_balance'],
            'accounts' => $accounts,
        ];
    }

    /**
     * @return array{
     *     asset: array{total_balance: int, accounts: array<int, array{account_id: int, account_name: string, balance: int, sub_accounts: array<int, array{sub_account_id: int, sub_account_name: string, balance: int}>}>},
     *     liability: array{total_balance: int, accounts: array<int, array{account_id: int, account_name: string, balance: int, sub_accounts: array<int, array{sub_account_id: int, sub_account_name: string, balance: int}>}>},
     *     equity: array{total_balance: int, accounts: array<int, array{account_id: int, account_name: string, balance: int, sub_accounts: array<int, array{sub_account_id: int, sub_account_name: string, balance: int}>}>}
     * }
     */
    protected function emptySummary(): array
    {
        return [
            Account::TYPE_ASSET => ['total_balance' => 0, 'accounts' => []],
            Account::TYPE_LIABILITY => ['total_balance' => 0, 'accounts' => []],
            Account::TYPE_EQUITY => ['total_balance' => 0, 'accounts' => []],
        ];
    }

    protected function normalizeBalance(int $rawBalance, string $accountType): int
    {
        return match ($accountType) {
            Account::TYPE_ASSET => $rawBalance,
            Account::TYPE_LIABILITY, Account::TYPE_EQUITY => -$rawBalance,
            default => $rawBalance,
        };
    }
}
