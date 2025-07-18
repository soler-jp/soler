<?php

namespace App\Livewire;

use Livewire\Component;
use App\Services\TransactionRegistrar;
use Illuminate\Support\Collection;

class DashboardRevenueInput extends Component
{
    public string $date = '';
    public int|null $gross_amount = null;
    public int|null $holding_amount = null;
    public string $description = '';
    public int|null $revenueSubAccountId = null;
    public int|null $receiptSubAccountId = null;
    public int|null $withheldTaxSubAccountId = null;
    public bool $withholding = false;
    public array $receiptGroups;

    public function save()
    {
        $this->validate([
            'date' => ['required', 'date'],
            'gross_amount' => ['required', 'integer', 'min:1'],
            'revenueSubAccountId' => ['required', 'exists:sub_accounts,id'],
            'receiptSubAccountId' => ['required', 'exists:sub_accounts,id'],
        ]);

        $fiscalYear = auth()->user()->selectedBusinessUnit->currentFiscalYear;

        $journalEntries = [];
        if ($this->withholding) {
            $journalEntries = [
                [
                    'sub_account_id' => $this->revenueSubAccountId,
                    'type' => 'credit',
                    'amount' => (int) $this->gross_amount,
                ],
                [
                    'sub_account_id' => $this->receiptSubAccountId,
                    'type' => 'debit',
                    'amount' => (int) ($this->gross_amount - $this->holding_amount),
                ],
                [
                    'sub_account_id' => $this->withheldTaxSubAccountId,
                    'type' => 'debit',
                    'amount' => (int) $this->holding_amount,
                ],
            ];
        } else {
            $journalEntries = [
                [
                    'sub_account_id' => $this->receiptSubAccountId,
                    'type' => 'debit',
                    'amount' => (int) $this->gross_amount,
                ],
                [
                    'sub_account_id' => $this->revenueSubAccountId,
                    'type' => 'credit',
                    'amount' => (int) $this->gross_amount,
                ],
            ];
        }

        try {
            app(TransactionRegistrar::class)->register(
                $fiscalYear,
                [
                    'date' => $this->date,
                    'description' => $this->description,
                ],
                $journalEntries
            );

            session()->flash('message', '売上を登録しました');

            $this->reset([
                'date',
                'gross_amount',
                'description',
                'revenueSubAccountId',
                'receiptSubAccountId',
                'withholding',
                'holding_amount',
            ]);
        } catch (\Exception $e) {
            session()->flash('error', '取引の登録に失敗しました: ' . $e->getMessage());
        }
    }

    public function mount()
    {
        $this->date = now()->toDateString();

        $unit = auth()->user()->selectedBusinessUnit;

        $this->revenueSubAccountId = $unit->getAccountByName('売上高')->subAccounts()->first()->id;
        $this->withheldTaxSubAccountId = $unit->getSubAccountByName('事業主貸', '源泉徴収')->id;

        $cashAccount = $unit->getAccountByName('現金');
        $bankAccount = $unit->getAccountByName('その他の預金');

        $this->receiptGroups = [
            'cash' => $cashAccount->subAccounts->isNotEmpty()
                ? $cashAccount->subAccounts->all()
                : [$cashAccount],

            'bank' => $bankAccount->subAccounts->isNotEmpty()
                ? $bankAccount->subAccounts->all()
                : [$bankAccount],

            'other' => [
                $unit->getAccountByName('売掛金'),
                $unit->getAccountByName('事業主貸'),
            ],
        ];
    }

    public function render()
    {
        return view('livewire.dashboard-revenue-input');
    }
}
