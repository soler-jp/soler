<?php

namespace App\Livewire\Pages;

use App\Models\Account;
use App\Models\BusinessUnit;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\SubAccount;
use App\Services\DepreciationService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

class FixedAssetsIndex extends Component
{
    public const CATEGORY_ADVANCED = 'advanced';

    private const CAR_CATEGORIES = [
        FixedAsset::ASSET_CATEGORY_NEW_STANDARD_CAR,
        FixedAsset::ASSET_CATEGORY_NEW_LIGHT_CAR,
        FixedAsset::ASSET_CATEGORY_USED_STANDARD_CAR,
        FixedAsset::ASSET_CATEGORY_USED_LIGHT_CAR,
    ];

    private const USED_CAR_CATEGORIES = [
        FixedAsset::ASSET_CATEGORY_USED_STANDARD_CAR,
        FixedAsset::ASSET_CATEGORY_USED_LIGHT_CAR,
    ];

    private const DEPRECIABLE_ASSET_ACCOUNT_NAMES = [
        '建物',
        '建物附属設備',
        '機械装置',
        '車両運搬具',
        '工具器具備品',
    ];

    public string $selected_category = FixedAsset::ASSET_CATEGORY_NEW_STANDARD_CAR;

    public bool $confirming = false;

    public string $car_name = '';

    public string $car_acquisition_date = '';

    public string $car_first_registration_date = '';

    public $car_gross_amount = null;

    public $car_tax_amount = null;

    public ?int $car_payment_sub_account_id = null;

    public string $car_transaction_description = '';

    public ?int $adv_asset_sub_account_id = null;

    public ?int $adv_payment_sub_account_id = null;

    public string $adv_name = '';

    public string $adv_acquisition_date = '';

    public $adv_gross_amount = null;

    public $adv_tax_amount = null;

    public $adv_useful_life = null;

    public string $adv_transaction_description = '';

    public ?int $opening_transfer_asset_id = null;

    public function mount(): void
    {
        $fiscalYear = $this->fiscalYear();
        $this->car_acquisition_date = $fiscalYear->start_date->toDateString();
        $this->adv_acquisition_date = $fiscalYear->start_date->toDateString();
    }

    public function setCategory(string $category): void
    {
        if (! in_array($category, $this->allSelectableCategories(), true)) {
            return;
        }

        $this->selected_category = $category;
        $this->confirming = false;
    }

    public function cancelConfirm(): void
    {
        $this->confirming = false;
    }

    public function isCarPresetSelected(): bool
    {
        return in_array($this->selected_category, self::CAR_CATEGORIES, true);
    }

    public function isAdvancedSelected(): bool
    {
        return $this->selected_category === self::CATEGORY_ADVANCED;
    }

    public function isUsedCarSelected(): bool
    {
        return in_array($this->selected_category, self::USED_CAR_CATEGORIES, true);
    }

    /**
     * @return list<string>
     */
    public function selectableCategories(): array
    {
        return self::CAR_CATEGORIES;
    }

