<?php

namespace App\Livewire\Pages;

use App\Services\FiscalYearAccountBreakdownCalculator;
use Livewire\Component;

class AccountSummaryIndex extends Component
{
    /**
     * @var array{
     *     asset: array{total_amount: int, accounts: array<int, array{account_id: int, account_name: string, total_amount: int, has_multiple_sub_accounts: bool, sub_accounts: array<int, array{sub_account_id: int, sub_account_name: string, amount: int}>}>},
     *     liability: array{total_amount: int, accounts: array<int, array{account_id: int, account_name: string, total_amount: int, has_multiple_sub_accounts: bool, sub_accounts: array<int, array{sub_account_id: int, sub_account_name: string, amount: int}>}>},
     *     equity: array{total_amount: int, accounts: array<int, array{account_id: int, account_name: string, total_amount: int, has_multiple_sub_accounts: bool, sub_accounts: array<int, array{sub_account_id: int, sub_account_name: string, amount: int}>}>},
     *     revenue: array{total_amount: int, accounts: array<int, array{account_id: int, account_name: string, total_amount: int, has_multiple_sub_accounts: bool, sub_accounts: array<int, array{sub_account_id: int, sub_account_name: string, amount: int}>}>},
     *     expense: array{total_amount: int, accounts: array<int, array{account_id: int, account_name: string, total_amount: int, has_multiple_sub_accounts: bool, sub_accounts: array<int, array{sub_account_id: int, sub_account_name: string, amount: int}>}>}
     * }
     */
    public array $accountTypeCards = [];

    public bool $showTransactionsModal = false;

    public string $selectedLabel = '';

    /**
     * @var array<int, array{
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
    public array $transactions = [];

    public function mount(FiscalYearAccountBreakdownCalculator $calculator): void
    {
        $fiscalYear = auth()->user()->selectedBusinessUnit->currentFiscalYear;

        $this->accountTypeCards = $calculator->calculate($fiscalYear, auth()->user());
    }

    public function openTransactionsModal(
        string $accountType,
        int $accountId,
        ?int $subAccountId,
        string $label,
    ): void {
        $fiscalYear = auth()->user()->selectedBusinessUnit->currentFiscalYear;
        $calculator = app(FiscalYearAccountBreakdownCalculator::class);

        $this->selectedLabel = $label;
        $this->transactions = $calculator->transactionsForBreakdown(
            $fiscalYear,
            $accountType,
            $accountId,
            $subAccountId,
            auth()->user(),
        );
        $this->showTransactionsModal = true;
    }

    public function closeTransactionsModal(): void
    {
        $this->showTransactionsModal = false;
    }

    public function render()
    {
        return view('livewire.pages.account-summary-index');
    }
}
