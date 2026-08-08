<?php

namespace App\Livewire\SolerUi\TransactionEntry\TransferForm;

use App\Livewire\SolerUi\TransactionEntry\Concerns\FormatsJapaneseAmount;
use App\Models\JournalEntry;
use App\Models\SubAccount;
use App\Models\Transaction;
use App\Services\TransactionRevisor;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Edit extends Component
{
    use FormatsJapaneseAmount;

    private const MAX_AMOUNT = 10000000;

    public int $transactionId = 0;

    public string $date_input = '';

    public $amount = null;

    public string $note = '';

    public ?int $from_sub_account_id = null;

    public ?int $to_sub_account_id = null;

    public int $fiscalYearYear = 0;

    public function mount(int $transactionId): void
    {
        $this->transactionId = $transactionId;

        $unit = auth()->user()->selectedBusinessUnitOrFail();
        $fiscalYear = $unit->currentFiscalYear;
        $this->fiscalYearYear = (int) $fiscalYear->year;

        $transaction = Transaction::with(['journalEntries.subAccount.account'])
            ->findOrFail($transactionId);

        $debitEntry = $transaction->journalEntries->firstWhere('type', JournalEntry::TYPE_DEBIT);
        $creditEntry = $transaction->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT);

        $this->date_input = optional($transaction->date)->format('md') ?? now()->format('md');
        $this->note = (string) ($transaction->description ?? '');

        if ($debitEntry !== null) {
            $this->to_sub_account_id = $debitEntry->sub_account_id;
            $this->amount = (int) $debitEntry->gross_amount;
        }

        if ($creditEntry !== null) {
            $this->from_sub_account_id = $creditEntry->sub_account_id;
        }
    }

    public function submit(): void
    {
        $user = auth()->user();
        $unit = $user->selectedBusinessUnitOrFail();

        $this->validate([
            'date_input' => ['required', 'string', 'regex:/^\d{3,4}$/'],
            'amount' => ['required', 'integer', 'min:1', 'max:'.self::MAX_AMOUNT],
            'from_sub_account_id' => ['required', $unit->subAccountExistsRule()],
            'to_sub_account_id' => ['required', $unit->subAccountExistsRule()],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        [$month, $day] = $this->parseDateInput($this->date_input);

        if (! checkdate($month, $day, $this->fiscalYearYear)) {
            $this->addError('date_input', __('transactions.transfer_form.errors.invalid_date'));

            return;
        }

        if (! $this->isAllowedSubAccountId($this->from_sub_account_id)) {
            $this->addError('from_sub_account_id', __('transactions.transfer_form.errors.invalid_transfer_account'));

            return;
        }

        if (! $this->isAllowedSubAccountId($this->to_sub_account_id)) {
            $this->addError('to_sub_account_id', __('transactions.transfer_form.errors.invalid_transfer_account'));

            return;
        }

        if ($this->from_sub_account_id === $this->to_sub_account_id) {
            $message = __('transactions.transfer_form.errors.same_account');
            $this->addError('from_sub_account_id', $message);
            $this->addError('to_sub_account_id', $message);

            return;
        }

        $date = sprintf('%04d-%02d-%02d', $this->fiscalYearYear, $month, $day);
        $transaction = Transaction::findOrFail($this->transactionId);

        try {
            app(TransactionRevisor::class)->reviseSinglePair(
                $transaction,
                $user,
                [
                    'revision_reason' => __('transactions.transfer_form.messages.revision_reason'),
                    'date' => $date,
                    'description' => $this->resolveDescription(),
                    'debit_sub_account_id' => (int) $this->to_sub_account_id,
                    'credit_sub_account_id' => (int) $this->from_sub_account_id,
                    'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                    'gross_amount' => (int) $this->amount,
                ],
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            session()->flash('error', __('transactions.transfer_form.errors.update_failed').': '.$e->getMessage());

            return;
        }

        session()->flash('message', __('transactions.transfer_form.messages.updated'));
        $this->dispatch('transaction-edit-finished', transactionId: $this->transactionId);
        $this->dispatch('dashboard-transaction-created');
    }

    public function cancel(): void
    {
        $this->dispatch('transaction-edit-cancelled', transactionId: $this->transactionId);
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
            return $display.' '.__('transactions.transfer_form.actions.update_suffix');
        }

        return __('transactions.transfer_form.actions.update');
    }

    public function amountInputInvalid(): bool
    {
        return $this->hasInvalidAmountInput($this->amount, max: self::MAX_AMOUNT);
    }

    /**
     * @return Collection<int, array{sub_account_id:int,label:string}>
     */
    #[Computed]
    public function accountOptions(): Collection
    {
        $unit = auth()->user()->selectedBusinessUnitOrFail();

        return $unit->accounts()
            ->with(['subAccounts' => fn ($query) => $query
                ->where('visibility', '!=', SubAccount::VISIBILITY_HIDDEN)
                ->where(fn ($subQuery) => $subQuery
                    ->whereNull('system_purpose')
                    ->orWhere('system_purpose', '!=', SubAccount::PURPOSE_HOUSEHOLD_ALLOCATION))
                ->where('name', '!=', '源泉徴収')])
            ->whereIn('name', Standard::allowedTransferAccountNames())
            ->orderByRaw(
                "CASE name
                    WHEN '現金' THEN 0
                    WHEN '普通預金' THEN 1
                    WHEN 'その他の預金' THEN 1
                    WHEN '事業主借' THEN 2
                    WHEN '事業主貸' THEN 3
                    ELSE 99
                END"
            )
            ->get()
            ->flatMap(fn ($account) => $account->subAccounts->map(function (SubAccount $subAccount) use ($account): SubAccount {
                $subAccount->setRelation('account', $account);

                return $subAccount;
            }))
            ->map(fn (SubAccount $subAccount): array => [
                'sub_account_id' => $subAccount->id,
                'label' => $subAccount->displayName(),
            ])
            ->values();
    }

    public function render()
    {
        return view('livewire.soler-ui.transaction-entry.transfer-form.edit');
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

        $fromLabel = $this->findOptionLabel($this->from_sub_account_id) ?? '移動元';
        $toLabel = $this->findOptionLabel($this->to_sub_account_id) ?? '移動先';

        return $fromLabel.' → '.$toLabel.' 振替';
    }

    protected function isAllowedSubAccountId(?int $subAccountId): bool
    {
        if ($subAccountId === null) {
            return false;
        }

        return $this->accountOptions->contains(
            fn (array $option): bool => $option['sub_account_id'] === $subAccountId,
        );
    }

    protected function findOptionLabel(?int $subAccountId): ?string
    {
        if ($subAccountId === null) {
            return null;
        }

        return $this->accountOptions
            ->first(fn (array $option): bool => $option['sub_account_id'] === $subAccountId)['label'] ?? null;
    }
}
