<?php

namespace App\Livewire\Recurring;

use App\Models\Account;
use App\Models\Counterparty;
use App\Models\JournalEntry;
use App\Models\RecurringTransactionPlan;
use App\Models\SubAccount;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use LogicException;

class IncomeForm extends Component
{
    public array $form = [
        'counterparty_id' => null,
        'name' => '',
        'interval' => 'monthly',
        'day_of_month' => 1,
        'month_of_year' => null,
        'debit_sub_account_id' => null,
        'gross_amount' => null,
        'tax_option' => '10',
        'is_withholding' => false,
        'withholding_tax_amount' => null,
    ];

    public bool $confirming = false;

    /**
     * @var Collection<int, Counterparty>
     */
    public Collection $counterparties;

    /**
     * @var list<SubAccount>
     */
    public array $receiptStandardSubAccounts = [];

    /**
     * @var list<SubAccount>
     */
    public array $receiptOwnerDrawSubAccounts = [];

    public function mount(): void
    {
        $unit = $this->currentUser()->selectedBusinessUnitOrFail();

        $this->counterparties = $unit->counterparties()
            ->orderBy('name')
            ->get();

        $cashAccount = $unit->getAccountByName('現金');
        $bankAccount = $unit->getAccountByName('その他の預金');
        $ownerDrawAccount = $unit->getAccountByName('事業主貸');
        $withholdingSubAccountId = $unit->getSubAccountByName('事業主貸', RecurringTransactionPlan::WITHHOLDING_SUB_ACCOUNT_NAME)?->id;

        $this->receiptStandardSubAccounts = array_merge(
            $this->collectReceiptSubAccounts($cashAccount),
            $this->collectReceiptSubAccounts($bankAccount),
        );

        $this->receiptOwnerDrawSubAccounts = collect($this->collectReceiptSubAccounts($ownerDrawAccount))
            ->reject(fn (SubAccount $subAccount): bool => $subAccount->id === $withholdingSubAccountId)
            ->values()
            ->all();

        $this->form['day_of_month'] = 1;
        $this->form['month_of_year'] = now()->month;
        $this->form['debit_sub_account_id'] = $this->receiptStandardSubAccounts[0]->id ?? null;
    }

    public function updatedFormInterval(string $value): void
    {
        if ($value === 'monthly') {
            $this->form['day_of_month'] = 1;
        }
    }

    public function submit(): void
    {
        if (! $this->runValidation()) {
            return;
        }

        $this->confirming = true;
    }

    public function back(): void
    {
        $this->confirming = false;
    }

    public function save(): void
    {
        $user = $this->currentUser();
        $unit = $user->selectedBusinessUnitOrFail();
        $fiscalYear = $unit->currentFiscalYear;

        if ($fiscalYear === null) {
            throw new LogicException('IncomeForm は会計年度が選択されている前提です。');
        }

        if (! $this->runValidation()) {
            $this->confirming = false;

            return;
        }

        /** @var array<string, mixed> $form */
        $form = $this->validate()['form'];
        [$amount, $taxAmount] = $this->splitGrossAmount((int) $form['gross_amount'], (int) $form['tax_option']);

        try {
            $plan = $unit->createRecurringTransactionPlan([
                'counterparty_id' => $form['counterparty_id'] ?: null,
                'name' => $form['name'],
                'interval' => $form['interval'],
                'day_of_month' => (int) $form['day_of_month'],
                'month_of_year' => $form['interval'] === 'yearly'
                    ? (int) $form['month_of_year']
                    : null,
                'type' => RecurringTransactionPlan::TYPE_INCOME,
                'debit_sub_account_id' => (int) $form['debit_sub_account_id'],
                'credit_sub_account_id' => $unit->getSubAccountByName('売上高', '売上高')?->id,
                'amount' => $amount,
                'tax_amount' => $taxAmount,
                'tax_type' => $this->mapTaxType($fiscalYear->is_taxable, (int) $form['tax_option']),
                'is_withholding' => (bool) $form['is_withholding'],
                'withholding_tax_amount' => (bool) $form['is_withholding']
                    ? (int) ($form['withholding_tax_amount'] ?? 0)
                    : null,
                'withholding_sub_account_id' => (bool) $form['is_withholding']
                    ? $unit->getSubAccountByName('事業主貸', RecurringTransactionPlan::WITHHOLDING_SUB_ACCOUNT_NAME)?->id
                    : null,
            ], $user);

            $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $normalizedField = str_replace('form.', '', $field);

                foreach ($messages as $message) {
                    $this->addError('form.'.$normalizedField, $message);
                }
            }

            return;
        }

