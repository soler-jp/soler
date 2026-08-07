<?php

namespace App\Livewire\SolerUi\TransactionEntry\RevenueForm;

use App\Livewire\SolerUi\TransactionEntry\Concerns\FormatsJapaneseAmount;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\SubAccount;
use App\Models\Transaction;
use App\Services\TransactionRevisor;
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

    public ?int $revenue_sub_account_id = null;

    public ?int $receipt_sub_account_id = null;

    public int $fiscalYearYear = 0;

    public bool $isTaxable = false;

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
            $this->receipt_sub_account_id = $debitEntry->sub_account_id;
        }

        if ($creditEntry !== null) {
            $this->revenue_sub_account_id = $creditEntry->sub_account_id;
            $this->amount = (int) $creditEntry->gross_amount;
            $this->tax_option = $this->reverseMapTaxOption($creditEntry->tax_type);
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
            return $display.' '.__('transactions.revenue_form.actions.update_suffix');
        }

        return __('transactions.revenue_form.actions.update');
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
            'revenue_sub_account_id' => ['required', $unit->subAccountExistsRule()],
            'receipt_sub_account_id' => ['required', $unit->subAccountExistsRule()],
            'note' => ['nullable', 'string', 'max:255'],
            'counterparty_name' => ['nullable', 'string', 'max:255'],
        ]);

        [$month, $day] = $this->parseDateInput($this->date_input);

        if (! checkdate($month, $day, $this->fiscalYearYear)) {
            $this->addError('date_input', __('transactions.revenue_form.errors.invalid_date'));

            return;
        }

        $date = sprintf('%04d-%02d-%02d', $this->fiscalYearYear, $month, $day);

        if (! $fiscalYear->is_taxable) {
            $this->tax_option = Standard::TAX_OPTION_10;
        }

        $creditTaxType = $this->mapCreditTaxType($fiscalYear, $this->tax_option);
        $description = $this->resolveDescription();

        $transaction = Transaction::findOrFail($this->transactionId);

        try {
            app(TransactionRevisor::class)->reviseSinglePair(
                $transaction,
                $user,
                [
                    'revision_reason' => __('transactions.revenue_form.messages.revision_reason'),
                    'date' => $date,
                    'description' => $description,
                    'debit_sub_account_id' => (int) $this->receipt_sub_account_id,
                    'credit_sub_account_id' => (int) $this->revenue_sub_account_id,
                    'tax_type' => $creditTaxType,
                    'gross_amount' => (int) $this->amount,
                ],
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            session()->flash('error', __('transactions.revenue_form.errors.update_failed').': '.$e->getMessage());

            return;
        }

        session()->flash('message', __('transactions.revenue_form.messages.updated'));
        $this->dispatch('transaction-edit-finished', transactionId: $this->transactionId);
        $this->dispatch('dashboard-transaction-created');
    }

    public function cancel(): void
    {
        $this->dispatch('transaction-edit-cancelled', transactionId: $this->transactionId);
    }

    public function render()
    {
        return view('livewire.soler-ui.transaction-entry.revenue-form.edit');
    }

    protected function loadFormChoices(): void
    {
        $unit = auth()->user()->selectedBusinessUnitOrFail();
        $fiscalYear = $unit->currentFiscalYear;
        $this->fiscalYearYear = (int) $fiscalYear->year;
        $this->isTaxable = (bool) $fiscalYear->is_taxable;

        $withheldTaxSubAccountId = $unit->getSubAccountByName('事業主貸', '源泉徴収')?->id;

        $cashAccount = $unit->getAccountByName('現金');
        $bankAccount = $unit->getAccountByName('その他の預金');
        $ownerDrawAccount = $unit->getAccountByName('事業主貸');
        $accountsReceivable = $unit->getAccountByName('売掛金');

        $this->receiptStandardSubAccounts = array_merge(
            $this->collectReceiptSubAccounts($cashAccount),
            $this->collectReceiptSubAccounts($bankAccount),
        );

        $this->receiptOwnerDrawSubAccounts = collect($this->collectReceiptSubAccounts($ownerDrawAccount))
            ->reject(fn (SubAccount $sub) => $sub->id === $withheldTaxSubAccountId)
            ->values()
            ->all();

        $this->receiptSpecialSubAccounts = $this->collectReceiptSubAccounts($accountsReceivable);
    }

    /**
     * @return list<SubAccount>
     */
    protected function collectReceiptSubAccounts(?Account $account): array
    {
        if ($account === null) {
            return [];
        }

        return $account->subAccounts
            ->filter(fn (SubAccount $sub): bool => $sub->name === $account->name
                || (
                    $sub->system_purpose === null
                    && $sub->visibility === SubAccount::VISIBILITY_STANDARD
                ))
            ->values()
            ->all();
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

        $subAccount = $this->revenue_sub_account_id !== null
            ? SubAccount::with('account')->find($this->revenue_sub_account_id)
            : null;

        if ($subAccount === null) {
            return '売上';
        }

        $accountName = $subAccount->account?->name ?? '';
        $subName = $subAccount->name;

        return $accountName === '' || $accountName === $subName
            ? $subName
            : $accountName.' - '.$subName;
    }

    protected function mapCreditTaxType(FiscalYear $fiscalYear, string $option): string
    {
        return match ($option) {
            Standard::TAX_OPTION_10 => $fiscalYear->is_taxable
                ? JournalEntry::TAX_TYPE_TAXABLE_SALES_10
                : JournalEntry::TAX_TYPE_DEEMED_TAXABLE_SALES_10,
            Standard::TAX_OPTION_8 => JournalEntry::TAX_TYPE_TAXABLE_SALES_8,
            Standard::TAX_OPTION_EXEMPT => JournalEntry::TAX_TYPE_EXEMPT,
        };
    }

    protected function reverseMapTaxOption(?string $taxType): string
    {
        return match ($taxType) {
            JournalEntry::TAX_TYPE_TAXABLE_SALES_8 => Standard::TAX_OPTION_8,
            JournalEntry::TAX_TYPE_EXEMPT => Standard::TAX_OPTION_EXEMPT,
            default => Standard::TAX_OPTION_10,
        };
    }
}
