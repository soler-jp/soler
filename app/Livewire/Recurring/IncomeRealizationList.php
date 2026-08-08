<?php

namespace App\Livewire\Recurring;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\RecurringTransactionPlan;
use App\Models\SubAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RecurringIncomeRealizationService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use LogicException;

class IncomeRealizationList extends Component
{
    public ?int $selectedPlanId = null;

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $inputs = [];

    public ?string $noticeMessage = null;

    public ?string $errorMessage = null;

    /**
     * @var list<SubAccount>
     */
    public array $receiptStandardSubAccounts = [];

    /**
     * @var list<SubAccount>
     */
    public array $receiptOwnerDrawSubAccounts = [];

    /**
     * @var list<SubAccount>
     */
    public array $receiptSpecialSubAccounts = [];

    public function mount(): void
    {
        $unit = $this->currentUser()->selectedBusinessUnitOrFail();

        $cashAccount = $unit->getAccountByName('現金');
        $bankAccount = $unit->getAccountByName('その他の預金');
        $ownerDrawAccount = $unit->getAccountByName('事業主貸');
        $accountsReceivableAccount = $unit->getAccountByName('売掛金');
        $withholdingSubAccountId = $unit->getSubAccountByName('事業主貸', RecurringTransactionPlan::WITHHOLDING_SUB_ACCOUNT_NAME)?->id;

        $this->receiptStandardSubAccounts = array_merge(
            $this->collectReceiptSubAccounts($cashAccount),
            $this->collectReceiptSubAccounts($bankAccount),
        );

        $this->receiptOwnerDrawSubAccounts = collect($this->collectReceiptSubAccounts($ownerDrawAccount))
            ->reject(fn (SubAccount $sub): bool => $sub->id === $withholdingSubAccountId)
            ->values()
            ->all();

        $this->receiptSpecialSubAccounts = $this->collectReceiptSubAccounts($accountsReceivableAccount);
    }

    public function selectPlan(int $planId): void
    {
        $this->selectedPlanId = $planId;
        $this->noticeMessage = null;
        $this->errorMessage = null;
    }

    public function realize(int $transactionId): void
    {
        $this->noticeMessage = null;
        $this->errorMessage = null;
        $this->resetErrorBag();

        $actor = $this->currentUser();
        $unit = $actor->selectedBusinessUnitOrFail();
        $data = $this->inputs[$transactionId] ?? [];

        $validated = validator($data, [
            'amount' => ['required', 'integer', 'min:1'],
            'withholding_tax_amount' => ['nullable', 'integer', 'min:0'],
            'receipt_date' => ['required', 'date'],
            'receipt_sub_account_id' => ['required', $unit->subAccountExistsRule()],
            'tax_option' => [(bool) $unit->currentFiscalYear?->is_taxable ? 'required' : 'nullable', 'in:8,10'],
        ])->validate();

        $transaction = Transaction::query()
            ->with('recurringTransactionPlan')
            ->whereKey($transactionId)
            ->first();

        if (! $transaction instanceof Transaction) {
            return;
        }

        $plan = $transaction->recurringTransactionPlan;

        if (! $plan instanceof RecurringTransactionPlan || $plan->business_unit_id !== $unit->id || $plan->type !== RecurringTransactionPlan::TYPE_INCOME) {
            return;
        }

        try {
            app(RecurringIncomeRealizationService::class)->realize($transaction, $validated, $actor);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError("inputs.$transactionId.$field", $message);
                }
            }

