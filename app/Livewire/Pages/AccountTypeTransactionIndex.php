<?php

namespace App\Livewire\Pages;

use App\Models\Account;
use App\Models\Transaction;
use InvalidArgumentException;
use Livewire\Attributes\On;
use Livewire\Component;

class AccountTypeTransactionIndex extends Component
{
    /**
     * 経費（月別一覧・種類別一覧）から除外する売上原価の損益科目。青色申告決算書と同じ扱い。
     */
    private const COST_OF_GOODS_SOLD_ACCOUNT_NAMES = ['期首商品（棚卸高）', '仕入金額', '期末商品（棚卸高）'];

    public const YEARLY_PERIOD = 'yearly';

    public const KIND_OTHER = 'other';

    public string $kind;

    public string $title;

    public string $description;

    public string $accountType = '';

    public string $variant;

    public bool $showTaxTypeColumn = true;

    public bool $groupByMonth = true;

    public ?int $editingTransactionId = null;

    public int $fiscalYearYear;

    public string $selectedPeriod = self::YEARLY_PERIOD;

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
     *     transaction_count: int
     * }>
     */
    public array $months = [];

    /**
     * @var array<string, array<int, array{
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
     *     counterparty_name: string,
     *     is_single_pair: bool
     * }>
     */
    public array $monthTransactions = [];

    public function mount(string $kind): void
    {
        $config = $this->configFor($kind);

        $this->kind = $kind;
        $this->title = $config['title'];
        $this->description = $config['description'];
        $this->accountType = $config['account_type'] ?? '';
        $this->variant = $config['variant'];
        $this->groupByMonth = $config['group_by_month'];
        $this->accountNames = $config['account_names'];
        $this->excludedAccountNames = $config['excluded_account_names'];

        $fiscalYear = auth()->user()->selectedBusinessUnit->currentFiscalYear;
        $this->fiscalYearYear = $fiscalYear?->year ?? now()->year;
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
     *     account_type: ?string,
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
                'excluded_account_names' => self::COST_OF_GOODS_SOLD_ACCOUNT_NAMES,
            ],
            'expense_type' => [
                'title' => '経費の種類別一覧',
                'description' => '表示する経費の種類を選んで、日付順に確認できます。',
                'account_type' => Account::TYPE_EXPENSE,
                'variant' => Account::TYPE_EXPENSE,
                'group_by_month' => false,
                'account_names' => [],
                'excluded_account_names' => self::COST_OF_GOODS_SOLD_ACCOUNT_NAMES,
            ],
            'purchase' => [
                'title' => '仕入・棚卸一覧',
                'description' => '仕入金額と、期首・期末棚卸の決算整理仕訳をまとめて月ごとに確認できます。',
                'account_type' => Account::TYPE_EXPENSE,
                'variant' => Account::TYPE_EXPENSE,
                'group_by_month' => true,
                'account_names' => self::COST_OF_GOODS_SOLD_ACCOUNT_NAMES,
                'excluded_account_names' => [],
            ],
            self::KIND_OTHER => [
                'title' => 'お金の移動の登録・確認',
                'description' => '売上・経費・仕入以外の、資産・負債・資本のあいだのお金の移動を月ごとに確認できます。',
                'account_type' => null,
                'variant' => self::KIND_OTHER,
                'group_by_month' => true,
                'account_names' => [],
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
                'amount' => 'text-content',
                'tableWrap' => 'border-line rounded-card',
                'tableHead' => 'bg-surface-muted text-content-muted border-b border-line',
                'tabActive' => 'border-line border-b-surface border-t-brand bg-surface text-content -mb-px shadow-card',
                'tabInactive' => 'border-l border-y border-r-0 border-line bg-surface-muted text-content-muted hover:bg-surface hover:text-content last:border-r last:border-line',
                'tabCountActive' => 'bg-brand text-content-onbrand',
                'tabCountInactive' => 'bg-surface text-content-muted',
            ],
            default => [
                'amount' => 'text-content',
                'tableWrap' => 'border-line rounded-card',
                'tableHead' => 'bg-surface-muted text-content-muted border-b border-line',
                'tabActive' => 'border-line border-b-surface border-t-brand bg-surface text-content -mb-px shadow-card',
                'tabInactive' => 'border-l border-y border-r-0 border-line bg-surface-muted text-content-muted hover:bg-surface hover:text-content last:border-r last:border-line',
                'tabCountActive' => 'bg-brand text-content-onbrand',
                'tabCountInactive' => 'bg-surface text-content-muted',
            ],
        };
    }

    public function debitHeader(): string
    {
        return $this->kind === self::KIND_OTHER ? '入金先' : '種類';
    }

    public function creditHeader(): string
    {
        return $this->kind === self::KIND_OTHER ? '出金元' : '支払い元';
    }

    public function emptyStateColspan(): int
    {
        return ($this->accountType === Account::TYPE_REVENUE ? 3 : 4)
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

    /**
     * @return array<int, array{year_month: string, label: string, amount: int, transaction_count: int}>
     */
    public function monthTabs(): array
    {
        return $this->months;
    }

    public function selectPeriod(string $period): void
    {
        if (! $this->groupByMonth || ! $this->isValidPeriod($period)) {
            return;
        }

        $this->selectedPeriod = $period;
        $this->editingTransactionId = null;
    }

    /**
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
     *     counterparty_name: string,
     *     is_single_pair: bool
     * }>
     */
    public function visibleTransactions(): array
    {
        if (! $this->groupByMonth || $this->selectedPeriod === self::YEARLY_PERIOD) {
            return $this->transactions;
        }

        return $this->monthTransactions[$this->selectedPeriod] ?? [];
    }

    public function visibleTransactionCount(): int
    {
        return count($this->visibleTransactions());
    }

    public function visibleTotalAmount(): int
    {
        return (int) collect($this->visibleTransactions())->sum('amount');
    }

    public function activePeriodLabel(): string
    {
        if (! $this->groupByMonth || $this->selectedPeriod === self::YEARLY_PERIOD) {
            return __('transactions.index.tabs.yearly');
        }

        return collect($this->months)
            ->firstWhere('year_month', $this->selectedPeriod)['label'] ?? __('transactions.index.tabs.yearly');
    }

    public function periodEmptyMessage(): string
    {
        if (! $this->groupByMonth || $this->selectedPeriod === self::YEARLY_PERIOD) {
            return __('transactions.index.empty.yearly');
        }

        return __('transactions.index.empty.monthly');
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

    public function startEditTransaction(int $transactionId): void
    {
        $this->editingTransactionId = $transactionId;
    }

    #[On('transaction-edit-cancelled')]
    public function onTransactionEditCancelled(): void
    {
        $this->editingTransactionId = null;
    }

    #[On('transaction-edit-finished')]
    public function onTransactionEditFinished(): void
    {
        $this->editingTransactionId = null;
        $this->reloadTransactions();
    }

    public function editLivewireComponent(): ?string
    {
        return match ($this->kind) {
            'expense', 'expense_type' => 'soler-ui.transaction-entry.expense-form.edit',
            'revenue' => 'soler-ui.transaction-entry.revenue-form.edit',
            'purchase' => 'soler-ui.transaction-entry.purchase-form.edit',
            default => null,
        };
    }

    public function editAction(): ?string
    {
        return $this->editLivewireComponent() !== null ? 'startEditTransaction' : null;
    }

    public function deleteTransaction(int $transactionId): void
    {
        $actor = auth()->user();
        $transaction = Transaction::findOrFail($transactionId);

        $transaction->deactivate($actor, '利用者による削除');

        $this->availableAccountCounts = $this->resolveAvailableAccountCounts();
        $this->availableAccountNames = array_keys($this->availableAccountCounts);

        if (! $this->groupByMonth) {
            $this->accountNames = array_values(array_intersect($this->accountNames, $this->availableAccountNames));
        }

        $this->reloadTransactions();

        $this->dispatch('dashboard-transaction-created');
    }

    private function reloadTransactions(): void
    {
        $fiscalYear = auth()->user()->selectedBusinessUnit->currentFiscalYear;

        if ($this->groupByMonth) {
            $groupedMonths = $this->kind === self::KIND_OTHER
                ? ($fiscalYear?->monthlyOtherTransactionGroups() ?? [])
                : ($fiscalYear?->monthlyAccountTypeTransactionGroups(
                    $this->accountType,
                    $this->accountNames,
                    $this->excludedAccountNames,
                ) ?? []);
            $monthsByYearMonth = collect($groupedMonths)->keyBy('year_month');
            $this->months = collect(range(1, 12))
                ->map(function (int $month) use ($monthsByYearMonth): array {
                    $yearMonth = sprintf('%04d-%02d', $this->fiscalYearYear, $month);
                    $monthGroup = $monthsByYearMonth->get($yearMonth);

                    return [
                        'year_month' => $yearMonth,
                        'label' => $month.'月',
                        'amount' => (int) ($monthGroup['amount'] ?? 0),
                        'transaction_count' => count($monthGroup['transactions'] ?? []),
                    ];
                })
                ->all();
            $this->monthTransactions = collect($this->months)
                ->mapWithKeys(fn (array $month): array => [
                    $month['year_month'] => $monthsByYearMonth->get($month['year_month'])['transactions'] ?? [],
                ])
                ->all();
            $this->transactions = $this->kind === self::KIND_OTHER
                ? ($fiscalYear?->otherTransactions() ?? [])
                : ($fiscalYear?->accountTypeTransactions(
                    $this->accountType,
                    $this->accountNames,
                    $this->excludedAccountNames,
                ) ?? []);

            return;
        }

        if ($this->accountNames === []) {
            $this->transactions = [];
            $this->months = [];
            $this->monthTransactions = [];

            return;
        }

        $this->transactions = $fiscalYear?->accountTypeTransactions(
            $this->accountType,
            $this->accountNames,
            $this->excludedAccountNames,
        ) ?? [];
        $this->months = [];
        $this->monthTransactions = [];
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
        if ($this->kind === self::KIND_OTHER || $this->accountType !== Account::TYPE_EXPENSE || $this->groupByMonth) {
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

    private function isValidPeriod(string $period): bool
    {
        if ($period === self::YEARLY_PERIOD) {
            return true;
        }

        return collect($this->months)->contains(fn (array $month): bool => $month['year_month'] === $period);
    }
}
