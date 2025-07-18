<?php

namespace App\Livewire;

use Livewire\Component;
use Illuminate\Support\Collection;
use App\Models\Account;
use App\Services\TransactionRegistrar;

class DashboardExpenseInput extends Component
{
    public string $date;
    public string $description = '';
    public int|null $amount = null;
    public int|null $debit_sub_account_id = null;
    public int|null $credit_sub_account_id = null;

    public Collection $expenseAccounts; // type = 'expense'
    public Collection $creditAccounts; // name in ['現金', '普通預金', '事業主借']


    public function mount()
    {
        $this->date = now()->toDateString();

        $unit = auth()->user()->selectedBusinessUnit;

        $this->expenseAccounts = $unit->accounts()
            ->with('subAccounts')
            ->where('type', Account::TYPE_EXPENSE)
            ->orderBy('name')
            ->get();

        $this->creditAccounts = $unit->accounts()
            ->with('subAccounts')
            ->whereIn('name', ['現金', '普通預金', '事業主借'])
            ->orderByRaw("FIELD(name, '現金', '普通預金', '事業主借')")
            ->get();
    }


    public function submit()
    {
        $this->validate([
            'date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'integer', 'min:1'],
            'debit_sub_account_id' => ['required', 'exists:sub_accounts,id'],
            'credit_sub_account_id' => ['required', 'exists:sub_accounts,id'],
        ]);

        $fiscalYear = auth()->user()->selectedBusinessUnit->currentFiscalYear;

        try {
            app(TransactionRegistrar::class)->register($fiscalYear, [
                'date' => $this->date,
                'description' => $this->description,
            ], [
                [
                    'sub_account_id' => $this->debit_sub_account_id,
                    'type' => 'debit',
                    'amount' => $this->amount,
                ],
                [
                    'sub_account_id' => $this->credit_sub_account_id,
                    'type' => 'credit',
                    'amount' => $this->amount,
                ],
            ]);

            // 初期化 & 確認メッセージ
            $this->reset(['description', 'amount', 'debit_sub_account_id', 'credit_sub_account_id']);
            session()->flash('message', '経費を登録しました');
        } catch (\Exception $e) {
            session()->flash('error', '経費の登録に失敗しました: ' . $e->getMessage());
        }
    }


    public function render()
    {
        return view('livewire.dashboard-expense-input');
    }
}
