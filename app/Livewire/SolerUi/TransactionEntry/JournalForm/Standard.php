<?php

namespace App\Livewire\SolerUi\TransactionEntry\JournalForm;

use App\Livewire\SolerUi\TransactionEntry\Concerns\FormatsJapaneseAmount;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Services\TransactionRegistrar;
use Illuminate\Support\Collection;
use Livewire\Component;

class Standard extends Component
{
    use FormatsJapaneseAmount;

    private const MAX_AMOUNT = 100000000;

    public string $date_input = '';

    public string $description = '';

    public string $counterparty_name = '';

    /**
     * 各行: ['type', 'sub_account_id', 'gross_amount', 'tax_type']
     *
     * 生の journal_entry データを1対1で扱う raw フォーム。家事按分の按分比率などの
     * 派生ロジックは持たない（必要なら利用者が明示的に行を追加する）。
     *
     * @var array<int, array{type:string, sub_account_id:?int, gross_amount:mixed, tax_type:string}>
     */
    public array $entries = [];

    public int $fiscalYearYear = 0;

    public bool $isTaxable = false;

    public function mount(): void
    {
        $unit = auth()->user()->selectedBusinessUnitOrFail();
        $fiscalYear = $unit->currentFiscalYear;

        $this->fiscalYearYear = (int) $fiscalYear->year;
        $this->isTaxable = (bool) $fiscalYear->is_taxable;

        $this->date_input = now()->format('md');

        $this->entries = [
            $this->makeEmptyEntry(JournalEntry::TYPE_DEBIT),
            $this->makeEmptyEntry(JournalEntry::TYPE_CREDIT),
        ];
    }

    /**
     * @return Collection<string, Collection<int, Account>>
     */
    public function subAccountsByType(): Collection
    {
        return auth()->user()
            ->selectedBusinessUnitOrFail()
            ->selectableSubAccountsGroupedByType();
    }

    public function addDebit(): void
    {
        $this->entries[] = $this->makeEmptyEntry(JournalEntry::TYPE_DEBIT);
    }

    public function addCredit(): void
    {
        $this->entries[] = $this->makeEmptyEntry(JournalEntry::TYPE_CREDIT);
    }

    public function removeEntry(int $index): void
    {
        if (! array_key_exists($index, $this->entries)) {
            return;
        }

        $type = $this->entries[$index]['type'] ?? null;

        $sameTypeCount = collect($this->entries)
            ->filter(fn (array $entry): bool => ($entry['type'] ?? null) === $type)
            ->count();

        if ($sameTypeCount <= 1) {
            return;
        }

        unset($this->entries[$index]);
        $this->entries = array_values($this->entries);
    }

    public function debitTotal(): int
    {
        return $this->sumSide(JournalEntry::TYPE_DEBIT);
    }

    public function creditTotal(): int
    {
        return $this->sumSide(JournalEntry::TYPE_CREDIT);
    }

    public function debitTotalDisplay(): string
    {
        return $this->formatJapaneseAmount($this->debitTotal());
    }

    public function creditTotalDisplay(): string
    {
        return $this->formatJapaneseAmount($this->creditTotal());
    }

    public function isBalanced(): bool
    {
        return $this->debitTotal() === $this->creditTotal() && $this->debitTotal() > 0;
    }

    public function submit(): void
    {
        $user = auth()->user();
        $unit = $user->selectedBusinessUnitOrFail();
        $fiscalYear = $unit->currentFiscalYear;

        $subAccountRule = $unit->selectableSubAccountExistsRule();

        $this->validate([
            'date_input' => ['required', 'string', 'regex:/^\d{3,4}$/'],
            'description' => ['nullable', 'string', 'max:255'],
            'counterparty_name' => ['nullable', 'string', 'max:255'],
            'entries' => ['required', 'array', 'min:2'],
            'entries.*.type' => ['required', 'in:'.JournalEntry::TYPE_DEBIT.','.JournalEntry::TYPE_CREDIT],
            'entries.*.sub_account_id' => ['required', $subAccountRule],
            'entries.*.gross_amount' => ['required', 'integer', 'min:1', 'max:'.self::MAX_AMOUNT],
            'entries.*.tax_type' => ['required', 'in:'.implode(',', JournalEntry::USER_SELECTABLE_TAX_TYPES)],
        ]);

        $debitCount = collect($this->entries)->where('type', JournalEntry::TYPE_DEBIT)->count();
        $creditCount = collect($this->entries)->where('type', JournalEntry::TYPE_CREDIT)->count();

        if ($debitCount < 1 || $creditCount < 1) {
            $this->addError('entries', __('transactions.journal_form.errors.need_debit_and_credit'));

            return;
        }

        [$month, $day] = $this->parseDateInput($this->date_input);

        if (! checkdate($month, $day, $this->fiscalYearYear)) {
            $this->addError('date_input', __('transactions.journal_form.errors.invalid_date'));

            return;
        }

        if ($this->debitTotal() !== $this->creditTotal()) {
            $this->addError('entries', __('transactions.journal_form.errors.unbalanced'));

            return;
        }

        $date = sprintf('%04d-%02d-%02d', $this->fiscalYearYear, $month, $day);

        $transactionData = ['date' => $date];

        $description = trim($this->description);
        if ($description !== '') {
            $transactionData['description'] = $description;
        }

        $counterpartyName = trim($this->counterparty_name);
        if ($counterpartyName !== '') {
            $transactionData['counterparty_name'] = $counterpartyName;
        }

        $journalEntriesData = array_map(fn (array $entry): array => [
            'sub_account_id' => (int) $entry['sub_account_id'],
            'type' => $entry['type'],
            'gross_amount' => (int) $entry['gross_amount'],
            'tax_type' => $entry['tax_type'],
        ], $this->entries);

        try {
            app(TransactionRegistrar::class)->register(
                $fiscalYear,
                $transactionData,
                $journalEntriesData,
                $user,
            );

            $this->dispatch('dashboard-transaction-created');

            $this->entries = [
                $this->makeEmptyEntry(JournalEntry::TYPE_DEBIT),
                $this->makeEmptyEntry(JournalEntry::TYPE_CREDIT),
            ];
            $this->reset(['description', 'counterparty_name']);

            session()->flash('message', __('transactions.journal_form.messages.registered'));
        } catch (\Exception $e) {
            session()->flash('error', __('transactions.journal_form.errors.registration_failed').': '.$e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.soler-ui.transaction-entry.journal-form.standard', [
            'subAccountsByType' => $this->subAccountsByType(),
        ]);
    }

    /**
     * @return array{type:string, sub_account_id:?int, gross_amount:?string, tax_type:string}
     */
    protected function makeEmptyEntry(string $type): array
    {
        return [
            'type' => $type,
            'sub_account_id' => null,
            'gross_amount' => null,
            'tax_type' => $this->defaultTaxTypeFor($type),
        ];
    }

    protected function defaultTaxTypeFor(string $type): string
    {
        return JournalEntry::TAX_TYPE_OUT_OF_SCOPE;
    }

    protected function sumSide(string $type): int
    {
        return collect($this->entries)
            ->filter(fn (array $entry): bool => ($entry['type'] ?? null) === $type)
            ->sum(function (array $entry): int {
                $parsed = $this->parseAmountInput($entry['gross_amount'] ?? null);

                return $parsed ?? 0;
            });
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
}
