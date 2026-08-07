<?php

namespace App\Livewire\SolerUi\TransactionEntry\ExpenseForm;

use App\Livewire\SolerUi\TransactionEntry\Concerns\FormatsJapaneseAmount;
use App\Models\Account;
use App\Models\BusinessUnit;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\SubAccount;
use App\Models\Transaction;
use App\Services\TransactionRevisor;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Edit extends Component
{
    use FormatsJapaneseAmount;

    private const MAX_AMOUNT = 1000000;

    public int $transactionId = 0;

    public string $date_input = '';

    public string $note = '';

    public string $counterparty_name = '';

    public $amount = null;

    public string $tax_option = Standard::TAX_OPTION_10;

    public ?int $debit_sub_account_id = null;

    public ?int $credit_sub_account_id = null;

    public bool $showExpanded = false;

    public int $fiscalYearYear = 0;

    public bool $isTaxable = false;

    public Collection $expenseSubAccountsStandard;

    public Collection $expenseSubAccountsUnclassified;

    public Collection $expenseSubAccountsExpanded;

    public Collection $creditAccounts;

    public function mount(int $transactionId): void
    {
        $this->transactionId = $transactionId;

        $this->loadFormChoices();

        $transaction = Transaction::with(['journalEntries.subAccount.account', 'counterparty'])
            ->findOrFail($transactionId);

        $debitEntry = $transaction->journalEntries
            ->firstWhere('type', JournalEntry::TYPE_DEBIT);
        $creditEntry = $transaction->journalEntries
            ->firstWhere('type', JournalEntry::TYPE_CREDIT);

        $this->date_input = optional($transaction->date)->format('md') ?? now()->format('md');
        $this->note = (string) ($transaction->description ?? '');
        $this->counterparty_name = (string) ($transaction->counterparty?->name ?? '');

        if ($debitEntry !== null) {
            $this->debit_sub_account_id = $debitEntry->sub_account_id;
            $this->amount = (int) $debitEntry->gross_amount;
            $this->tax_option = $this->reverseMapTaxOption($debitEntry->tax_type);
        }

        if ($creditEntry !== null) {
            $this->credit_sub_account_id = $creditEntry->sub_account_id;
        }
    }

    public function toggleExpanded(): void
    {
        $this->showExpanded = ! $this->showExpanded;
    }

    public function amountDisplay(): string
    {
        return $this->formatJapaneseAmount($this->amount);
    }

    public function amountSubmitLabel(): string
    {
        if ($this->amountInputInvalid()) {
            return __('transactions.shared.invalid_amount_submit');
        }

        $display = $this->amountDisplay();

        if ($display !== '') {
            return $display.' '.__('transactions.expense_form.actions.update_suffix');
        }

        return __('transactions.expense_form.actions.update');
    }

    public function amountInputInvalid(): bool
    {
        return $this->hasInvalidAmountInput($this->amount, max: self::MAX_AMOUNT);
    }

    public function submit(): void
    {
        $user = auth()->user();
        $unit = $user->selectedBusinessUnitOrFail();
        $fiscalYear = $unit->currentFiscalYear;

        $this->validate([
            'date_input' => ['required', 'string', 'regex:/^\d{3,4}$/'],
            'amount' => ['required', 'integer', 'min:1', 'max:'.self::MAX_AMOUNT],
            'tax_option' => ['required', 'in:'.implode(',', Standard::TAX_OPTIONS)],
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

        if (! $fiscalYear->is_taxable) {
            $this->tax_option = Standard::TAX_OPTION_10;
        }

        $debitTaxType = $this->mapDebitTaxType($fiscalYear, $this->tax_option);
        $description = $this->resolveDescription();

        $transaction = Transaction::findOrFail($this->transactionId);
        $revisionData = [
            'revision_reason' => __('transactions.expense_form.messages.revision_reason'),
            'date' => $date,
            'description' => $description,
            'debit_sub_account_id' => (int) $this->debit_sub_account_id,
            'credit_sub_account_id' => (int) $this->credit_sub_account_id,
            'tax_type' => $debitTaxType,
            'gross_amount' => (int) $this->amount,
        ];

        $counterpartyName = trim($this->counterparty_name);
        $originalCounterpartyName = trim((string) ($transaction->counterparty?->name ?? ''));

        if ($counterpartyName !== $originalCounterpartyName) {
            $revisionData['counterparty_name'] = $counterpartyName === '' ? null : $counterpartyName;
        }

        try {
            app(TransactionRevisor::class)->reviseSinglePair(
                $transaction,
                $user,
                $revisionData,
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            session()->flash('error', __('transactions.expense_form.errors.update_failed').': '.$e->getMessage());

            return;
        }

        session()->flash('message', __('transactions.expense_form.messages.updated'));
        $this->dispatch('transaction-edit-finished', transactionId: $this->transactionId);
        $this->dispatch('dashboard-transaction-created');
    }

    public function cancel(): void
    {
        $this->dispatch('transaction-edit-cancelled', transactionId: $this->transactionId);
    }

    public function render()
    {
        return view('livewire.soler-ui.transaction-entry.expense-form.edit');
    }

    protected function loadFormChoices(): void
    {
        $unit = auth()->user()->selectedBusinessUnitOrFail();
        $fiscalYear = $unit->currentFiscalYear;
        $this->fiscalYearYear = (int) $fiscalYear->year;
        $this->isTaxable = (bool) $fiscalYear->is_taxable;

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

        $this->creditAccounts = $unit->paymentAccounts(BusinessUnit::PAYMENT_ACCOUNT_PRESET_PAYMENT);
    }

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
            Standard::TAX_OPTION_10 => $fiscalYear->is_taxable
                ? JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10
                : JournalEntry::TAX_TYPE_DEEMED_TAXABLE_PURCHASES_10,
            Standard::TAX_OPTION_8 => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_8,
            Standard::TAX_OPTION_EXEMPT => JournalEntry::TAX_TYPE_EXEMPT,
        };
    }

    protected function reverseMapTaxOption(?string $taxType): string
    {
        return match ($taxType) {
            JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_8 => Standard::TAX_OPTION_8,
            JournalEntry::TAX_TYPE_EXEMPT => Standard::TAX_OPTION_EXEMPT,
            default => Standard::TAX_OPTION_10,
        };
    }
}
