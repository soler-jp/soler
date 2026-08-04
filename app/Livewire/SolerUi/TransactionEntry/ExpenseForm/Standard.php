<?php

namespace App\Livewire\SolerUi\TransactionEntry\ExpenseForm;

use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\SubAccount;
use App\Services\TransactionRegistrar;
use Illuminate\Support\Collection;
use Livewire\Component;

class Standard extends Component
{
    public const TAX_OPTION_10 = '10';

    public const TAX_OPTION_8 = '8';

    public const TAX_OPTION_EXEMPT = 'exempt';

    public const TAX_OPTIONS = [
        self::TAX_OPTION_10,
        self::TAX_OPTION_8,
        self::TAX_OPTION_EXEMPT,
    ];

    public string $date_input = '';

    public string $note = '';

    public string $counterparty_name = '';

    public ?int $amount = null;

    public string $tax_option = self::TAX_OPTION_10;

    public ?int $debit_sub_account_id = null;

    public ?int $credit_sub_account_id = null;

    public bool $showExpanded = false;

    public int $fiscalYearYear = 0;

    public bool $isTaxable = false;

    public Collection $expenseSubAccountsStandard;

    public Collection $expenseSubAccountsUnclassified;

    public Collection $expenseSubAccountsExpanded;

    public Collection $creditAccounts;

    public function mount(): void
    {
        $unit = auth()->user()->selectedBusinessUnitOrFail();
        $fiscalYear = $unit->currentFiscalYear;
        $this->fiscalYearYear = (int) $fiscalYear->year;
        $this->isTaxable = (bool) $fiscalYear->is_taxable;

        if (! $fiscalYear->is_taxable) {
            $this->tax_option = self::TAX_OPTION_10;
        }

        $this->date_input = now()->format('md');

        $expenseSubAccounts = SubAccount::query()
            ->with('account')
            ->whereHas('account', fn ($q) => $q
                ->where('business_unit_id', $unit->id)
                ->where('type', Account::TYPE_EXPENSE))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $this->expenseSubAccountsStandard = $expenseSubAccounts
            ->filter(fn (SubAccount $sub) => $sub->visibility === SubAccount::VISIBILITY_STANDARD
                && $sub->system_purpose === null)
            ->values();

        $this->expenseSubAccountsUnclassified = $expenseSubAccounts
            ->filter(fn (SubAccount $sub) => $sub->system_purpose === SubAccount::PURPOSE_UNCLASSIFIED
                && $sub->visibility !== SubAccount::VISIBILITY_HIDDEN)
            ->values();

        $this->expenseSubAccountsExpanded = $expenseSubAccounts
            ->filter(fn (SubAccount $sub) => $sub->visibility === SubAccount::VISIBILITY_EXPANDED
                && $sub->system_purpose === null)
            ->values();

        $this->creditAccounts = $unit->accounts()
            ->with(['subAccounts' => fn ($q) => $q->where('visibility', '!=', SubAccount::VISIBILITY_HIDDEN)])
            ->whereIn('name', ['現金', '普通預金', '事業主借'])
            ->orderByRaw("CASE name WHEN '現金' THEN 0 WHEN '普通預金' THEN 1 WHEN '事業主借' THEN 2 ELSE 3 END")
            ->get();
    }

    public function toggleExpanded(): void
    {
        $this->showExpanded = ! $this->showExpanded;
    }

    public function submit(): void
    {
        $user = auth()->user();
        $unit = $user->selectedBusinessUnitOrFail();
        $fiscalYear = $unit->currentFiscalYear;

        $this->validate([
            'date_input' => ['required', 'string', 'regex:/^\d{3,4}$/'],
            'amount' => ['required', 'integer', 'min:1', 'max:1000000'],
            'tax_option' => ['required', 'in:'.implode(',', self::TAX_OPTIONS)],
            'debit_sub_account_id' => ['required', $unit->subAccountExistsRule()],
            'credit_sub_account_id' => ['required', $unit->subAccountExistsRule()],
            'note' => ['nullable', 'string', 'max:255'],
            'counterparty_name' => ['nullable', 'string', 'max:255'],
        ]);

        [$month, $day] = $this->parseDateInput($this->date_input);

        if (! checkdate($month, $day, $this->fiscalYearYear)) {
            $this->addError('date_input', __('transactions.expense_form.errors.invalid_date'));

            return;
        }

        $date = sprintf('%04d-%02d-%02d', $this->fiscalYearYear, $month, $day);
        $description = $this->resolveDescription();

        if (! $fiscalYear->is_taxable) {
            $this->tax_option = self::TAX_OPTION_10;
        }

        $debitTaxType = $this->mapDebitTaxType($fiscalYear, $this->tax_option);

        $transactionData = [
            'date' => $date,
            'description' => $description,
        ];

        $counterpartyName = trim($this->counterparty_name);
        if ($counterpartyName !== '') {
            $transactionData['counterparty_name'] = $counterpartyName;
        }

        try {
            app(TransactionRegistrar::class)->register(
                $fiscalYear,
                $transactionData,
                [
                    [
                        'sub_account_id' => $this->debit_sub_account_id,
                        'type' => JournalEntry::TYPE_DEBIT,
                        'gross_amount' => (int) $this->amount,
                        'tax_type' => $debitTaxType,
                    ],
                    [
                        'sub_account_id' => $this->credit_sub_account_id,
                        'type' => JournalEntry::TYPE_CREDIT,
                        'gross_amount' => (int) $this->amount,
                        'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                    ],
                ],
                $user,
            );

            $this->dispatch('dashboard-transaction-created');
            $this->reset([
                'note',
                'amount',
                'debit_sub_account_id',
                'credit_sub_account_id',
                'counterparty_name',
            ]);
            session()->flash('message', __('transactions.expense_form.messages.registered'));
        } catch (\Exception $e) {
            session()->flash('error', __('transactions.expense_form.errors.registration_failed').': '.$e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.soler-ui.transaction-entry.expense-form.standard');
    }

    /**
     * @return array{0:int,1:int}
     */
    protected function parseDateInput(string $input): array
    {
        $normalized = str_pad(trim($input), 4, '0', STR_PAD_LEFT);
        $month = (int) substr($normalized, 0, 2);
        $day = (int) substr($normalized, 2, 2);

        return [$month, $day];
    }

    protected function resolveDescription(): string
    {
        $note = trim($this->note);
        if ($note !== '') {
            return $note;
        }

        $subAccount = $this->debit_sub_account_id !== null
            ? SubAccount::with('account')->find($this->debit_sub_account_id)
            : null;

        if ($subAccount === null) {
            return '経費';
        }

        $accountName = $subAccount->account?->name ?? '';
        $subName = $subAccount->name;

        return $accountName === '' || $accountName === $subName
            ? $subName
            : $accountName.' - '.$subName;
    }

    protected function mapDebitTaxType(FiscalYear $fiscalYear, string $option): string
    {
        return match ($option) {
            self::TAX_OPTION_10 => $fiscalYear->is_taxable
                ? JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10
                : JournalEntry::TAX_TYPE_DEEMED_TAXABLE_PURCHASES_10,
            self::TAX_OPTION_8 => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_8,
            self::TAX_OPTION_EXEMPT => JournalEntry::TAX_TYPE_EXEMPT,
        };
    }
}
