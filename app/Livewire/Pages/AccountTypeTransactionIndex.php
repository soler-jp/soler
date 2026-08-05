<?php

namespace App\Livewire\Pages;

use App\Models\Account;
use InvalidArgumentException;
use Livewire\Attributes\On;
use Livewire\Component;

class AccountTypeTransactionIndex extends Component
{
    private const PURCHASE_ACCOUNT_NAMES = ['仕入金額'];

    public string $kind;

    public string $title;

    public string $description;

    public string $accountType;

    public string $variant;

    public bool $showTaxTypeColumn = true;

    public bool $groupByMonth = true;

    /**
     * @var array<int, string>
     */
    public array $accountNames = [];

    /**
     * @var array<int, string>
     */
    public array $excludedAccountNames = [];

    /**
     * @var array<int, string>
     */
    public array $availableAccountNames = [];

    /**
     * @var array<string, int>
     */
    public array $availableAccountCounts = [];

    /**
     * @var array<int, array{
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
    public array $transactions = [];

    /**
     * @var array<int, array{
     *     year_month: string,
     *     label: string,
     *     amount: int,
     *     transactions: array<int, array{
     *         id: int,
     *         date: string,
     *         amount: int,
     *         description: string,
     *         allocation_note: string,
     *         debit_label: string,
     *         credit_label: string,
     *         tax_type_label: string,
     *         counterparty_name: string
     *     }>
     * }>
     */
    public array $months = [];

    public function mount(string $kind): void
    {
        $config = $this->configFor($kind);

        $this->kind = $kind;
        $this->title = $config['title'];
        $this->description = $config['description'];
        $this->accountType = $config['account_type'];
        $this->variant = $config['variant'];
        $this->groupByMonth = $config['group_by_month'];
        $this->accountNames = $config['account_names'];
        $this->excludedAccountNames = $config['excluded_account_names'];

        $fiscalYear = auth()->user()->selectedBusinessUnit->currentFiscalYear;
        $this->showTaxTypeColumn = (bool) $fiscalYear?->is_taxable;
        $this->availableAccountCounts = $this->resolveAvailableAccountCounts();
        $this->availableAccountNames = array_keys($this->availableAccountCounts);

        if (! $this->groupByMonth) {
            $this->accountNames = $this->availableAccountNames;
        }

        $this->reloadTransactions();
    }

    /**
     * @return array{
     *     title: string,
     *     description: string,
     *     account_type: string,
     *     variant: string,
     *     group_by_month: bool,
     *     account_names: array<int, string>,
     *     excluded_account_names: array<int, string>
     * }
     */
    public function configFor(string $kind): array
    {
        return match ($kind) {
            'revenue' => [
                'title' => '売上一覧',
                'description' => '売上を月ごとにまとめて確認できます。',
                'account_type' => Account::TYPE_REVENUE,
                'variant' => Account::TYPE_REVENUE,
                'group_by_month' => true,
                'account_names' => [],
                'excluded_account_names' => [],
            ],
            'expense' => [
                'title' => '経費の月別一覧',
                'description' => '経費を月ごとにまとめて確認できます。',
                'account_type' => Account::TYPE_EXPENSE,
                'variant' => Account::TYPE_EXPENSE,
                'group_by_month' => true,
                'account_names' => [],
                'excluded_account_names' => self::PURCHASE_ACCOUNT_NAMES,
            ],
            'expense_type' => [
                'title' => '経費の種類別一覧',
                'description' => '表示する経費の種類を選んで、日付順に確認できます。',
                'account_type' => Account::TYPE_EXPENSE,
                'variant' => Account::TYPE_EXPENSE,
                'group_by_month' => false,
                'account_names' => [],
                'excluded_account_names' => self::PURCHASE_ACCOUNT_NAMES,
            ],
            'purchase' => [
                'title' => '仕入れ一覧',
                'description' => '仕入金額の取引だけを月ごとに確認できます。',
                'account_type' => Account::TYPE_EXPENSE,
                'variant' => Account::TYPE_EXPENSE,
                'group_by_month' => true,
                'account_names' => ['仕入金額'],
                'excluded_account_names' => [],
            ],
            default => throw new InvalidArgumentException('Unsupported kind.'),
        };
    }

