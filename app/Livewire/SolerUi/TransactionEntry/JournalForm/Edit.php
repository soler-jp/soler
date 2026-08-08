<?php

namespace App\Livewire\SolerUi\TransactionEntry\JournalForm;

use App\Livewire\SolerUi\TransactionEntry\Concerns\FormatsJapaneseAmount;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Services\TransactionRevisor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Livewire\Component;

class Edit extends Component
{
    use FormatsJapaneseAmount;

    private const MAX_AMOUNT = 100000000;

    public int $transactionId = 0;

    public string $date_input = '';

    public string $description = '';

    public string $counterparty_name = '';

    public string $revision_reason = '';

    /**
     * 各行: ['type', 'sub_account_id', 'gross_amount', 'tax_type']
     *
     * DB 上の生の journal_entry を1対1で表示・編集する。ratio や家事按分の
     * 派生ロジックはこの UI 側では扱わない（利用者が明示的に行を編集する）。
     *
     * @var array<int, array{type:string, sub_account_id:?int, gross_amount:mixed, tax_type:string}>
     */
    public array $entries = [];

    public int $fiscalYearYear = 0;

    public bool $isTaxable = false;

    public function mount(int $transactionId): void
    {
        $user = auth()->user();
        $unit = $user->selectedBusinessUnitOrFail();

        $transaction = Transaction::with(['journalEntries.subAccount', 'counterparty', 'fiscalYear'])
            ->findOrFail($transactionId);

        // 他事業体の transaction を触れないように actor でガード。
        $transactionUnitId = $transaction->fiscalYear?->business_unit_id;
        if ($transactionUnitId === null || (int) $transactionUnitId !== (int) $unit->id) {
            throw new AuthorizationException('この取引を修正する権限がありません。');
        }

        if (! $transaction->isRevisable()) {
            throw new \InvalidArgumentException(
                $transaction->revisionBlockedReason() ?? 'この取引は修正できません。',
            );
        }

        $this->transactionId = $transactionId;
        $this->fiscalYearYear = (int) $transaction->fiscalYear->year;
        $this->isTaxable = (bool) $transaction->fiscalYear->is_taxable;

        $this->date_input = $transaction->date?->format('md') ?? now()->format('md');
        $this->description = (string) ($transaction->description ?? '');
        $this->counterparty_name = (string) ($transaction->counterparty?->name ?? '');

        $this->entries = $transaction->journalEntries
            ->map(fn (JournalEntry $entry): array => [
                'type' => $entry->type,
                'sub_account_id' => (int) $entry->sub_account_id,
                'gross_amount' => (int) $entry->gross_amount,
                'tax_type' => (string) ($entry->tax_type ?? JournalEntry::TAX_TYPE_OUT_OF_SCOPE),
            ])
            ->values()
            ->all();
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

    public function isBalanced(): bool
    {
        return $this->debitTotal() === $this->creditTotal() && $this->debitTotal() > 0;
    }

    public function cancel(): void
    {
        $this->dispatch('journal-form-edit-cancelled', transactionId: $this->transactionId);
    }

    public function submit(): void
    {
        $user = auth()->user();
        $unit = $user->selectedBusinessUnitOrFail();

        $subAccountRule = $unit->selectableSubAccountExistsRule();

        $this->validate([
            'date_input' => ['required', 'string', 'regex:/^\d{3,4}$/'],
            'description' => ['nullable', 'string', 'max:255'],
            'counterparty_name' => ['nullable', 'string', 'max:255'],
            'revision_reason' => ['required', 'string', 'max:255'],
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

        $transactionOverrides = [
            'revision_reason' => trim($this->revision_reason),
            'date' => $date,
            'description' => trim($this->description) !== '' ? trim($this->description) : null,
        ];

        $counterpartyName = trim($this->counterparty_name);
        if ($counterpartyName !== '') {
            $transactionOverrides['counterparty_name'] = $counterpartyName;
        } else {
            // 空文字なら取引先クリア（counterparty_id を null に）
            $transactionOverrides['counterparty_id'] = null;
        }

        $journalEntriesData = array_map(fn (array $entry): array => [
            'sub_account_id' => (int) $entry['sub_account_id'],
            'type' => $entry['type'],
            'gross_amount' => (int) $entry['gross_amount'],
            'tax_type' => $entry['tax_type'],
        ], $this->entries);

        $transaction = Transaction::findOrFail($this->transactionId);

        try {
            $revised = app(TransactionRevisor::class)->revise(
                $transaction,
                $user,
                [
                    'transaction' => $transactionOverrides,
                    'journal_entries' => $journalEntriesData,
                ],
            );

            $this->dispatch(
                'journal-form-edit-saved',
                originalTransactionId: $this->transactionId,
                revisedTransactionId: $revised->id,
            );
            $this->dispatch('dashboard-transaction-created');

            session()->flash('message', __('transactions.journal_form.messages.revised'));
        } catch (\Exception $e) {
            session()->flash('error', __('transactions.journal_form.errors.revision_failed').': '.$e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.soler-ui.transaction-entry.journal-form.edit', [
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
            'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
        ];
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
