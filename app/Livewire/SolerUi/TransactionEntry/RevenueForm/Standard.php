<?php

namespace App\Livewire\SolerUi\TransactionEntry\RevenueForm;

use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\SubAccount;
use App\Services\TransactionRegistrar;
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

    public ?int $amount = null;

    public string $tax_option = self::TAX_OPTION_10;

    public string $note = '';

    public string $counterparty_name = '';

    public ?int $revenue_sub_account_id = null;

    public ?int $receipt_sub_account_id = null;

    public ?int $withheld_tax_sub_account_id = null;

    public bool $withholding = false;

    public ?int $withholding_amount = null;

    public int $fiscalYearYear = 0;

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
        $user = auth()->user();
        $unit = $user->selectedBusinessUnitOrFail();
        $fiscalYear = $unit->currentFiscalYear;

        $this->validate([
            'date_input' => ['required', 'string', 'regex:/^\d{3,4}$/'],
            'amount' => ['required', 'integer', 'min:1', 'max:10000000'],
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

            return;
        }

        if ($this->withholding && (int) $this->withholding_amount <= 0) {
            $this->addError('withholding_amount', '源泉徴収額を入力してください。');

            return;
        }

        if ($this->withholding && (int) $this->withholding_amount >= (int) $this->amount) {
            $this->addError('withholding_amount', '源泉徴収額は売上金額より小さい必要があります。');

            return;
        }

        $date = sprintf('%04d-%02d-%02d', $this->fiscalYearYear, $month, $day);
        $description = $this->resolveDescription();
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
                'net_amount' => (int) $this->amount - $withheldTaxAmount,
            ];
            $journalEntries[] = [
                'sub_account_id' => $this->withheld_tax_sub_account_id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => $withheldTaxAmount,
            ];
        } else {
            $journalEntries[] = [
                'sub_account_id' => $this->receipt_sub_account_id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => (int) $this->amount,
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
            ]);
            $this->tax_option = self::TAX_OPTION_10;
            session()->flash('message', __('transactions.revenue_form.messages.registered'));
        } catch (\Exception $e) {
            session()->flash('error', __('transactions.revenue_form.errors.registration_failed').': '.$e->getMessage());
        }
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
            fn (SubAccount $sub) => $sub->system_purpose === null
                && ($sub->visibility === SubAccount::VISIBILITY_STANDARD
                    || $sub->name === $account->name),
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
