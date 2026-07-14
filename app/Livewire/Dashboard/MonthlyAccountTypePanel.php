<?php

namespace App\Livewire\Dashboard;

use App\Models\Account;
use Livewire\Attributes\On;
use Livewire\Component;

class MonthlyAccountTypePanel extends Component
{
    public string $accountType;

    public string $title;

    public string $variant;

    public int $totalAmount = 0;

    /**
     * @var array<int, string>
     */
    public array $accountNames = [];

    /**
     * @var array<int, string>
     */
    public array $excludedAccountNames = [];

    public bool $showMonthsModal = false;

    public bool $showTransactionsModal = false;

    /**
     * @var array<int, array{year_month: string, label: string, amount: int}>
     */
    public array $months = [];

    public ?string $selectedMonth = null;

    public function mount(
        string $accountType,
        string $title,
        ?string $variant = null,
        array $accountNames = [],
        array $excludedAccountNames = [],
    ): void {
        $this->accountType = $accountType;
        $this->title = $title;
        $this->variant = $variant ?? $accountType;
        $this->accountNames = $accountNames;
        $this->excludedAccountNames = $excludedAccountNames;

        $this->reload();
    }

    #[On('dashboard-transaction-created')]
    public function reload(): void
    {
        $fiscalYear = auth()->user()->selectedBusinessUnit->currentFiscalYear;
        $summary = $fiscalYear?->monthlyAccountTypeSummaryData(
            $this->accountType,
            $this->accountNames,
            $this->excludedAccountNames,
        ) ?? ['months' => [], 'total_amount' => 0];
        $this->months = $summary['months'];
        $this->totalAmount = $summary['total_amount'];

        $availableMonths = collect($this->months)->pluck('year_month');

        if ($availableMonths->isEmpty()) {
            $this->selectedMonth = null;

            return;
        }

        if ($this->selectedMonth === null || ! $availableMonths->contains($this->selectedMonth)) {
            $this->selectedMonth = $availableMonths->first();
        }
    }

    public function selectMonth(string $yearMonth): void
    {
        if (collect($this->months)->pluck('year_month')->contains($yearMonth)) {
            $this->selectedMonth = $yearMonth;
            $this->showMonthsModal = false;
            $this->showTransactionsModal = true;
        }
    }

    public function openMonthsModal(): void
    {
        $this->showMonthsModal = true;
    }

    public function closeMonthsModal(): void
    {
        $this->showMonthsModal = false;
    }

    public function closeTransactionsModal(): void
    {
        $this->showTransactionsModal = false;
    }

    /**
     * @return array<string, string>
     */
    public function palette(): array
    {
        return match ($this->variant) {
            Account::TYPE_EXPENSE => [
                'panel' => 'border-red-200 bg-red-50/80',
                'hover' => 'hover:border-red-300 hover:bg-red-100/70',
                'title' => 'text-red-700',
                'amount' => 'text-red-600',
                'chip' => 'bg-red-100 text-red-700',
                'monthDefault' => 'border-red-100 bg-white text-red-900 hover:bg-red-50',
                'monthActive' => 'border-red-600 bg-red-600 text-white',
                'tableWrap' => 'border-red-100',
                'tableHead' => 'bg-red-50 text-red-800',
            ],
            default => [
                'panel' => 'border-blue-200 bg-blue-50/80',
                'hover' => 'hover:border-blue-300 hover:bg-blue-100/70',
                'title' => 'text-blue-700',
                'amount' => 'text-blue-600',
                'chip' => 'bg-blue-100 text-blue-700',
                'monthDefault' => 'border-blue-100 bg-white text-blue-900 hover:bg-blue-50',
                'monthActive' => 'border-blue-600 bg-blue-600 text-white',
                'tableWrap' => 'border-blue-100',
                'tableHead' => 'bg-blue-50 text-blue-800',
            ],
        };
    }

    public function render()
    {
        return view('livewire.dashboard.monthly-account-type-panel');
    }
}
