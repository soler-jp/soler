<?php

namespace App\Livewire\Recurring;

use Livewire\Component;
use Illuminate\Support\Collection;
use App\Models\Account;
use App\Models\RecurringTransactionPlan;

class Form extends Component
{
    public array $form = [
        'name' => null,
        'interval' => 'monthly',
        'day_of_month' => null,
        'month_of_year' => null,
        'is_income' => false,
        'debit_sub_account_id' => null,
        'credit_sub_account_id' => null,
        'amount' => null,
        'tax_amount' => 0,
        'tax_type' => null,
        'start_month_type' => 'odd',
    ];

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

    public function save()
    {
        $unit = auth()->user()->selectedBusinessUnit;

        $validated = $this->validate([
            'form.name' => ['required', 'string', 'max:255'],
            'form.debit_sub_account_id' => ['required', 'exists:sub_accounts,id'],
            'form.credit_sub_account_id' => ['nullable', 'exists:sub_accounts,id'],
            'form.amount' => ['required', 'integer', 'min:0'],
            'form.tax_amount' => ['required', 'integer', 'min:0'],
            'form.interval' => ['required', 'in:monthly,bimonthly,yearly'],
            'form.day_of_month' => ['required', 'integer', 'min:1', 'max:31'],
            'form.start_month_type' => ['required_if:form.interval,bimonthly', 'in:odd,even'],
            'form.month_of_year' => ['nullable', 'integer', 'min:1', 'max:12'],
            'form.tax_amount' => ['nullable', 'integer', 'min:0'],
            'form.tax_type' => ['nullable', 'string', 'max:50'],
        ]);


        try {
            \DB::transaction(function () use ($unit, $validated) {
                $form = $validated['form'];

                $plan = $unit->createRecurringTransactionPlan([
                    'name' => $form['name'],
                    'debit_sub_account_id' => $form['debit_sub_account_id'],
                    'credit_sub_account_id' => $form['credit_sub_account_id'] ?? null,
                    'amount' => $form['amount'],
                    'interval' => $form['interval'],
                    'day_of_month' => $form['day_of_month'],
                    'month_of_year' => $form['month_of_year'],
                    'tax_amount' => $form['tax_amount'],
                    'tax_type' => $form['tax_type'],
                    'is_income' => false,
                    'start_month' => $form['interval'] === 'bimonthly'
                        ? ($form['start_month_type'] === 'odd' ? 1 : 2)
                        : null,
                ]);

                $unit->generatePlannedTransactionsForPlan(
                    $plan,
                    $unit->currentFiscalYear
                );
            });

            session()->flash('message', '定期取引を登録しました');
            $this->dispatch('plan-created');
            $this->reset('form');
        } catch (\Throwable $e) {
            \Log::error('定期取引の登録中にエラーが発生しました', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            session()->flash('error', '登録中にエラーが発生しました。もう一度お試しください。');
        }
    }

    public function render()
    {

        return view('livewire.recurring.form');
    }
}