        session()->flash('message', __('recurring_income_form.messages.created'));

        $this->form = [
            'counterparty_id' => null,
            'name' => '',
            'interval' => 'monthly',
            'day_of_month' => 1,
            'month_of_year' => now()->month,
            'debit_sub_account_id' => $this->receiptStandardSubAccounts[0]->id ?? null,
            'gross_amount' => null,
            'tax_option' => '10',
            'is_withholding' => false,
            'withholding_tax_amount' => null,
        ];
        $this->confirming = false;

        $this->dispatch('plan-created');
    }

    public function render(): View
    {
        return view('livewire.recurring.income-form');
    }

    public function selectedCounterpartyLabel(): string
    {
        if ($this->form['counterparty_id'] === null || $this->form['counterparty_id'] === '') {
            return __('recurring_income_form.confirm.no_counterparty');
        }

        return $this->counterparties
            ->firstWhere('id', (int) $this->form['counterparty_id'])
            ?->name ?? __('recurring_income_form.confirm.no_counterparty');
    }

    public function selectedReceiptLabel(): string
    {
        $subAccountId = (int) ($this->form['debit_sub_account_id'] ?? 0);

        return collect([
            ...$this->receiptStandardSubAccounts,
            ...$this->receiptOwnerDrawSubAccounts,
        ])->first(fn (SubAccount $subAccount): bool => $subAccount->id === $subAccountId)
            ?->displayName() ?? '';
    }

    public function selectedScheduleLabel(): string
    {
        if ($this->form['interval'] === 'monthly') {
            return __('recurring_income_form.confirm.schedule_monthly');
        }

        return __('recurring_income_form.confirm.schedule_yearly', [
            'month' => (int) ($this->form['month_of_year'] ?? 1),
            'day' => (int) ($this->form['day_of_month'] ?? 1),
        ]);
    }

    public function grossAmountDisplay(): string
    {
        $grossAmount = preg_replace('/\D+/', '', (string) ($this->form['gross_amount'] ?? ''));

        return $grossAmount === '' ? '' : number_format((int) $grossAmount).'円';
    }

    public function withholdingAmountDisplay(): string
    {
        $amount = preg_replace('/\D+/', '', (string) ($this->form['withholding_tax_amount'] ?? ''));

        return $amount === '' ? '' : number_format((int) $amount).'円';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function rules(): array
    {
        $unit = $this->currentUser()->selectedBusinessUnitOrFail();

        return [
            'form.counterparty_id' => ['required', 'integer', 'exists:counterparties,id'],
            'form.name' => ['required', 'string', 'max:255'],
            'form.interval' => ['required', 'in:monthly,yearly'],
            'form.day_of_month' => ['required', 'integer', 'min:1', 'max:31'],
            'form.month_of_year' => ['nullable', 'integer', 'min:1', 'max:12'],
            'form.debit_sub_account_id' => ['required', $unit->subAccountExistsRule()],
            'form.gross_amount' => ['required', 'integer', 'min:1'],
            'form.tax_option' => ['required', 'in:8,10'],
            'form.is_withholding' => ['boolean'],
            'form.withholding_tax_amount' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'form.counterparty_id' => __('recurring_income_form.fields.counterparty_id'),
            'form.name' => __('recurring_income_form.fields.name'),
            'form.interval' => __('recurring_income_form.fields.interval'),
            'form.day_of_month' => __('recurring_income_form.fields.day_of_month'),
            'form.month_of_year' => __('recurring_income_form.fields.month_of_year'),
            'form.debit_sub_account_id' => __('recurring_income_form.fields.debit_sub_account_id'),
            'form.gross_amount' => __('recurring_income_form.fields.gross_amount'),
            'form.tax_option' => __('recurring_income_form.fields.tax_option'),
            'form.withholding_tax_amount' => __('recurring_income_form.fields.withholding_tax_amount'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'form.counterparty_id.required' => __('recurring_income_form.validation.counterparty_required'),
            'form.counterparty_id.integer' => __('recurring_income_form.validation.counterparty_required'),
            'form.counterparty_id.exists' => __('recurring_income_form.validation.counterparty_invalid'),
            'form.name.required' => __('recurring_income_form.validation.required'),
            'form.interval.required' => __('recurring_income_form.validation.required'),
            'form.day_of_month.required' => __('recurring_income_form.validation.required'),
            'form.day_of_month.integer' => __('recurring_income_form.validation.integer'),
            'form.day_of_month.min' => __('recurring_income_form.validation.day_range'),
            'form.day_of_month.max' => __('recurring_income_form.validation.day_range'),
            'form.month_of_year.integer' => __('recurring_income_form.validation.integer'),
            'form.month_of_year.min' => __('recurring_income_form.validation.month_range'),
            'form.month_of_year.max' => __('recurring_income_form.validation.month_range'),
            'form.debit_sub_account_id.required' => __('recurring_income_form.validation.required'),
            'form.gross_amount.required' => __('recurring_income_form.validation.required'),
            'form.gross_amount.integer' => __('recurring_income_form.validation.integer'),
            'form.gross_amount.min' => __('recurring_income_form.validation.amount_min'),
            'form.tax_option.required' => __('recurring_income_form.validation.required'),
            'form.tax_option.in' => __('recurring_income_form.validation.tax_option_invalid'),
            'form.withholding_tax_amount.integer' => __('recurring_income_form.validation.integer'),
            'form.withholding_tax_amount.min' => __('recurring_income_form.validation.withholding_min'),
        ];
    }

    private function runValidation(): bool
    {
        $this->resetErrorBag();

        if ($this->form['interval'] === 'monthly') {
            $this->form['day_of_month'] = 1;
        }

        $this->form['gross_amount'] = $this->normalizeIntegerInput($this->form['gross_amount']);
        $this->form['withholding_tax_amount'] = $this->normalizeIntegerInput($this->form['withholding_tax_amount']);

        $this->validate();

        if ($this->form['interval'] === 'yearly' && blank($this->form['month_of_year'])) {
            $this->addError('form.month_of_year', __('recurring_income_form.errors.month_of_year_required'));

            return false;
        }

        if ($this->form['is_withholding'] && (int) ($this->form['withholding_tax_amount'] ?? 0) <= 0) {
            $this->addError('form.withholding_tax_amount', __('recurring_income_form.errors.withholding_required'));

            return false;
        }

        if ($this->form['is_withholding'] && (int) ($this->form['withholding_tax_amount'] ?? 0) >= (int) ($this->form['gross_amount'] ?? 0)) {
            $this->addError('form.withholding_tax_amount', __('recurring_income_form.errors.withholding_less_than_gross'));

            return false;
        }

        return true;
    }

    private function normalizeIntegerInput(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $normalized = preg_replace('/\D+/', '', (string) $value);

        if ($normalized === null || $normalized === '') {
            return null;
        }

        return (int) $normalized;
    }

    private function currentUser()
    {
        $user = auth()->user();

        if ($user === null) {
            throw new LogicException('IncomeForm は認証済みユーザーからのみ利用できます。');
        }

        return $user;
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

    /**
     * @return array{0: int, 1: int}
     */
    private function splitGrossAmount(int $grossAmount, int $taxRate): array
    {
        $netAmount = intdiv($grossAmount * 100, 100 + $taxRate);
        $taxAmount = $grossAmount - $netAmount;

        return [$netAmount, $taxAmount];
    }

    private function mapTaxType(bool $isTaxable, int $taxRate): string
    {
        if ($taxRate === 8) {
            return JournalEntry::TAX_TYPE_TAXABLE_SALES_8;
        }

        return $isTaxable
            ? JournalEntry::TAX_TYPE_TAXABLE_SALES_10
            : JournalEntry::TAX_TYPE_DEEMED_TAXABLE_SALES_10;
    }
}
