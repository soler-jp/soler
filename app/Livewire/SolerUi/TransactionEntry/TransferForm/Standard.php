<?php

namespace App\Livewire\SolerUi\TransactionEntry\TransferForm;

use App\Livewire\SolerUi\TransactionEntry\Concerns\FormatsJapaneseAmount;
use App\Models\JournalEntry;
use App\Models\SubAccount;
use App\Services\TransactionRegistrar;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Standard extends Component
{
    use FormatsJapaneseAmount;

    private const MAX_AMOUNT = 10000000;

    public string $date_input = '';

    public $amount = null;

    public string $note = '';

    public ?int $from_sub_account_id = null;

    public ?int $to_sub_account_id = null;

    public int $fiscalYearYear = 0;

    public function mount(): void
    {
        $unit = auth()->user()->selectedBusinessUnitOrFail();
        $fiscalYear = $unit->currentFiscalYear;

        $this->fiscalYearYear = (int) $fiscalYear->year;
        $this->date_input = now()->format('md');
    }

    public function submit(): void
    {
        $user = auth()->user();
        $unit = $user->selectedBusinessUnitOrFail();
        $fiscalYear = $unit->currentFiscalYear;

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

        if (! $this->isAllowedFromSubAccountId($this->from_sub_account_id)) {
            $this->addError('from_sub_account_id', __('transactions.transfer_form.errors.invalid_transfer_account'));

            return;
        }

        if (! $this->isAllowedToSubAccountId($this->to_sub_account_id)) {
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

        try {
            app(TransactionRegistrar::class)->register(
                $fiscalYear,
                [
                    'date' => $date,
                    'description' => $this->resolveDescription(),
                ],
                [
                    [
                        'sub_account_id' => $this->to_sub_account_id,
                        'type' => JournalEntry::TYPE_DEBIT,
                        'gross_amount' => (int) $this->amount,
                        'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                    ],
                    [
                        'sub_account_id' => $this->from_sub_account_id,
                        'type' => JournalEntry::TYPE_CREDIT,
                        'gross_amount' => (int) $this->amount,
                        'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                    ],
                ],
                $user,
            );

            $this->dispatch('dashboard-transaction-created');
            $this->reset([
                'amount',
                'note',
                'from_sub_account_id',
                'to_sub_account_id',
            ]);
            session()->flash('message', __('transactions.transfer_form.messages.registered'));
        } catch (\Exception $e) {
            session()->flash('error', __('transactions.transfer_form.errors.registration_failed').': '.$e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.soler-ui.transaction-entry.transfer-form.standard');
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
            return $display.' '.__('transactions.shared.submit_suffix');
        }

        return __('transactions.transfer_form.actions.submit');
    }

    public function amountInputInvalid(): bool
    {
        return $this->hasInvalidAmountInput($this->amount, max: self::MAX_AMOUNT);
    }

    /**
     * @return Collection<int, array{sub_account_id:int,label:string}>
     */
    #[Computed]
    public function fromOptions(): Collection
    {
        return $this->transferSubAccounts()
            ->reject(fn (SubAccount $subAccount): bool => $subAccount->account?->name === '事業主貸')
            ->map(fn (SubAccount $subAccount): array => [
                'sub_account_id' => $subAccount->id,
                'label' => $this->resolveFromLabel($subAccount),
            ])
            ->values();
    }

    /**
     * @return Collection<int, array{sub_account_id:int,label:string}>
     */
    #[Computed]
    public function toOptions(): Collection
    {
        return $this->transferSubAccounts()
            ->reject(fn (SubAccount $subAccount): bool => $subAccount->account?->name === '事業主借')
            ->map(fn (SubAccount $subAccount): array => [
                'sub_account_id' => $subAccount->id,
                'label' => $this->resolveToLabel($subAccount),
            ])
            ->values();
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

        $fromLabel = $this->findFromOptionLabel($this->from_sub_account_id) ?? '移動元';
        $toLabel = $this->findToOptionLabel($this->to_sub_account_id) ?? '移動先';

        return $fromLabel.' → '.$toLabel.' 振替';
    }

    protected function isAllowedFromSubAccountId(?int $subAccountId): bool
    {
        if ($subAccountId === null) {
            return false;
        }

        return $this->fromOptions->contains(fn (array $option): bool => $option['sub_account_id'] === $subAccountId);
    }

    protected function isAllowedToSubAccountId(?int $subAccountId): bool
    {
        if ($subAccountId === null) {
            return false;
        }

        return $this->toOptions->contains(fn (array $option): bool => $option['sub_account_id'] === $subAccountId);
    }

    protected function findFromOptionLabel(?int $subAccountId): ?string
    {
        if ($subAccountId === null) {
            return null;
        }

        return $this->fromOptions
            ->first(fn (array $option): bool => $option['sub_account_id'] === $subAccountId)['label'] ?? null;
    }

    protected function findToOptionLabel(?int $subAccountId): ?string
    {
        if ($subAccountId === null) {
            return null;
        }

        return $this->toOptions
            ->first(fn (array $option): bool => $option['sub_account_id'] === $subAccountId)['label'] ?? null;
    }

    /**
     * @return Collection<int, SubAccount>
     */
    protected function transferSubAccounts(): Collection
    {
        $unit = auth()->user()->selectedBusinessUnitOrFail();

        return $unit->accounts()
            ->with(['subAccounts' => fn ($query) => $query
                ->where('visibility', '!=', SubAccount::VISIBILITY_HIDDEN)
                ->where(fn ($subQuery) => $subQuery
                    ->whereNull('system_purpose')
                    ->orWhere('system_purpose', '!=', SubAccount::PURPOSE_HOUSEHOLD_ALLOCATION))
                ->where('name', '!=', '源泉徴収')])
            ->whereIn('name', $this->allowedTransferAccountNames())
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
            ->values();
    }

    /**
     * @return list<string>
     */
    public static function allowedTransferAccountNames(): array
    {
        return ['現金', '普通預金', 'その他の預金', '事業主借', '事業主貸'];
    }

    protected function resolveFromLabel(SubAccount $subAccount): string
    {
        return match ($subAccount->account?->name) {
            '事業主借' => '個人の財布から',
            default => $subAccount->displayName(),
        };
    }

    protected function resolveToLabel(SubAccount $subAccount): string
    {
        return match ($subAccount->account?->name) {
            '事業主貸' => '個人の財布へ',
            default => $subAccount->displayName(),
        };
    }
}
