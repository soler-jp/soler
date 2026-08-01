<?php

namespace App\Services;

use App\Concerns\AuthorizesBusinessUnitAccess;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class FiscalYearAccountBreakdownCalculator
{
    use AuthorizesBusinessUnitAccess;

    /**
     * @return array{
     *     asset: array{
     *         total_amount: int,
     *         accounts: array<int, array{
     *             account_id: int,
     *             account_name: string,
     *             total_amount: int,
     *             has_multiple_sub_accounts: bool,
     *             sub_accounts: array<int, array{
     *                 sub_account_id: int,
     *                 sub_account_name: string,
     *                 amount: int
     *             }>
     *         }>
     *     },
     *     liability: array{
     *         total_amount: int,
     *         accounts: array<int, array{
     *             account_id: int,
     *             account_name: string,
     *             total_amount: int,
     *             has_multiple_sub_accounts: bool,
     *             sub_accounts: array<int, array{
     *                 sub_account_id: int,
     *                 sub_account_name: string,
     *                 amount: int
     *             }>
     *         }>
     *     },
     *     equity: array{
     *         total_amount: int,
     *         accounts: array<int, array{
     *             account_id: int,
     *             account_name: string,
     *             total_amount: int,
     *             has_multiple_sub_accounts: bool,
     *             sub_accounts: array<int, array{
     *                 sub_account_id: int,
     *                 sub_account_name: string,
     *                 amount: int
     *             }>
     *         }>
     *     },
     *     revenue: array{
     *         total_amount: int,
     *         accounts: array<int, array{
     *             account_id: int,
     *             account_name: string,
     *             total_amount: int,
     *             has_multiple_sub_accounts: bool,
     *             sub_accounts: array<int, array{
     *                 sub_account_id: int,
     *                 sub_account_name: string,
     *                 amount: int
     *             }>
     *         }>
     *     },
     *     expense: array{
     *         total_amount: int,
     *         accounts: array<int, array{
     *             account_id: int,
     *             account_name: string,
     *             total_amount: int,
     *             has_multiple_sub_accounts: bool,
     *             sub_accounts: array<int, array{
     *                 sub_account_id: int,
     *                 sub_account_name: string,
     *                 amount: int
     *             }>
     *         }>
     *     }
     */
    public function calculate(FiscalYear $fiscalYear, ?User $actor): array
    {
        $this->authorizeBusinessUnitAccess($fiscalYear, $actor);
        $summary = $this->emptySummary();

        foreach ($this->queryRows($fiscalYear) as $row) {
            $accountType = (string) $row->account_type;
            $accountId = (int) $row->account_id;
            $subAccountId = (int) $row->sub_account_id;
            $amount = $this->normalizeAmount((int) $row->raw_amount, $accountType);

            if ($amount === 0) {
                continue;
            }

            $summary[$accountType]['accounts'][$accountId]['account_id'] ??= $accountId;
            $summary[$accountType]['accounts'][$accountId]['account_name'] ??= (string) $row->account_name;
            $summary[$accountType]['accounts'][$accountId]['total_amount'] ??= 0;
            $summary[$accountType]['accounts'][$accountId]['sub_accounts'][$subAccountId] = [
                'sub_account_id' => $subAccountId,
                'sub_account_name' => (string) $row->sub_account_name,
                'amount' => $amount,
            ];

            $summary[$accountType]['accounts'][$accountId]['total_amount'] += $amount;
            $summary[$accountType]['total_amount'] += $amount;
        }

        return collect(Account::TYPES)
            ->mapWithKeys(fn (string $type): array => [$type => $this->finalizeTypeSummary($summary[$type])])
            ->all();
    }

    /**
     * @return array<int, array{
     *     id: int,
     *     date: string,
     *     year_month: string,
     *     description: string,
     *     counterparty_name: string,
     *     counterpart_label: string,
     *     amount: int,
     *     balance: int,
     *     month_stripe: int
     * }>
     */
    public function transactionsForBreakdown(
        FiscalYear $fiscalYear,
        string $accountType,
        int $accountId,
        ?int $subAccountId = null,
        ?User $actor = null,
    ): array {
        $this->authorizeBusinessUnitAccess($fiscalYear, $actor);
        $transactions = Transaction::query()
            ->with([
                'counterparty:id,name',
                'journalEntries.subAccount.account:id,name,type',
            ])
            ->whereBelongsTo($fiscalYear)
            ->where('is_active', true)
            ->where('is_planned', false)
            ->whereHas('journalEntries', function (Builder $query) use ($accountType, $accountId, $subAccountId): void {
                $query
                    ->whereHas('subAccount.account', function (Builder $accountQuery) use ($accountType, $accountId): void {
                        $accountQuery
                            ->where('type', $accountType)
                            ->where('id', $accountId);
                    })
                    ->when($subAccountId !== null, fn (Builder $builder) => $builder->where('sub_account_id', $subAccountId));
            })
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(function (Transaction $transaction) use ($accountType, $accountId, $subAccountId): array {
                $relevantEntries = $transaction->journalEntries->filter(
                    fn (JournalEntry $entry): bool => $entry->subAccount->account->type === $accountType
                        && $entry->subAccount->account->id === $accountId
                        && ($subAccountId === null || $entry->sub_account_id === $subAccountId)
                );

                $counterpartEntries = $transaction->journalEntries->reject(
                    fn (JournalEntry $entry): bool => $relevantEntries->contains($entry)
                );

                $rawAmount = $relevantEntries->sum(function (JournalEntry $entry): int {
                    return $entry->type === JournalEntry::TYPE_DEBIT
                        ? $entry->gross_amount
                        : -$entry->gross_amount;
                });

                return [
                    'id' => $transaction->id,
                    'date' => $transaction->date->format('Y-m-d'),
                    'year_month' => $transaction->date->format('Y-m'),
                    'description' => trim((string) ($transaction->description ?? '')),
                    'counterparty_name' => $transaction->counterparty?->name ?? '',
                    'counterpart_label' => $this->entryLabels($counterpartEntries),
                    'amount' => $this->normalizeAmount($rawAmount, $accountType),
                ];
            })
            ->filter(fn (array $transaction): bool => $transaction['amount'] !== 0)
            ->values()
            ->all();

        $balance = 0;
        $monthStripe = -1;
        $currentYearMonth = null;

        return collect($transactions)
            ->map(function (array $transaction) use (&$balance, &$monthStripe, &$currentYearMonth): array {
                if ($currentYearMonth !== $transaction['year_month']) {
                    $currentYearMonth = $transaction['year_month'];
                    $monthStripe++;
                }

                $balance += $transaction['amount'];

                return [
                    ...$transaction,
                    'balance' => $balance,
                    'month_stripe' => $monthStripe % 2,
                ];
            })
            ->all();
    }

    /**
     * @return Collection<int, object{
     *     account_id: int,
     *     account_name: string,
     *     account_type: string,
     *     sub_account_id: int,
     *     sub_account_name: string,
     *     raw_amount: string|int
     * }>
     */
    protected function queryRows(FiscalYear $fiscalYear): Collection
    {
        return JournalEntry::query()
            ->join('transactions', 'journal_entries.transaction_id', '=', 'transactions.id')
            ->join('sub_accounts', 'journal_entries.sub_account_id', '=', 'sub_accounts.id')
            ->join('accounts', 'sub_accounts.account_id', '=', 'accounts.id')
            ->where('transactions.fiscal_year_id', $fiscalYear->id)
            ->where('transactions.is_active', true)
            ->where('transactions.is_planned', false)
            ->whereIn('accounts.type', Account::TYPES)
            ->selectRaw('accounts.id as account_id')
            ->selectRaw('accounts.name as account_name')
            ->selectRaw('accounts.type as account_type')
            ->selectRaw('sub_accounts.id as sub_account_id')
            ->selectRaw('sub_accounts.name as sub_account_name')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN journal_entries.type = ? THEN (journal_entries.net_amount + COALESCE(journal_entries.tax_amount, 0)) ELSE -(journal_entries.net_amount + COALESCE(journal_entries.tax_amount, 0)) END), 0) as raw_amount',
                [JournalEntry::TYPE_DEBIT]
            )
            ->groupBy(
                'accounts.id',
                'accounts.name',
                'accounts.type',
                'sub_accounts.id',
                'sub_accounts.name'
            )
            ->orderBy('accounts.type')
            ->orderBy('accounts.id')
            ->orderBy('sub_accounts.id')
            ->get();
    }

    /**
     * @param  array{
     *     total_amount: int,
     *     accounts: array<int, array{
     *         account_id: int,
     *         account_name: string,
     *         total_amount: int,
     *         sub_accounts: array<int, array{
     *             sub_account_id: int,
     *             sub_account_name: string,
     *             amount: int
     *         }>
     *     }>
     * }  $summary
     * @return array{
     *     total_amount: int,
     *     accounts: array<int, array{
     *         account_id: int,
     *         account_name: string,
     *         total_amount: int,
     *         has_multiple_sub_accounts: bool,
     *         sub_accounts: array<int, array{
     *             sub_account_id: int,
     *             sub_account_name: string,
     *             amount: int
     *         }>
     *     }>
     * }
     */
    protected function finalizeTypeSummary(array $summary): array
    {
        $accounts = [];

        foreach ($summary['accounts'] as $account) {
            $subAccounts = array_values($account['sub_accounts']);

            usort($subAccounts, static function (array $left, array $right): int {
                $nameComparison = $left['sub_account_name'] <=> $right['sub_account_name'];

                if ($nameComparison !== 0) {
                    return $nameComparison;
                }

                return $left['sub_account_id'] <=> $right['sub_account_id'];
            });

            $account['has_multiple_sub_accounts'] = count($subAccounts) > 1;
            $account['sub_accounts'] = $subAccounts;
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
            'total_amount' => $summary['total_amount'],
            'accounts' => $accounts,
        ];
    }

    /**
     * @return array{
     *     asset: array{
     *         total_amount: int,
     *         accounts: array<int, array{
     *             account_id: int,
     *             account_name: string,
     *             total_amount: int,
     *             sub_accounts: array<int, array{
     *                 sub_account_id: int,
     *                 sub_account_name: string,
     *                 amount: int
     *             }>
     *         }>
     *     },
     *     liability: array{
     *         total_amount: int,
     *         accounts: array<int, array{
     *             account_id: int,
     *             account_name: string,
     *             total_amount: int,
     *             sub_accounts: array<int, array{
     *                 sub_account_id: int,
     *                 sub_account_name: string,
     *                 amount: int
     *             }>
     *         }>
     *     },
     *     equity: array{
     *         total_amount: int,
     *         accounts: array<int, array{
     *             account_id: int,
     *             account_name: string,
     *             total_amount: int,
     *             sub_accounts: array<int, array{
     *                 sub_account_id: int,
     *                 sub_account_name: string,
     *                 amount: int
     *             }>
     *         }>
     *     },
     *     revenue: array{
     *         total_amount: int,
     *         accounts: array<int, array{
     *             account_id: int,
     *             account_name: string,
     *             total_amount: int,
     *             sub_accounts: array<int, array{
     *                 sub_account_id: int,
     *                 sub_account_name: string,
     *                 amount: int
     *             }>
     *         }>
     *     },
     *     expense: array{
     *         total_amount: int,
     *         accounts: array<int, array{
     *             account_id: int,
     *             account_name: string,
     *             total_amount: int,
     *             sub_accounts: array<int, array{
     *                 sub_account_id: int,
     *                 sub_account_name: string,
     *                 amount: int
     *             }>
     *         }>
     *     }
     * }
     */
    protected function emptySummary(): array
    {
        return collect(Account::TYPES)
            ->mapWithKeys(fn (string $type): array => [$type => [
                'total_amount' => 0,
                'accounts' => [],
            ]])
            ->all();
    }

    protected function normalizeAmount(int $rawAmount, string $accountType): int
    {
        return match ($accountType) {
            Account::TYPE_LIABILITY,
            Account::TYPE_EQUITY,
            Account::TYPE_REVENUE => -$rawAmount,
            default => $rawAmount,
        };
    }

    /**
     * @param  Collection<int, JournalEntry>  $entries
     */
    protected function entryLabels(Collection $entries): string
    {
        $labels = $entries
            ->map(function (JournalEntry $entry): string {
                $accountName = $entry->subAccount->account->name;
                $subAccountName = $entry->subAccount->name;

                if ($accountName === $subAccountName) {
                    return $subAccountName;
                }

                return "{$accountName} / {$subAccountName}";
            })
            ->unique()
            ->values();

        if ($labels->isEmpty()) {
            return '-';
        }

        return $labels->implode(' / ');
    }
}
