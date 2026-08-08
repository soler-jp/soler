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
     * 各行: ['type', 'sub_account_id', 'gross_amount', 'tax_type', 'business_ratio']
     *
     * @var array<int, array{type:string, sub_account_id:?int, gross_amount:mixed, tax_type:string, business_ratio:mixed}>
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

    /**
     * business_ratio を受理する SubAccount ID の一覧（費用 Account 配下の SubAccount）。
     *
     * @return array<int, int>
     */
    protected function expenseSubAccountIds(): array
    {
        $expenseAccounts = $this->subAccountsByType()->get(Account::TYPE_EXPENSE);

        if ($expenseAccounts === null) {
            return [];
        }

        return $expenseAccounts
            ->flatMap(fn (Account $account) => $account->subAccounts->pluck('id'))
            ->map(fn ($id) => (int) $id)
            ->all();
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
            'entries.*.business_ratio' => ['nullable', 'integer', 'min:1', 'max:100'],
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

        $expenseSubAccountIds = $this->expenseSubAccountIds();

        foreach ($this->entries as $index => $entry) {
            if (($entry['type'] ?? null) !== JournalEntry::TYPE_DEBIT) {
                continue;
            }

            $ratioRaw = $entry['business_ratio'] ?? null;
            if ($ratioRaw === null || $ratioRaw === '') {
                continue;
            }

            $ratio = (int) $ratioRaw;
            if ($ratio === 100) {
                // 100 は「按分なし」に等しく、費用以外でも実質的な副作用がないのでスルー。
                continue;
            }

            if (! in_array((int) ($entry['sub_account_id'] ?? 0), $expenseSubAccountIds, true)) {
                $this->addError(
                    "entries.$index.business_ratio",
                    __('transactions.journal_form.errors.ratio_only_on_expense_debit'),
                );

                return;
            }
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

        $journalEntriesData = array_map(function (array $entry) use ($expenseSubAccountIds): array {
            $data = [
                'sub_account_id' => (int) $entry['sub_account_id'],
                'type' => $entry['type'],
                'gross_amount' => (int) $entry['gross_amount'],
                'tax_type' => $entry['tax_type'],
            ];

            $allowsBusinessRatio = $entry['type'] === JournalEntry::TYPE_DEBIT
                && in_array((int) $entry['sub_account_id'], $expenseSubAccountIds, true);

            if (
                $allowsBusinessRatio
                && isset($entry['business_ratio'])
                && $entry['business_ratio'] !== ''
                && $entry['business_ratio'] !== null
            ) {
                $data['business_ratio'] = (int) $entry['business_ratio'];
            }

            return $data;
        }, $this->entries);

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
     * @return array<string, mixed>
     */
    protected function makeEmptyEntry(string $type): array
    {
        $entry = [
            'type' => $type,
            'sub_account_id' => null,
            'gross_amount' => null,
            'tax_type' => $this->defaultTaxTypeFor($type),
        ];

        // 事業割合は家事按分の概念で、借方の費用科目でしか意味を持たないので
        // credit 行にはフィールド自体を持たせない。
        if ($type === JournalEntry::TYPE_DEBIT) {
            $entry['business_ratio'] = '100';
        }

        return $entry;
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
