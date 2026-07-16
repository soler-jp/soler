<?php

namespace App\Livewire\Pages;

use App\Data\TransactionSearchFilters;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class TransactionJournalIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $keyword = '';

    /**
     * @var array<int, string>
     */
    public array $debitAccountNames = [];

    /**
     * @var array<int, string>
     */
    public array $creditAccountNames = [];

    /**
     * @var array<int, int>
     */
    public array $months = [];

    #[Url]
    public string $exactAmount = '';

    #[Url]
    public string $minAmount = '';

    #[Url]
    public string $maxAmount = '';

    #[Url]
    public int $perPage = 100;

    #[Url(as: 'sort')]
    public string $sortBy = 'date';

    #[Url(as: 'direction')]
    public string $sortDirection = 'asc';

    /**
     * @var array<string, int>
     */
    public array $availableDebitAccountCounts = [];

    /**
     * @var array<string, int>
     */
    public array $availableCreditAccountCounts = [];

    public function mount(): void
    {
        $fiscalYear = auth()->user()->selectedBusinessUnit->currentFiscalYear;

        $this->availableDebitAccountCounts = $fiscalYear->transactionJournalAccountNameCounts('debit');
        $this->availableCreditAccountCounts = $fiscalYear->transactionJournalAccountNameCounts('credit');
    }

    public function updatedKeyword(): void
    {
        $this->resetPage();
    }

    public function updatedDebitAccountNames(): void
    {
        $this->resetPage();
    }

    public function updatedCreditAccountNames(): void
    {
        $this->resetPage();
    }

    public function updatedExactAmount(): void
    {
        $this->resetPage();
    }

    public function updatedMinAmount(): void
    {
        $this->resetPage();
    }

    public function updatedMaxAmount(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        if (! in_array($column, ['date', 'entry_number', 'amount', 'description', 'counterparty'], true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }

        $this->resetPage();
    }

    public function toggleMonth(int $month): void
    {
        if (in_array($month, $this->months, true)) {
            $this->months = array_values(array_filter($this->months, fn (int $selectedMonth): bool => $selectedMonth !== $month));
        } else {
            $this->months[] = $month;
            sort($this->months);
        }

        $this->resetPage();
    }

    public function clearDebitAccountNames(): void
    {
        $this->debitAccountNames = [];
        $this->resetPage();
    }

    public function clearCreditAccountNames(): void
    {
        $this->creditAccountNames = [];
        $this->resetPage();
    }

    public function clearMonths(): void
    {
        $this->months = [];
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(
            'keyword',
            'debitAccountNames',
            'creditAccountNames',
            'months',
            'exactAmount',
            'minAmount',
            'maxAmount',
            'perPage',
            'sortBy',
            'sortDirection',
        );
        $this->perPage = 100;
        $this->sortBy = 'date';
        $this->sortDirection = 'asc';
        $this->resetPage();
    }

    /**
     * @return array<int, int>
     */
    public function monthOptions(): array
    {
        return range(1, 12);
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function debitAccountOptionCounts(): array
    {
        $fiscalYear = auth()->user()->selectedBusinessUnit->currentFiscalYear;

        return $fiscalYear->transactionJournalAvailableAccountNameCounts(
            'debit',
            $this->filtersForDebitOptions(),
        );
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function creditAccountOptionCounts(): array
    {
        $fiscalYear = auth()->user()->selectedBusinessUnit->currentFiscalYear;

        return $fiscalYear->transactionJournalAvailableAccountNameCounts(
            'credit',
            $this->filtersForCreditOptions(),
        );
    }

    #[Computed]
    public function transactions(): LengthAwarePaginator
    {
        $fiscalYear = auth()->user()->selectedBusinessUnit->currentFiscalYear;

        return $fiscalYear->searchTransactionsForJournal($this->filters());
    }

    public function sortIndicator(string $column): string
    {
        if ($this->sortBy !== $column) {
            return '↕';
        }

        return $this->sortDirection === 'asc' ? '↑' : '↓';
    }

    public function debitAccountOptionIsAvailable(string $accountName): bool
    {
        return array_key_exists($accountName, $this->debitAccountOptionCounts);
    }

    public function creditAccountOptionIsAvailable(string $accountName): bool
    {
        return array_key_exists($accountName, $this->creditAccountOptionCounts);
    }

    public function render(): View
    {
        return view('livewire.pages.transaction-journal-index');
    }

    private function nullableInteger(string $value): ?int
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        return (int) $trimmed;
    }

    private function filters(): TransactionSearchFilters
    {
        return TransactionSearchFilters::from(
            debitAccountNames: $this->debitAccountNames,
            creditAccountNames: $this->creditAccountNames,
            keyword: $this->keyword,
            months: $this->months,
            exactAmount: $this->nullableInteger($this->exactAmount),
            minAmount: $this->nullableInteger($this->minAmount),
            maxAmount: $this->nullableInteger($this->maxAmount),
            sortBy: $this->sortBy,
            sortDirection: $this->sortDirection,
            perPage: $this->perPage,
        );
    }

    private function filtersForDebitOptions(): TransactionSearchFilters
    {
        return TransactionSearchFilters::from(
            creditAccountNames: $this->creditAccountNames,
            keyword: $this->keyword,
            months: $this->months,
            exactAmount: $this->nullableInteger($this->exactAmount),
            minAmount: $this->nullableInteger($this->minAmount),
            maxAmount: $this->nullableInteger($this->maxAmount),
            sortBy: $this->sortBy,
            sortDirection: $this->sortDirection,
            perPage: $this->perPage,
        );
    }

    private function filtersForCreditOptions(): TransactionSearchFilters
    {
        return TransactionSearchFilters::from(
            debitAccountNames: $this->debitAccountNames,
            keyword: $this->keyword,
            months: $this->months,
            exactAmount: $this->nullableInteger($this->exactAmount),
            minAmount: $this->nullableInteger($this->minAmount),
            maxAmount: $this->nullableInteger($this->maxAmount),
            sortBy: $this->sortBy,
            sortDirection: $this->sortDirection,
            perPage: $this->perPage,
        );
    }
}
