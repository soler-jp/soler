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
    public int|null $revenueAccountId = null;
    public int|null $receiptAccountId = null;
    public int|null $withheldTaxAccountId = null;
    public int|null $withheldTaxSubAccountId = null;
    public bool $withholding = false;
    public array $receiptGroups;
    public string|null $selectedReceiptId = null;


    public function save()
    {

        $this->validate([
            'selectedReceiptId' => ['required'],
        ]);

        $receipt = $this->resolveReceiptAccount($this->selectedReceiptId);
        $this->receiptAccountId = $receipt['account_id'];
        $this->receiptSubAccountId = $receipt['sub_account_id'];

        $this->validate([
            'date' => ['required', 'date'],
            'gross_amount' => ['required', 'integer', 'min:1'],
            'revenueAccountId' => ['required', 'exists:accounts,id'],
            'receiptAccountId' => ['required', 'exists:accounts,id'],
        ]);

        $user = auth()->user();
        $unit = $user->selectedBusinessUnit;
        $fiscalYear = $unit->currentFiscalYear;

        $registrar = new TransactionRegistrar();

        $journalEntries = [];
        // 源泉徴収がある場合の処理
        if ($this->withholding) {
            $journalEntries = [
                [
                    'account_id' => $this->revenueAccountId,
                    'type' => 'credit',
                    'amount' => (int) $this->gross_amount,
                ],
                [
                    'account_id' => $this->receiptAccountId,
                    'sub_account_id' => $this->receiptSubAccountId,
                    'type' => 'debit',
                    'amount' => (int) ($this->gross_amount - $this->holding_amount),
                ],
                [
                    'account_id' => $this->withheldTaxAccountId,
                    'sub_account_id' => $this->withheldTaxSubAccountId,
                    'type' => 'debit',
                    'amount' => (int) $this->holding_amount,
                ],
            ];
        } else {
            $journalEntries = [
                [
                    'account_id' => $this->receiptAccountId,
                    'sub_account_id' => $this->receiptSubAccountId,
                    'type' => 'debit',
                    'amount' => (int) $this->gross_amount,
                ],
                [
                    'account_id' => $this->revenueAccountId,
                    'type' => 'credit',
                    'amount' => (int) $this->gross_amount,
                ],
            ];
        }

        try {
            $registrar->register(
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
                'revenueAccountId',
                'receiptAccountId',
            ]);
        } catch (\Exception $e) {
            session()->flash('error', '取引の登録に失敗しました: ' . $e->getMessage());
            return;
        }
    }

    protected function resolveReceiptAccount(?string $selectedId): array
    {
        if (empty($selectedId)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'receiptAccountId' => '入金先を選択してください。',
            ]);
        }

        [$type, $id] = explode(':', $selectedId);

        if ($type === 'SubAccount') {
            $sub = \App\Models\SubAccount::findOrFail($id);
            return [
                'account_id' => $sub->account_id,
                'sub_account_id' => $sub->id,
            ];
        }

        return [
            'account_id' => (int) $id,
            'sub_account_id' => null,
        ];
    }


    public function mount()
    {
        $this->date = now()->toDateString();

        $unit = auth()->user()->selectedBusinessUnit;

        $this->revenueAccountId = $unit->getAccountByName('売上高')->id;

        $withheldAccount = $unit->getAccountByName('事業主貸');
        $this->withheldTaxAccountId = $withheldAccount->id;

        $withheldSubAccount = $unit->getSubAccountByName('事業主貸', '源泉徴収');
        $this->withheldTaxSubAccountId = $withheldSubAccount->id;

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
