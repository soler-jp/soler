<?php

namespace App\Livewire\SolerUi\TransactionEntry\RevenueForm;

use App\Livewire\SolerUi\TransactionEntry\Concerns\FormatsJapaneseAmount;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\SubAccount;
use App\Services\TransactionRegistrar;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Standard extends Component
{
    use FormatsJapaneseAmount;

    private const MAX_AMOUNT = 10000000;

    public const TAX_OPTION_10 = '10';

    public const TAX_OPTION_8 = '8';

    public const TAX_OPTION_EXEMPT = 'exempt';

    public const TAX_OPTIONS = [
        self::TAX_OPTION_10,
        self::TAX_OPTION_8,
        self::TAX_OPTION_EXEMPT,
    ];

    public string $date_input = '';

    public $amount = null;

    public string $tax_option = self::TAX_OPTION_10;

    public string $note = '';

    public string $counterparty_name = '';

    public ?int $revenue_sub_account_id = null;

    public ?int $receipt_sub_account_id = null;

    public ?int $withheld_tax_sub_account_id = null;

    public bool $withholding = false;

    public ?int $withholding_amount = null;

    public bool $confirming = false;

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

        // 売上の種類は固定表示しないため、常に先頭を自動選択する
        $revenueSubAccounts = $unit->getAccountByName('売上高')->subAccounts;
        $this->revenue_sub_account_id = $revenueSubAccounts->first()?->id;

        $this->withheld_tax_sub_account_id = $unit->getSubAccountByName('事業主貸', '源泉徴収')->id;

        $cashAccount = $unit->getAccountByName('現金');
        $bankAccount = $unit->getAccountByName('その他の預金');
        $ownerDrawAccount = $unit->getAccountByName('事業主貸');
        $accountsReceivable = $unit->getAccountByName('売掛金');

        $this->receiptStandardSubAccounts = array_merge(
            $this->collectReceiptSubAccounts($cashAccount),
            $this->collectReceiptSubAccounts($bankAccount),
        );

        // 事業主貸から源泉徴収科目(withheld_tax_sub_account_id)は入金先の選択肢に含めない
        $this->receiptOwnerDrawSubAccounts = collect($this->collectReceiptSubAccounts($ownerDrawAccount))
            ->reject(fn (SubAccount $sub) => $sub->id === $this->withheld_tax_sub_account_id)
            ->values()
            ->all();

        $this->receiptSpecialSubAccounts = $this->collectReceiptSubAccounts($accountsReceivable);
    }

    public function submit(): void
    {
        if (! $this->runValidation()) {
            return;
        }

        if ($this->withholding) {
            $this->confirming = true;

            return;
        }

        $this->finalize();
    }

    public function confirm(): void
    {
        if (! $this->confirming) {
            return;
        }

        if (! $this->runValidation()) {
            $this->confirming = false;

            return;
        }

        $this->finalize();
    }

    public function back(): void
    {
        $this->confirming = false;
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

        return __('transactions.revenue_form.actions.submit');
    }

    public function amountInputInvalid(): bool
    {
        return $this->hasInvalidAmountInput($this->amount, max: self::MAX_AMOUNT);
    }

    protected function runValidation(): bool
    {
        $user = auth()->user();
        $unit = $user->selectedBusinessUnitOrFail();

        $this->validate([
            'date_input' => ['required', 'string', 'regex:/^\d{3,4}$/'],
            'amount' => ['required', 'integer', 'min:1', 'max:'.self::MAX_AMOUNT],
            'tax_option' => ['required', 'in:'.implode(',', self::TAX_OPTIONS)],
            'revenue_sub_account_id' => ['required', $unit->subAccountExistsRule()],
            'receipt_sub_account_id' => ['required', $unit->subAccountExistsRule()],
            'note' => ['nullable', 'string', 'max:255'],
            'counterparty_name' => ['nullable', 'string', 'max:255'],
            'withholding' => ['boolean'],
            'withholding_amount' => ['nullable', 'integer', 'min:1'],
        ]);

        [$month, $day] = $this->parseDateInput($this->date_input);

        if (! checkdate($month, $day, $this->fiscalYearYear)) {
            $this->addError('date_input', __('transactions.revenue_form.errors.invalid_date'));

            return false;
        }

        if ($this->withholding && (int) $this->withholding_amount <= 0) {
            $this->addError('withholding_amount', '源泉徴収額を入力してください。');

            return false;
        }

        if ($this->withholding && (int) $this->withholding_amount >= (int) $this->amount) {
            $this->addError('withholding_amount', '源泉徴収額は売上金額より小さい必要があります。');

            return false;
        }

        return true;
    }

    protected function finalize(): void
    {
        $user = auth()->user();
        $unit = $user->selectedBusinessUnitOrFail();
        $fiscalYear = $unit->currentFiscalYear;

        [$month, $day] = $this->parseDateInput($this->date_input);
        $date = sprintf('%04d-%02d-%02d', $this->fiscalYearYear, $month, $day);
        $description = $this->resolveDescription();

        if (! $fiscalYear->is_taxable) {
            $this->tax_option = self::TAX_OPTION_10;
        }

        $creditTaxType = $this->mapCreditTaxType($fiscalYear, $this->tax_option);

        $transactionData = [
            'date' => $date,
            'description' => $description,
        ];

        $counterpartyName = trim($this->counterparty_name);
        if ($counterpartyName !== '') {
            $transactionData['counterparty_name'] = $counterpartyName;
        }

        $journalEntries = [
            [
                'sub_account_id' => $this->revenue_sub_account_id,
                'type' => JournalEntry::TYPE_CREDIT,
                'gross_amount' => (int) $this->amount,
                'tax_type' => $creditTaxType,
            ],
        ];

        if ($this->withholding) {
            $withheldTaxAmount = (int) $this->withholding_amount;

            $journalEntries[] = [
                'sub_account_id' => $this->receipt_sub_account_id,
                'type' => JournalEntry::TYPE_DEBIT,
                'gross_amount' => (int) $this->amount - $withheldTaxAmount,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ];
            $journalEntries[] = [
                'sub_account_id' => $this->withheld_tax_sub_account_id,
                'type' => JournalEntry::TYPE_DEBIT,
                'gross_amount' => $withheldTaxAmount,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ];
        } else {
            $journalEntries[] = [
                'sub_account_id' => $this->receipt_sub_account_id,
                'type' => JournalEntry::TYPE_DEBIT,
                'gross_amount' => (int) $this->amount,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ];
        }

        try {
            app(TransactionRegistrar::class)->register(
                $fiscalYear,
                $transactionData,
                $journalEntries,
                $user,
            );

            $this->dispatch('dashboard-transaction-created');
            $this->reset([
                'note',
                'amount',
                'counterparty_name',
                'receipt_sub_account_id',
                'withholding',
                'withholding_amount',
                'confirming',
            ]);
            session()->flash('message', __('transactions.revenue_form.messages.registered'));
        } catch (\Exception $e) {
            $this->confirming = false;
            session()->flash('error', __('transactions.revenue_form.errors.registration_failed').': '.$e->getMessage());
        }
    }

    #[Computed]
    public function confirmTaxRate(): int
    {
        if (! $this->isTaxable) {
            return 0;
        }

        return match ($this->tax_option) {
            self::TAX_OPTION_10 => 10,
            self::TAX_OPTION_8 => 8,
            default => 0,
        };
    }

    #[Computed]
    public function confirmNetAmount(): int
    {
        $rate = $this->confirmTaxRate();
        $amount = (int) $this->amount;

        if ($rate === 0) {
            return $amount;
        }

        return intdiv($amount * 100, 100 + $rate);
    }

    #[Computed]
    public function confirmTaxAmount(): int
    {
        return (int) $this->amount - $this->confirmNetAmount();
    }

    #[Computed]
    public function confirmWithholdingAmount(): int
    {
        return (int) $this->withholding_amount;
    }

    #[Computed]
    public function confirmSettlementAmount(): int
    {
        return (int) $this->amount - $this->confirmWithholdingAmount();
    }

    #[Computed]
    public function confirmReceiptSubAccount(): ?SubAccount
    {
        if ($this->receipt_sub_account_id === null) {
            return null;
        }

        return SubAccount::with('account')->find($this->receipt_sub_account_id);
    }

    #[Computed]
    public function confirmReceiptLabel(): string
    {
        $sub = $this->confirmReceiptSubAccount();

        return $sub?->displayName() ?? '';
    }

    #[Computed]
    public function confirmDateLabel(): string
    {
        [$month, $day] = $this->parseDateInput($this->date_input);

        if (! checkdate($month, $day, $this->fiscalYearYear)) {
            return '';
        }

        return $month.'/'.$day;
    }

    #[Computed]
    public function confirmSettlementMessage(): string
    {
        $sub = $this->confirmReceiptSubAccount();
        $accountName = $sub?->account?->name;

        $key = match ($accountName) {
            '現金' => 'cash',
            'その他の預金', '普通預金' => 'bank',
            '事業主貸' => 'owner_draw',
            '売掛金' => 'accounts_receivable',
            default => 'default',
        };

        return __('transactions.revenue_form.confirm.settlements.'.$key, [
            'amount' => number_format($this->confirmSettlementAmount()),
            'date' => $this->confirmDateLabel(),
            'receipt' => $this->confirmReceiptLabel(),
        ]);
    }

    public function render()
    {
        return view('livewire.soler-ui.transaction-entry.revenue-form.standard');
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

    /**
     * @return list<SubAccount>
     */
    protected function materializeSubAccounts(?Account $account, ?callable $filter = null): array
    {
        if ($account === null) {
            return [];
        }

        $subs = $account->subAccounts;

        if ($filter !== null) {
            $subs = $subs->filter($filter);
        }

        return $subs->values()->all();
    }

    /**
     * 資産勘定の既定 SubAccount は seed で visibility=expanded になるが、
     * 「勘定科目と同名の namesake は入金先として standard 扱い」にすることで、
     * 既定の 現金/現金・売掛金/売掛金 も選択肢に含める。
     *
     * @return list<SubAccount>
     */
    protected function collectReceiptSubAccounts(?Account $account): array
    {
        if ($account === null) {
            return [];
        }

        return $this->materializeSubAccounts(
            $account,
            fn (SubAccount $sub) => $sub->name === $account->name
                || (
                    $sub->system_purpose === null
                    && $sub->visibility === SubAccount::VISIBILITY_STANDARD
                ),
        );
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
            self::TAX_OPTION_10 => $fiscalYear->is_taxable
                ? JournalEntry::TAX_TYPE_TAXABLE_SALES_10
                : JournalEntry::TAX_TYPE_DEEMED_TAXABLE_SALES_10,
            self::TAX_OPTION_8 => JournalEntry::TAX_TYPE_TAXABLE_SALES_8,
            self::TAX_OPTION_EXEMPT => JournalEntry::TAX_TYPE_EXEMPT,
        };
    }
}
