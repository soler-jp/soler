<?php

namespace App\Livewire\Dashboard;

use App\Models\Account;
use Livewire\Component;

class MonthlyTransactionTable extends Component
{
    public string $accountType;

    public string $yearMonth;

    public string $variant;

    public bool $showTaxTypeColumn = true;

    /**
     * @var array<int, array{
     *     id: int,
     *     date: string,
     *     amount: int,
     *     debit_label: string,
     *     credit_label: string,
     *     tax_type_label: string,
     *     counterparty_name: string
     * }>
     */
    public array $transactions = [];

    public function mount(string $accountType, string $yearMonth, ?string $variant = null): void
    {
        $this->accountType = $accountType;
        $this->yearMonth = $yearMonth;
        $this->variant = $variant ?? $accountType;
        $this->showTaxTypeColumn = (bool) auth()->user()->selectedBusinessUnit->currentFiscalYear?->is_taxable;

        $fiscalYear = auth()->user()->selectedBusinessUnit->currentFiscalYear;
        $this->transactions = $fiscalYear?->monthlyAccountTypeTransactions($this->accountType, $this->yearMonth) ?? [];
    }

    /**
     * @return array<string, string>
     */
    public function palette(): array
    {
        return match ($this->variant) {
            Account::TYPE_EXPENSE => [
                'wrap' => 'border-red-100',
                'head' => 'bg-red-50 text-red-800',
            ],
            default => [
                'wrap' => 'border-blue-100',
                'head' => 'bg-blue-50 text-blue-800',
            ],
        };
    }

    public function emptyStateColspan(): int
    {
        return ($this->accountType === Account::TYPE_EXPENSE ? 4 : 3)
            + ($this->showTaxTypeColumn ? 1 : 0)
            + 2;
    }

    public function render()
    {
        return view('livewire.dashboard.monthly-transaction-table');
    }
}