    /**
     * @return array<string, string>
     */
    public function palette(): array
    {
        return match ($this->variant) {
            Account::TYPE_EXPENSE => [
                'panel' => 'border-red-200 bg-red-50/80',
                'title' => 'text-red-700',
                'amount' => 'text-red-600',
                'tableWrap' => 'border-red-100',
                'tableHead' => 'bg-white text-gray-600 border-b border-gray-200',
                'monthCard' => 'border-red-100 bg-white',
                'monthHeader' => 'border-red-100 bg-red-50 text-red-900',
            ],
            default => [
                'panel' => 'border-blue-200 bg-blue-50/80',
                'title' => 'text-blue-700',
                'amount' => 'text-blue-600',
                'tableWrap' => 'border-blue-100',
                'tableHead' => 'bg-white text-gray-600 border-b border-gray-200',
                'monthCard' => 'border-blue-100 bg-white',
                'monthHeader' => 'border-blue-100 bg-blue-50 text-blue-900',
            ],
        };
    }

    public function emptyStateColspan(): int
    {
        return ($this->accountType === Account::TYPE_EXPENSE ? 4 : 3)
            + ($this->showTaxTypeColumn ? 1 : 0)
            + 2;
    }

    #[On('dashboard-transaction-created')]
    public function onTransactionCreated(): void
    {
        $this->availableAccountCounts = $this->resolveAvailableAccountCounts();
        $this->availableAccountNames = array_keys($this->availableAccountCounts);

        if (! $this->groupByMonth) {
            $this->accountNames = array_values(array_intersect($this->accountNames, $this->availableAccountNames));

            if ($this->accountNames === []) {
                $this->accountNames = $this->availableAccountNames;
            }
        }

        $this->reloadTransactions();
    }

    public function updatedAccountNames(): void
    {
        if ($this->groupByMonth) {
            return;
        }

        $this->reloadTransactions();
    }

    public function selectedTotalAmount(): int
    {
        return collect($this->transactions)->sum('amount');
    }

    public function selectAllAccountNames(): void
    {
        if ($this->groupByMonth) {
            return;
        }

        $this->accountNames = $this->availableAccountNames;
        $this->reloadTransactions();
    }

    public function clearAccountNames(): void
    {
        if ($this->groupByMonth) {
            return;
        }

        $this->accountNames = [];
        $this->reloadTransactions();
    }

    private function reloadTransactions(): void
    {
        $fiscalYear = auth()->user()->selectedBusinessUnit->currentFiscalYear;

        if ($this->groupByMonth) {
            $this->months = $fiscalYear?->monthlyAccountTypeTransactionGroups(
                $this->accountType,
                $this->accountNames,
                $this->excludedAccountNames,
            ) ?? [];
            $this->transactions = [];

            return;
        }

        if ($this->accountNames === []) {
            $this->transactions = [];
            $this->months = [];

            return;
        }

        $this->transactions = $fiscalYear?->accountTypeTransactions(
            $this->accountType,
            $this->accountNames,
            $this->excludedAccountNames,
        ) ?? [];
        $this->months = [];
    }

    /**
     * @return array<int, string>
     */
    private function resolveAvailableAccountNames(): array
    {
        return array_keys($this->resolveAvailableAccountCounts());
    }

    /**
     * @return array<string, int>
     */
    private function resolveAvailableAccountCounts(): array
    {
        if ($this->accountType !== Account::TYPE_EXPENSE || $this->groupByMonth) {
            return [];
        }

        $fiscalYear = auth()->user()->selectedBusinessUnit->currentFiscalYear;
        $candidateAccountNames = auth()->user()
            ->selectedBusinessUnit
            ->accounts()
            ->where('type', Account::TYPE_EXPENSE)
            ->whereNotIn('name', $this->excludedAccountNames)
            ->orderBy('id')
            ->pluck('name')
            ->all();

        return $fiscalYear?->transactionCountByAccountNames(
            $this->accountType,
            $candidateAccountNames,
        ) ?? [];
    }

    public function render()
    {
        return view('livewire.pages.account-type-transaction-index');
    }
}
