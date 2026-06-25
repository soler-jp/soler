<?php

namespace App\Livewire\Recurring;

use App\Models\RecurringTransactionPlan;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Illuminate\Validation\ValidationException;

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
            ->orderByRaw("CASE name WHEN '現金' THEN 0 WHEN '普通預金' THEN 1 WHEN '事業主借' THEN 2 ELSE 3 END")
            ->get();
    }

    public function selectPlan(int $planId)
    {
        $this->selectedPlanId = $planId;
    }

    public function confirm(int $transactionId)
    {
        $unit = Auth::user()->selectedBusinessUnit;
        $fiscalYear = $unit->currentFiscalYear;
        $data = $this->inputs[$transactionId] ?? [];

        $validated = validator($data, [
            'amount' => ['required', 'integer', 'min:1'],
            'credit_sub_account_id' => ['required', $unit->subAccountExistsRule()],
            'date' => ['nullable', 'date'],
        ])->validate();

        $plan = $unit->recurringTransactionPlans()
            ->where('is_income', false)
            ->whereHas('transactions', function ($query) use ($transactionId, $fiscalYear) {
                $query->whereKey($transactionId)
                    ->where('fiscal_year_id', $fiscalYear->id);
            })
            ->first();

        if (!$plan) {
            return;
        }

        try {
            $transaction = $plan->confirmTransaction($transactionId, $validated);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError("inputs.$transactionId.$field", $message);
                }
            }

            return;
        }

        if (!$transaction) {
            return;
        }

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
                $debit = $tx->journalEntries->where('type', 'debit')->sortByDesc('net_amount')->first();
                $credit = $tx->journalEntries->where('type', 'credit')->sortByDesc('net_amount')->first();

                $this->inputs[$tx->id]['amount'] ??= $debit?->net_amount;
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