            return;
        }

        unset($this->inputs[$transactionId]);

        $this->noticeMessage = __('recurring_income_realizations.notices.realized');
    }

    public function render(): View
    {
        $unit = $this->currentUser()->selectedBusinessUnitOrFail();
        $fiscalYear = $unit->currentFiscalYear;

        if ($fiscalYear === null) {
            throw new LogicException('IncomeRealizationList は会計年度が選択されている前提です。');
        }

        $plans = $unit->recurringTransactionPlans()
            ->where('type', RecurringTransactionPlan::TYPE_INCOME)
            ->orderBy('name')
            ->get();

        $selectedPlan = $plans->firstWhere('id', $this->selectedPlanId)
            ?? $plans->first();

        $this->selectedPlanId = $selectedPlan?->id;

        $transactions = $selectedPlan instanceof RecurringTransactionPlan
            ? $selectedPlan->transactions()
                ->with([
                    'journalEntries.subAccount.account',
                    'counterparty',
                    'settlementTransactions.journalEntries.subAccount.account',
                ])
                ->active()
                ->whereBetween('date', [$fiscalYear->start_date, $fiscalYear->end_date])
                ->orderBy('date')
                ->orderBy('id')
                ->get()
            : collect();

        foreach ($transactions as $transaction) {
            $this->initializeInputs($transaction);
        }

        return view('livewire.recurring.income-realization-list', [
            'plans' => $plans,
            'selectedPlan' => $selectedPlan,
            'transactions' => $transactions,
            'periodLabels' => $transactions
                ->mapWithKeys(fn (Transaction $transaction): array => [
                    $transaction->id => $this->periodLabel($selectedPlan, $transaction),
                ])
                ->all(),
            'realizedMessages' => $transactions
                ->filter(fn (Transaction $transaction): bool => ! $transaction->is_planned)
                ->mapWithKeys(fn (Transaction $transaction): array => [
                    $transaction->id => $this->realizedMessage($transaction),
                ])
                ->all(),
            'previewMessages' => $transactions
                ->mapWithKeys(fn (Transaction $transaction): array => [
                    $transaction->id => $this->previewLines($selectedPlan, $transaction),
                ])
                ->all(),
        ]);
    }

    private function initializeInputs(Transaction $transaction): void
    {
        $plan = $transaction->recurringTransactionPlan;
        $primaryDebitEntry = $transaction->journalEntries
            ->where('type', JournalEntry::TYPE_DEBIT)
            ->firstWhere('sub_account_id', '!=', $plan?->withholding_sub_account_id)
            ?? $transaction->journalEntries->firstWhere('type', JournalEntry::TYPE_DEBIT);

        $creditEntry = $transaction->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT);

        $this->inputs[$transaction->id]['amount'] ??= $creditEntry !== null
            ? (int) $creditEntry->net_amount + (int) $creditEntry->tax_amount
            : 0;
        $this->inputs[$transaction->id]['tax_option'] ??= $this->taxOptionFromTaxType($creditEntry?->tax_type);
        $this->inputs[$transaction->id]['withholding_tax_amount'] ??= (int) ($plan?->withholding_tax_amount ?? 0);
        $this->inputs[$transaction->id]['receipt_date'] ??= $transaction->date?->toDateString();
        $this->inputs[$transaction->id]['receipt_sub_account_id'] ??= $primaryDebitEntry?->sub_account_id;
    }

    private function buildConfirmationMessage(
        Transaction $transaction,
        Carbon $receiptDate,
        int $amount,
        SubAccount $receiptSubAccount,
    ): string {
        $plannedDate = $transaction->date?->copy();

        if (! $plannedDate instanceof Carbon) {
            return '';
        }

        $payload = [
            'date' => $receiptDate->format('n/j'),
            'amount' => number_format($amount),
            'receipt' => $receiptSubAccount->displayName(),
        ];

        $isCrossMonth = $receiptDate->year !== $plannedDate->year
            || $receiptDate->month !== $plannedDate->month;

        if (! $isCrossMonth) {
            return __('recurring_income_realizations.messages.same_month', $payload);
        }

        if ($receiptDate->copy()->startOfDay()->gt(now()->startOfDay())) {
            return __('recurring_income_realizations.messages.cross_month_future', $payload);
        }

        return __('recurring_income_realizations.messages.cross_month_past', $payload);
    }

    /**
     * @return list<string>
     */
    private function previewLines(?RecurringTransactionPlan $plan, Transaction $transaction): array
    {
        if (! $transaction->is_planned) {
            return [];
        }

        $input = $this->inputs[$transaction->id] ?? [];
        $receiptDate = isset($input['receipt_date']) && $input['receipt_date'] !== ''
            ? Carbon::parse($input['receipt_date'])
            : null;
        $grossAmount = (int) ($input['amount'] ?? 0);
        $withholdingTaxAmount = (int) ($input['withholding_tax_amount'] ?? 0);
        $receiptSubAccount = isset($input['receipt_sub_account_id'])
            ? $this->findReceiptSubAccount((int) $input['receipt_sub_account_id'])
            : null;

        if (! $receiptDate instanceof Carbon || $grossAmount <= 0 || ! $receiptSubAccount instanceof SubAccount) {
            return [];
        }

        $creditEntry = $transaction->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT);
        $taxOption = (string) ($input['tax_option'] ?? $this->taxOptionFromTaxType($creditEntry?->tax_type));
        $taxAmount = $this->taxAmountForPreview($grossAmount, $taxOption);
        $netReceiptAmount = max(0, $grossAmount - $withholdingTaxAmount);
        $periodLabel = $this->periodLabel($plan, $transaction);
        $planName = $plan?->name ?? '';

        $lines = [];

        if ($this->currentUser()->selectedBusinessUnitOrFail()->currentFiscalYear?->is_taxable) {
            $taxRateLabel = $this->taxRateLabelForPreview($taxOption);

            $lines[] = __('recurring_income_realizations.preview.taxable_summary', [
                'period' => $periodLabel,
                'name' => $planName,
                'gross' => number_format($grossAmount),
                'tax_rate' => $taxRateLabel,
                'tax' => number_format($taxAmount),
            ]);
        } else {
            $lines[] = __('recurring_income_realizations.preview.non_taxable_summary', [
                'period' => $periodLabel,
                'name' => $planName,
                'gross' => number_format($grossAmount),
            ]);
        }

        $receiptContext = $this->receiptContext($receiptDate, $receiptSubAccount);

        if ($withholdingTaxAmount > 0) {
            $lines[] = __('recurring_income_realizations.preview.withholding_summary', [
                'withholding' => number_format($withholdingTaxAmount),
                'net' => number_format($netReceiptAmount),
                'when' => $receiptContext['when'],
                'destination' => $receiptContext['destination'],
            ]);
        } else {
            $lines[] = __('recurring_income_realizations.preview.no_withholding_summary', [
                'net' => number_format($netReceiptAmount),
                'when' => $receiptContext['when'],
                'destination' => $receiptContext['destination'],
            ]);
        }

        return $lines;
    }

    private function periodLabel(?RecurringTransactionPlan $plan, Transaction $transaction): string
    {
        $description = (string) $transaction->description;

        if ($plan?->interval === 'yearly' && preg_match('/(\d{4})年分/u', $description, $matches) === 1) {
            return $matches[1].'年分';
        }

        if (preg_match('/(\d{1,2})月分/u', $description, $matches) === 1) {
            return $matches[1].'月分';
        }

        return ($transaction->date?->format('n') ?? '').'月分';
    }

    private function realizedMessage(Transaction $transaction): string
    {
        $settlementTransaction = $transaction->settlementTransactions->sortByDesc('date')->first();

        if ($settlementTransaction instanceof Transaction) {
            $receiptDate = $settlementTransaction->date;
            $amount = (int) $settlementTransaction->journalEntries
                ->where('type', JournalEntry::TYPE_CREDIT)
                ->sum(fn (JournalEntry $entry): int => (int) $entry->net_amount + (int) $entry->tax_amount);
            $receiptSubAccount = $settlementTransaction->journalEntries
                ->where('type', JournalEntry::TYPE_DEBIT)
                ->firstWhere('sub_account_id', '!=', $transaction->recurringTransactionPlan?->withholding_sub_account_id)
                ?->subAccount;

            if ($receiptDate instanceof Carbon && $receiptSubAccount instanceof SubAccount) {
                return $this->buildConfirmationMessage(
                    $transaction,
                    $receiptDate,
                    $amount,
                    $receiptSubAccount,
                );
            }
        }

        $amount = (int) $transaction->journalEntries
            ->where('type', JournalEntry::TYPE_CREDIT)
            ->sum(fn (JournalEntry $entry): int => (int) $entry->net_amount + (int) $entry->tax_amount);
        $receiptSubAccount = $transaction->journalEntries
            ->where('type', JournalEntry::TYPE_DEBIT)
            ->firstWhere('sub_account_id', '!=', $transaction->recurringTransactionPlan?->withholding_sub_account_id)
            ?->subAccount;

        if ($transaction->date instanceof Carbon && $receiptSubAccount instanceof SubAccount) {
            return $this->buildConfirmationMessage(
                $transaction,
                $transaction->date,
                $amount,
                $receiptSubAccount,
            );
        }

        return __('recurring_income_realizations.realized_default');
    }

    private function taxAmountForPreview(int $grossAmount, ?string $taxOption): int
    {
        $rate = match ($taxOption) {
            '10' => 10,
            '8' => 8,
            default => 0,
        };

        if ($rate === 0) {
            return 0;
        }

        $netAmount = intdiv($grossAmount * 100, 100 + $rate);

        return $grossAmount - $netAmount;
    }

    private function taxRateLabelForPreview(?string $taxOption): string
    {
        return match ($taxOption) {
            '8' => '8%',
            '10' => '10%',
            default => '0%',
        };
    }

    private function taxOptionFromTaxType(?string $taxType): string
    {
        return match ($taxType) {
            JournalEntry::TAX_TYPE_TAXABLE_SALES_8,
            JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_8 => '8',
            default => '10',
        };
    }

    /**
     * @return array{when: string, destination: string}
     */
    private function receiptContext(Carbon $receiptDate, SubAccount $receiptSubAccount): array
    {
        $timing = $receiptDate->copy()->startOfDay()->timestamp <=> now()->startOfDay()->timestamp;
        $when = $timing === 0
            ? __('recurring_income_realizations.preview.when_today')
            : __('recurring_income_realizations.preview.when_date', [
                'date' => $receiptDate->format('n/j'),
            ]);

        $accountName = $receiptSubAccount->account?->name ?? $receiptSubAccount->name;

        $category = match ($accountName) {
            'その他の預金' => 'bank',
            '売掛金' => 'receivable',
            default => 'receive',
        };

        $tense = match (true) {
            $timing > 0 => 'future',
            $timing < 0 => 'past',
            default => 'today',
        };

        return [
            'when' => $when,
            'destination' => __('recurring_income_realizations.preview.destination.'.$category.'_'.$tense, [
                'receipt' => $receiptSubAccount->displayName(),
            ]),
        ];
    }

    private function currentUser(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new LogicException('IncomeRealizationList は認証済みユーザーからのみ利用できます。');
        }

        return $user;
    }

    private function findReceiptSubAccount(int $subAccountId): ?SubAccount
    {
        return collect([
            ...$this->receiptStandardSubAccounts,
            ...$this->receiptOwnerDrawSubAccounts,
            ...$this->receiptSpecialSubAccounts,
        ])->first(fn (SubAccount $subAccount): bool => $subAccount->id === $subAccountId);
    }

    /**
     * @return list<SubAccount>
     */
    private function collectReceiptSubAccounts(?Account $account): array
    {
        if ($account === null) {
            return [];
        }

        return $account->subAccounts
            ->filter(fn (SubAccount $subAccount): bool => $subAccount->name === $account->name
                || (
                    $subAccount->system_purpose === null
                    && $subAccount->visibility === SubAccount::VISIBILITY_STANDARD
                ))
            ->values()
            ->all();
    }
}
