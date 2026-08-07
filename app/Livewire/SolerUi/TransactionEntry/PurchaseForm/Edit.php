<?php

namespace App\Livewire\SolerUi\TransactionEntry\PurchaseForm;

use App\Livewire\SolerUi\TransactionEntry\Concerns\FormatsJapaneseAmount;
use App\Models\BusinessUnit;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Services\TransactionRevisor;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Edit extends Component
{
    use FormatsJapaneseAmount;

    private const MAX_AMOUNT = 10000000;

    public int $transactionId = 0;

    public string $date_input = '';

    public $amount = null;

    public string $tax_option = Standard::TAX_OPTION_10;

    public string $note = '';

    public string $counterparty_name = '';

    public ?int $purchase_sub_account_id = null;

    public ?int $credit_sub_account_id = null;

    public int $fiscalYearYear = 0;

    public bool $isTaxable = false;

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
            $this->purchase_sub_account_id = $debitEntry->sub_account_id;
            $this->amount = (int) $debitEntry->gross_amount;
            $this->tax_option = $this->reverseMapTaxOption($debitEntry->tax_type);
        }

        if ($creditEntry !== null) {
            $this->credit_sub_account_id = $creditEntry->sub_account_id;
        }
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
            return $display.' '.__('transactions.purchase_form.actions.update_suffix');
        }

        return __('transactions.purchase_form.actions.update');
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
            'purchase_sub_account_id' => ['required', $unit->subAccountExistsRule()],
            'credit_sub_account_id' => ['required', $unit->subAccountExistsRule()],
            'note' => ['nullable', 'string', 'max:255'],
            'counterparty_name' => ['nullable', 'string', 'max:255'],
        ]);

        [$month, $day] = $this->parseDateInput($this->date_input);

        if (! checkdate($month, $day, $this->fiscalYearYear)) {
            $this->addError('date_input', __('transactions.purchase_form.errors.invalid_date'));

            return;
        }

        $date = sprintf('%04d-%02d-%02d', $this->fiscalYearYear, $month, $day);

        if (! $fiscalYear->is_taxable) {
            $this->tax_option = Standard::TAX_OPTION_10;
        }

        $debitTaxType = $this->mapDebitTaxType($fiscalYear, $this->tax_option);
        $description = $this->resolveDescription();

        $transaction = Transaction::findOrFail($this->transactionId);

        try {
            app(TransactionRevisor::class)->reviseSinglePair(
                $transaction,
                $user,
                [
                    'revision_reason' => __('transactions.purchase_form.messages.revision_reason'),
                    'date' => $date,
                    'description' => $description,
                    'debit_sub_account_id' => (int) $this->purchase_sub_account_id,
                    'credit_sub_account_id' => (int) $this->credit_sub_account_id,
                    'tax_type' => $debitTaxType,
                    'gross_amount' => (int) $this->amount,
                ],
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            session()->flash('error', __('transactions.purchase_form.errors.update_failed').': '.$e->getMessage());

            return;
        }

        session()->flash('message', __('transactions.purchase_form.messages.updated'));
        $this->dispatch('transaction-edit-finished', transactionId: $this->transactionId);
        $this->dispatch('dashboard-transaction-created');
    }

    public function cancel(): void
    {
        $this->dispatch('transaction-edit-cancelled', transactionId: $this->transactionId);
    }

    public function render()
    {
        return view('livewire.soler-ui.transaction-entry.purchase-form.edit');
    }

    protected function loadFormChoices(): void
    {
        $unit = auth()->user()->selectedBusinessUnitOrFail();
        $fiscalYear = $unit->currentFiscalYear;
        $this->fiscalYearYear = (int) $fiscalYear->year;
        $this->isTaxable = (bool) $fiscalYear->is_taxable;

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

        return $note !== '' ? $note : '仕入';
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
