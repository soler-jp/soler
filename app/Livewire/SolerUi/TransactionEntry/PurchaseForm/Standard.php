<?php

namespace App\Livewire\SolerUi\TransactionEntry\PurchaseForm;

use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Services\TransactionRegistrar;
use Illuminate\Support\Collection;
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

    public ?int $purchase_sub_account_id = null;

    public ?int $credit_sub_account_id = null;

    public int $fiscalYearYear = 0;

    public bool $isTaxable = false;

    public Collection $creditAccounts;

    public function mount(): void
    {
        $unit = auth()->user()->selectedBusinessUnitOrFail();
        $fiscalYear = $unit->currentFiscalYear;
        $this->fiscalYearYear = (int) $fiscalYear->year;
        $this->isTaxable = (bool) $fiscalYear->is_taxable;
        $this->tax_option = $this->resolveEffectiveTaxOption($fiscalYear);

        $this->date_input = now()->format('md');
        $this->purchase_sub_account_id = $unit->getAccountByName('仕入金額')->subAccounts()->first()?->id;

        $this->creditAccounts = $unit->accounts()
            ->with('subAccounts')
            ->whereIn('name', ['現金', '普通預金', '事業主借'])
            ->orderByRaw("CASE name WHEN '現金' THEN 0 WHEN '普通預金' THEN 1 WHEN '事業主借' THEN 2 ELSE 3 END")
            ->get();
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
        $effectiveTaxOption = $this->resolveEffectiveTaxOption($fiscalYear);
        $this->tax_option = $effectiveTaxOption;

        $transactionData = [
            'date' => $date,
            'description' => $this->resolveDescription(),
        ];

        $counterpartyName = trim($this->counterparty_name);
        if ($counterpartyName !== '') {
            $transactionData['counterparty_name'] = $counterpartyName;
        }

        try {
            app(TransactionRegistrar::class)->register(
                $fiscalYear,
                $transactionData,
                [
                    [
                        'sub_account_id' => $this->purchase_sub_account_id,
                        'type' => JournalEntry::TYPE_DEBIT,
                        'gross_amount' => (int) $this->amount,
                        'tax_type' => $this->mapDebitTaxType($fiscalYear, $effectiveTaxOption),
                    ],
                    [
                        'sub_account_id' => $this->credit_sub_account_id,
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
                'counterparty_name',
                'credit_sub_account_id',
            ]);
            $this->tax_option = $this->resolveEffectiveTaxOption($fiscalYear);
            session()->flash('message', __('transactions.purchase_form.messages.registered'));
        } catch (\Exception $e) {
            session()->flash('error', __('transactions.purchase_form.errors.registration_failed').': '.$e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.soler-ui.transaction-entry.purchase-form.standard');
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

        return $note !== '' ? $note : '仕入';
    }

    protected function resolveEffectiveTaxOption(FiscalYear $fiscalYear): string
    {
        if (! $fiscalYear->is_taxable) {
            return self::TAX_OPTION_10;
        }

        return $this->tax_option;
    }

    protected function mapDebitTaxType(FiscalYear $fiscalYear, string $option): string
    {
        return match ($option) {
            self::TAX_OPTION_10 => $fiscalYear->is_taxable
                ? JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10
                : JournalEntry::TAX_TYPE_DEEMED_TAXABLE_PURCHASES_10,
            self::TAX_OPTION_8 => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_8,
            self::TAX_OPTION_EXEMPT => JournalEntry::TAX_TYPE_EXEMPT,
        };
    }
}
