<?php

namespace App\Livewire\Recurring;

use App\Models\RecurringTransactionPlan;
use App\Models\SubAccount;
use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Illuminate\Support\Carbon;

class TabList extends Component
{
    public ?int $selectedPlanId = null;
    public array $inputs = [];
    public $creditAccounts;

    public function mount()
    {
        $user = Auth::user();
        $unit = $user->selectedBusinessUnit;

        $this->creditAccounts = $unit->accounts()
            ->with('subAccounts')
            ->whereIn('name', ['現金', '普通預金', '事業主借'])
            ->orderByRaw("FIELD(name, '現金', '普通預金', '事業主借')")
            ->get();
    }

    public function selectPlan(int $planId)
    {
        $this->selectedPlanId = $planId;
    }

    public function confirm(int $transactionId)
    {
        $data = $this->inputs[$transactionId] ?? [];

        $validated = validator($data, [
            'amount' => ['required', 'integer', 'min:1'],
            'credit_sub_account_id' => ['required', 'exists:sub_accounts,id'],
            'date' => ['nullable', 'date'],
        ])->validate();

        $transaction = Transaction::with('journalEntries')->findOrFail($transactionId);

        if (! $transaction->is_planned) {
            return;
        }

        $debitEntry = $transaction->journalEntries->firstWhere('type', 'debit');
        $creditEntry = $transaction->journalEntries->firstWhere('type', 'credit');

        if (! $debitEntry || ! $creditEntry) {
            return;
        }

        $debitEntry->amount = $validated['amount'];
        $debitEntry->save();

        $creditEntry->amount = $validated['amount'];
        $creditEntry->sub_account_id = $validated['credit_sub_account_id'];
        $creditEntry->save();

        $transaction->is_planned = false;
        if (!empty($validated['date'])) {
            $transaction->date = Carbon::parse($validated['date']);
        }
        $transaction->save();

        unset($this->inputs[$transactionId]);
    }

    public function render()
    {
        $user = Auth::user();
        $unit = $user->selectedBusinessUnit;
        $fiscalYear = $unit->currentFiscalYear;

        $plans = $unit->recurringTransactionPlans()
            ->where('is_income', false)
            ->orderBy('day_of_month')
            ->get();

        $selectedPlan = $plans->firstWhere('id', $this->selectedPlanId)
            ?? $plans->first();

        $this->selectedPlanId = $selectedPlan?->id;

        $transactions = $selectedPlan
            ? $selectedPlan->transactions()
            ->with('journalEntries')
            ->whereBetween('date', [$fiscalYear->start_date, $fiscalYear->end_date])
            ->orderBy('date')
            ->get()
            : collect();

        foreach ($transactions as $tx) {
            if ($tx->is_planned) {
                $debit = $tx->journalEntries->where('type', 'debit')->sortByDesc('amount')->first();
                $credit = $tx->journalEntries->where('type', 'credit')->sortByDesc('amount')->first();

                $this->inputs[$tx->id]['amount'] ??= $debit?->amount;
                $this->inputs[$tx->id]['credit_sub_account_id'] ??= $credit?->sub_account_id;
                $this->inputs[$tx->id]['date'] ??= $tx->date->format('Y-m-d');
            }
        }

        return view('livewire.recurring.tab-list', [
            'plans' => $plans,
            'selectedPlan' => $selectedPlan,
            'transactions' => $transactions,
            'creditAccounts' => $this->creditAccounts,
        ]);
    }
}