    #[Computed]
    public function fixedAssets(): Collection
    {
        $unit = auth()->user()->selectedBusinessUnitOrFail();

        return $unit->fixedAssets()
            ->with('account')
            ->orderByDesc('acquisition_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return Collection<int, Account>
     */
    #[Computed]
    public function paymentAccounts(): Collection
    {
        $unit = auth()->user()->selectedBusinessUnitOrFail();

        return $unit->paymentAccounts(BusinessUnit::PAYMENT_ACCOUNT_PRESET_PAYMENT);
    }

    /**
     * @return Collection<int, Account>
     */
    #[Computed]
    public function assetAccounts(): Collection
    {
        $unit = auth()->user()->selectedBusinessUnitOrFail();

        return $unit->accounts()
            ->with(['subAccounts' => fn ($q) => $q->where('visibility', '!=', SubAccount::VISIBILITY_HIDDEN)])
            ->whereIn('name', self::DEPRECIABLE_ASSET_ACCOUNT_NAMES)
            ->get();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function carPresetSummary(): ?array
    {
        if (! $this->isCarPresetSelected() || $this->car_payment_sub_account_id === null) {
            return null;
        }

        $fiscalYear = $this->fiscalYear();
        $gross = (int) ($this->car_gross_amount ?? 0);
        $tax = (int) ($this->car_tax_amount ?? 0);
        $paymentSub = SubAccount::with('account')->find($this->car_payment_sub_account_id);

        return [
            'category' => $this->selected_category,
            'name' => $this->car_name,
            'acquisition_date' => $this->car_acquisition_date,
            'first_registration_date' => $this->car_first_registration_date !== '' ? $this->car_first_registration_date : null,
            'gross_amount' => $gross,
            'tax_amount' => $tax,
            'taxable_amount' => $gross - $tax,
            'payment_label' => $paymentSub?->displayName(),
            'description' => $this->resolveDescription($this->car_transaction_description, $this->car_name),
            'is_past_acquisition' => $this->isPastAcquisition($this->car_acquisition_date, $fiscalYear),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function advancedSummary(): ?array
    {
        if (! $this->isAdvancedSelected()
            || $this->adv_asset_sub_account_id === null
            || $this->adv_payment_sub_account_id === null
        ) {
            return null;
        }

        $fiscalYear = $this->fiscalYear();
        $gross = (int) ($this->adv_gross_amount ?? 0);
        $tax = (int) ($this->adv_tax_amount ?? 0);
        $assetSub = SubAccount::with('account')->find($this->adv_asset_sub_account_id);
        $paymentSub = SubAccount::with('account')->find($this->adv_payment_sub_account_id);

        return [
            'name' => $this->adv_name,
            'acquisition_date' => $this->adv_acquisition_date,
            'gross_amount' => $gross,
            'tax_amount' => $tax,
            'taxable_amount' => $gross - $tax,
            'useful_life' => (int) ($this->adv_useful_life ?? 0),
            'asset_account_label' => $assetSub?->account?->name
                .($assetSub && $assetSub->name !== $assetSub->account?->name ? ' / '.$assetSub->name : ''),
            'payment_label' => $paymentSub?->displayName(),
            'description' => $this->resolveDescription($this->adv_transaction_description, $this->adv_name),
            'is_past_acquisition' => $this->isPastAcquisition($this->adv_acquisition_date, $fiscalYear),
        ];
    }

    public function confirmCarPreset(): void
    {
        if (! $this->isCarPresetSelected()) {
            return;
        }

        $this->validate($this->carPresetValidationRules());
        $this->confirming = true;
    }

    public function confirmAdvanced(): void
    {
        if (! $this->isAdvancedSelected()) {
            return;
        }

        $this->validate($this->advancedValidationRules());
        $this->confirming = true;
    }

    public function submitCarPreset(): void
    {
        if (! $this->isCarPresetSelected() || ! $this->confirming) {
            return;
        }

        $user = auth()->user();
        $unit = $user->selectedBusinessUnitOrFail();
        $fiscalYear = $unit->currentFiscalYear;

        $this->validate($this->carPresetValidationRules());

        $paymentSubAccount = SubAccount::findOrFail($this->car_payment_sub_account_id);

        $grossAmount = (int) $this->car_gross_amount;
        $taxAmount = (int) ($this->car_tax_amount ?? 0);

        $fixedAssetData = [
            'name' => $this->car_name,
            'acquisition_date' => $this->car_acquisition_date,
            'first_registration_date' => $this->car_first_registration_date !== ''
                ? $this->car_first_registration_date
                : null,
            'taxable_amount' => $grossAmount - $taxAmount,
            'tax_amount' => $taxAmount,
        ];

        $transactionData = [
            'date' => $this->car_acquisition_date,
            'description' => $this->resolveDescription($this->car_transaction_description, $this->car_name),
        ];

        $service = app(DepreciationService::class);
        $allowPast = $this->isPastAcquisition($this->car_acquisition_date, $fiscalYear);

        try {
            match ($this->selected_category) {
                FixedAsset::ASSET_CATEGORY_NEW_STANDARD_CAR => $service->registerNewStandardCar($fiscalYear, $paymentSubAccount, $fixedAssetData, $transactionData, $allowPast),
                FixedAsset::ASSET_CATEGORY_NEW_LIGHT_CAR => $service->registerNewLightCar($fiscalYear, $paymentSubAccount, $fixedAssetData, $transactionData, $allowPast),
                FixedAsset::ASSET_CATEGORY_USED_STANDARD_CAR => $service->registerUsedStandardCar($fiscalYear, $paymentSubAccount, $fixedAssetData, $transactionData, $allowPast),
                FixedAsset::ASSET_CATEGORY_USED_LIGHT_CAR => $service->registerUsedLightCar($fiscalYear, $paymentSubAccount, $fixedAssetData, $transactionData, $allowPast),
            };
        } catch (\Throwable $e) {
            session()->flash('fixed_asset_panel_error', __('fixed_assets.messages.registration_failed').': '.$e->getMessage());
            $this->confirming = false;

            return;
        }

        session()->flash('fixed_asset_panel_message', __('fixed_assets.messages.registered'));
        $this->resetCarForm($fiscalYear->start_date->toDateString());
        $this->confirming = false;
        unset($this->fixedAssets);
        $this->dispatch('dashboard-transaction-created');
    }

    public function submitAdvanced(): void
    {
        if (! $this->isAdvancedSelected() || ! $this->confirming) {
            return;
        }

        $user = auth()->user();
        $unit = $user->selectedBusinessUnitOrFail();
        $fiscalYear = $unit->currentFiscalYear;

        $this->validate($this->advancedValidationRules());

        $assetSubAccount = SubAccount::with('account')->findOrFail($this->adv_asset_sub_account_id);
        $paymentSubAccount = SubAccount::findOrFail($this->adv_payment_sub_account_id);

        $grossAmount = (int) $this->adv_gross_amount;
        $taxAmount = (int) ($this->adv_tax_amount ?? 0);

        $fixedAssetData = [
            'name' => $this->adv_name,
            'asset_category' => $assetSubAccount->account->name,
            'acquisition_date' => $this->adv_acquisition_date,
            'first_registration_date' => null,
            'taxable_amount' => $grossAmount - $taxAmount,
            'tax_amount' => $taxAmount,
            'useful_life' => (int) $this->adv_useful_life,
            'depreciation_method' => FixedAsset::DEPRECIATION_METHOD_STRAIGHT_LINE,
        ];

        $transactionData = [
            'date' => $this->adv_acquisition_date,
            'description' => $this->resolveDescription($this->adv_transaction_description, $this->adv_name),
        ];

        $allowPast = $this->isPastAcquisition($this->adv_acquisition_date, $fiscalYear);

        try {
            app(DepreciationService::class)->registerFixedAsset(
                $fiscalYear,
                $assetSubAccount,
                $paymentSubAccount,
                $fixedAssetData,
                $transactionData,
                $allowPast,
            );
        } catch (\Throwable $e) {
            session()->flash('fixed_asset_panel_error', __('fixed_assets.messages.registration_failed').': '.$e->getMessage());
            $this->confirming = false;

            return;
        }

        session()->flash('fixed_asset_panel_message', __('fixed_assets.messages.registered'));
        $this->resetAdvancedForm($fiscalYear->start_date->toDateString());
        $this->confirming = false;
        unset($this->fixedAssets);
        $this->dispatch('dashboard-transaction-created');
    }

    public function render()
    {
        return view('livewire.pages.fixed-assets-index');
    }

    public function startOpeningTransferConfirm(int $assetId): void
    {
        $unit = auth()->user()->selectedBusinessUnitOrFail();
        $asset = $unit->fixedAssets()->whereKey($assetId)->first();

        if ($asset === null || ! $asset->needsInitialOpeningTransfer($this->fiscalYear())) {
            return;
        }

        $this->opening_transfer_asset_id = $assetId;
    }

    public function cancelOpeningTransferConfirm(): void
    {
        $this->opening_transfer_asset_id = null;
    }

    public function submitOpeningTransfer(): void
    {
        if ($this->opening_transfer_asset_id === null) {
            return;
        }

        $unit = auth()->user()->selectedBusinessUnitOrFail();
        $asset = $unit->fixedAssets()->whereKey($this->opening_transfer_asset_id)->first();
        $fiscalYear = $this->fiscalYear();

        if ($asset === null) {
            $this->opening_transfer_asset_id = null;

            return;
        }

        try {
            app(DepreciationService::class)->registerInitialOpeningTransfer(
                $asset,
                $fiscalYear,
                auth()->user(),
            );
        } catch (\Throwable $e) {
            session()->flash('fixed_asset_panel_error', __('fixed_assets.messages.opening_transfer_failed').': '.$e->getMessage());
            $this->opening_transfer_asset_id = null;

            return;
        }

        session()->flash('fixed_asset_panel_message', __('fixed_assets.messages.opening_transfer_registered'));
        $this->opening_transfer_asset_id = null;
        unset($this->fixedAssets);
        $this->dispatch('dashboard-transaction-created');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function openingTransferConfirmSummary(): ?array
    {
        if ($this->opening_transfer_asset_id === null) {
            return null;
        }

        $unit = auth()->user()->selectedBusinessUnitOrFail();
        $asset = $unit->fixedAssets()->with('account')->whereKey($this->opening_transfer_asset_id)->first();

        if ($asset === null) {
            return null;
        }

        $fiscalYear = $this->fiscalYear();
        $openingBalance = app(DepreciationService::class)->calculateOpeningBalanceFor($asset, $fiscalYear);

        return [
            'asset_id' => $asset->id,
            'asset_name' => $asset->name,
            'account_name' => $asset->account?->name,
            'acquisition_date' => $asset->acquisition_date?->toDateString(),
            'fiscal_year' => $fiscalYear->year,
            'opening_balance' => $openingBalance,
        ];
    }

    public function assetNeedsOpeningTransfer(FixedAsset $asset): bool
    {
        return $asset->needsInitialOpeningTransfer($this->fiscalYear());
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function carPresetValidationRules(): array
    {
        return [
            'car_name' => ['required', 'string', 'max:255'],
            'car_acquisition_date' => ['required', 'date'],
            'car_first_registration_date' => [$this->isUsedCarSelected() ? 'required' : 'nullable', 'date'],
            'car_gross_amount' => ['required', 'integer', 'min:1'],
            'car_tax_amount' => ['nullable', 'integer', 'min:0', 'lt:car_gross_amount'],
            'car_payment_sub_account_id' => ['required', 'integer', Rule::in($this->allowedPaymentSubAccountIds())],
            'car_transaction_description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function advancedValidationRules(): array
    {
        return [
            'adv_asset_sub_account_id' => ['required', 'integer', Rule::in($this->allowedAssetSubAccountIds())],
            'adv_payment_sub_account_id' => ['required', 'integer', Rule::in($this->allowedPaymentSubAccountIds())],
            'adv_name' => ['required', 'string', 'max:255'],
            'adv_acquisition_date' => ['required', 'date'],
            'adv_gross_amount' => ['required', 'integer', 'min:1'],
            'adv_tax_amount' => ['nullable', 'integer', 'min:0', 'lt:adv_gross_amount'],
            'adv_useful_life' => ['required', 'integer', 'min:1'],
            'adv_transaction_description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return list<int>
     */
    private function allowedPaymentSubAccountIds(): array
    {
        return $this->paymentAccounts
            ->flatMap(fn (Account $account) => $account->subAccounts->pluck('id'))
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function allowedAssetSubAccountIds(): array
    {
        return $this->assetAccounts
            ->flatMap(fn (Account $account) => $account->subAccounts->pluck('id'))
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function resolveDescription(string $entered, string $name): string
    {
        if ($entered !== '') {
            return $entered;
        }

        return __('fixed_assets.auto_description', ['name' => $name]);
    }

    private function isPastAcquisition(string $acquisitionDate, FiscalYear $fiscalYear): bool
    {
        return Carbon::parse($acquisitionDate)->lt(Carbon::parse($fiscalYear->start_date));
    }

    /**
     * @return list<string>
     */
    private function allSelectableCategories(): array
    {
        return [...self::CAR_CATEGORIES, self::CATEGORY_ADVANCED];
    }

    private function fiscalYear(): FiscalYear
    {
        return auth()->user()->selectedBusinessUnitOrFail()->currentFiscalYear;
    }

    private function resetCarForm(string $defaultDate): void
    {
        $this->car_name = '';
        $this->car_acquisition_date = $defaultDate;
        $this->car_first_registration_date = '';
        $this->car_gross_amount = null;
        $this->car_tax_amount = null;
        $this->car_payment_sub_account_id = null;
        $this->car_transaction_description = '';
    }

    private function resetAdvancedForm(string $defaultDate): void
    {
        $this->adv_asset_sub_account_id = null;
        $this->adv_payment_sub_account_id = null;
        $this->adv_name = '';
        $this->adv_acquisition_date = $defaultDate;
        $this->adv_gross_amount = null;
        $this->adv_tax_amount = null;
        $this->adv_useful_life = null;
        $this->adv_transaction_description = '';
    }
}
