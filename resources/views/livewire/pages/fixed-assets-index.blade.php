@php
    use App\Livewire\Pages\FixedAssetsIndex;
    use App\Models\FixedAsset;

    $carSummary = $this->carPresetSummary();
    $advSummary = $this->advancedSummary();
    $openingTransferSummary = $this->openingTransferConfirmSummary();
@endphp

<div class="space-y-6">
    <h1 class="text-xl font-semibold text-content">{{ __('fixed_assets.panel.title') }}</h1>

    <div class="space-y-6">

            @if (session()->has('fixed_asset_panel_message'))
                <div class="p-2 rounded-control bg-status-success text-status-success-fg border border-status-success-border text-sm">
                    {{ session('fixed_asset_panel_message') }}
                </div>
            @endif

            @if (session()->has('fixed_asset_panel_error'))
                <div class="p-2 rounded-control bg-status-danger text-status-danger-fg border border-status-danger-border text-sm">
                    {{ session('fixed_asset_panel_error') }}
                </div>
            @endif

            {{-- 期首振替の確認カード --}}
            @if ($openingTransferSummary !== null)
                <div wire:key="opening-transfer-confirm" class="rounded-card border border-status-warning-border bg-status-warning text-status-warning-fg p-4 space-y-3">
                    <h4 class="text-sm font-semibold">{{ __('fixed_assets.opening_transfer.confirm_heading') }}</h4>

                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
                        <div class="flex gap-2">
                            <dt class="text-content-muted w-28 shrink-0">{{ __('fixed_assets.opening_transfer.confirm_labels.asset') }}</dt>
                            <dd class="text-content">{{ $openingTransferSummary['asset_name'] }}</dd>
                        </div>
                        <div class="flex gap-2">
                            <dt class="text-content-muted w-28 shrink-0">{{ __('fixed_assets.opening_transfer.confirm_labels.account') }}</dt>
                            <dd class="text-content">{{ $openingTransferSummary['account_name'] }}</dd>
                        </div>
                        <div class="flex gap-2">
                            <dt class="text-content-muted w-28 shrink-0">{{ __('fixed_assets.opening_transfer.confirm_labels.acquisition_date') }}</dt>
                            <dd class="text-content tabular-nums">{{ $openingTransferSummary['acquisition_date'] }}</dd>
                        </div>
                        <div class="flex gap-2">
                            <dt class="text-content-muted w-28 shrink-0">{{ __('fixed_assets.opening_transfer.confirm_labels.fiscal_year') }}</dt>
                            <dd class="text-content tabular-nums">{{ $openingTransferSummary['fiscal_year'] }}</dd>
                        </div>
                        <div class="flex gap-2 sm:col-span-2">
                            <dt class="text-content-muted w-28 shrink-0">{{ __('fixed_assets.opening_transfer.confirm_labels.opening_balance') }}</dt>
                            <dd class="text-content tabular-nums font-semibold">{{ number_format($openingTransferSummary['opening_balance']) }} {{ __('fixed_assets.units.yen') }}</dd>
                        </div>
                    </dl>

                    <p class="text-xs text-content-muted">{{ __('fixed_assets.opening_transfer.notes.formula') }}</p>
                    <p class="text-xs text-content-muted">{{ __('fixed_assets.opening_transfer.notes.capital') }}</p>

                    <div class="flex justify-end gap-2 pt-2">
                        <x-ui.button variant="secondary" type="button" wire:click="cancelOpeningTransferConfirm">
                            {{ __('fixed_assets.opening_transfer.actions.cancel') }}
                        </x-ui.button>
                        <x-ui.button variant="confirm" type="button" wire:click="submitOpeningTransfer" class="min-w-[10rem]">
                            {{ __('fixed_assets.opening_transfer.actions.submit') }}
                        </x-ui.button>
                    </div>
                </div>
            @endif

            {{-- 固定資産一覧 --}}
            <section class="space-y-2">
                <h3 class="text-sm font-semibold text-content">{{ __('fixed_assets.panel.sections.list') }}</h3>

                @if ($this->fixedAssets->isEmpty())
                    <p class="text-sm text-content-muted">{{ __('fixed_assets.panel.empty') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-left text-content-muted border-b border-line">
                                <tr>
                                    <th class="py-2 pr-3 font-medium">{{ __('fixed_assets.list.columns.name') }}</th>
                                    <th class="py-2 pr-3 font-medium">{{ __('fixed_assets.list.columns.category') }}</th>
                                    <th class="py-2 pr-3 font-medium">{{ __('fixed_assets.list.columns.acquisition_date') }}</th>
                                    <th class="py-2 pr-3 font-medium text-right">{{ __('fixed_assets.list.columns.acquisition_cost') }}</th>
                                    <th class="py-2 pr-3 font-medium text-right">{{ __('fixed_assets.list.columns.useful_life') }}</th>
                                    <th class="py-2 pr-3 font-medium">{{ __('fixed_assets.list.columns.status') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                @foreach ($this->fixedAssets as $asset)
                                    @php $needsOpeningTransfer = $this->assetNeedsOpeningTransfer($asset); @endphp
                                    <tr>
                                        <td class="py-2 pr-3">
                                            <div class="font-medium text-content">{{ $asset->name }}</div>
                                            <div class="text-xs text-content-muted">{{ $asset->account?->name }}</div>
                                        </td>
                                        <td class="py-2 pr-3 text-content">{{ $asset->asset_category }}</td>
                                        <td class="py-2 pr-3 tabular-nums text-content">{{ $asset->acquisition_date?->format('Y-m-d') }}</td>
                                        <td class="py-2 pr-3 text-right tabular-nums text-content">{{ number_format($asset->acquisition_cost) }} {{ __('fixed_assets.units.yen') }}</td>
                                        <td class="py-2 pr-3 text-right tabular-nums text-content">{{ $asset->useful_life }} {{ __('fixed_assets.units.months') }}</td>
                                        <td class="py-2 pr-3">
                                            <div class="flex flex-col gap-1">
                                                @if ($asset->is_disposed)
                                                    <span class="text-xs px-2 py-0.5 rounded-control bg-status-warning text-status-warning-fg border border-status-warning-border">{{ __('fixed_assets.list.status.disposed') }}</span>
                                                @else
                                                    <span class="text-xs px-2 py-0.5 rounded-control bg-status-info text-status-info-fg border border-status-info-border">{{ __('fixed_assets.list.status.in_use') }}</span>
                                                @endif

                                                @if ($needsOpeningTransfer)
                                                    <div class="flex items-center gap-2">
                                                        <span class="text-xs px-2 py-0.5 rounded-control bg-status-warning text-status-warning-fg border border-status-warning-border">
                                                            {{ __('fixed_assets.opening_transfer.not_booked_label') }}
                                                        </span>
                                                        <button type="button"
                                                            wire:click="startOpeningTransferConfirm({{ $asset->id }})"
                                                            class="text-xs px-2 py-0.5 rounded-control bg-action-primary text-action-primary-fg border border-transparent hover:opacity-90"
                                                            title="{{ __('fixed_assets.opening_transfer.prompt') }}">
                                                            {{ __('fixed_assets.opening_transfer.actions.start') }}
                                                        </button>
                                                    </div>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            {{-- 新規登録 --}}
            <section class="rounded-card border border-line bg-surface-muted p-4 space-y-3">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <h3 class="text-sm font-semibold text-content">{{ __('fixed_assets.panel.sections.new') }}</h3>
                    <p class="text-xs text-content-muted">{{ __('fixed_assets.panel.notes.straight_line_only') }}</p>
                </div>

                <div class="flex flex-wrap gap-2">
                    @foreach ($this->selectableCategories() as $category)
                        <button type="button" wire:click="setCategory('{{ $category }}')"
                            @class([
                                'px-3 py-1.5 text-sm font-medium rounded-control border transition',
                                'bg-action-primary text-action-primary-fg border-transparent font-semibold' => $selected_category === $category,
                                'bg-surface text-content border-line hover:bg-surface-muted' => $selected_category !== $category,
                            ])>
                            {{ $category }}
                        </button>
                    @endforeach
                    <button type="button" wire:click="setCategory('{{ FixedAssetsIndex::CATEGORY_ADVANCED }}')"
                        @class([
                            'px-3 py-1.5 text-sm font-medium rounded-control border transition',
                            'bg-action-primary text-action-primary-fg border-transparent font-semibold' => $this->isAdvancedSelected(),
                            'bg-surface text-content border-line hover:bg-surface-muted' => ! $this->isAdvancedSelected(),
                        ])>
                        {{ __('fixed_assets.panel.category_advanced') }}
                    </button>
                </div>

                {{-- 車両プリセット: 入力フェーズ --}}
                @if ($this->isCarPresetSelected() && ! $confirming)
                    <div wire:key="car-preset-form" class="space-y-3">
                        <div class="flex flex-wrap gap-x-4 gap-y-3 items-start">
                            <div class="w-56 grow">
                                <label class="block text-sm font-semibold text-content mb-1">{{ __('fixed_assets.fields.name') }}</label>
                                <input type="text" wire:model.defer="car_name"
                                    class="block w-full px-3 py-2 text-sm bg-surface text-content border border-line rounded-control focus:outline-none focus:ring-2 focus:ring-focus">
                                @error('car_name')
                                    <div class="text-xs text-status-danger-fg mt-1">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="w-40">
                                <label class="block text-sm font-semibold text-content mb-1">{{ __('fixed_assets.fields.acquisition_date') }}</label>
                                <input type="date" wire:model.defer="car_acquisition_date"
                                    class="block w-full px-3 py-2 text-sm bg-surface text-content border border-line rounded-control focus:outline-none focus:ring-2 focus:ring-focus">
                                @error('car_acquisition_date')
                                    <div class="text-xs text-status-danger-fg mt-1">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="w-40">
                                <label class="block text-sm font-semibold text-content mb-1">
                                    {{ __('fixed_assets.fields.first_registration_date') }} @if ($this->isUsedCarSelected())<span class="text-status-danger-fg">*</span>@endif
                                </label>
                                <input type="date" wire:model.defer="car_first_registration_date"
                                    class="block w-full px-3 py-2 text-sm bg-surface text-content border border-line rounded-control focus:outline-none focus:ring-2 focus:ring-focus">
                                @error('car_first_registration_date')
                                    <div class="text-xs text-status-danger-fg mt-1">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="w-40">
                                <label class="block text-sm font-semibold text-content mb-1">{{ __('fixed_assets.fields.gross_amount') }}</label>
                                <input type="text" wire:model.defer="car_gross_amount" inputmode="numeric"
                                    class="block w-full px-3 py-2 text-sm text-right tabular-nums bg-surface text-content border border-line rounded-control focus:outline-none focus:ring-2 focus:ring-focus">
                                @error('car_gross_amount')
                                    <div class="text-xs text-status-danger-fg mt-1">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="w-32">
                                <label class="block text-sm font-semibold text-content mb-1">{{ __('fixed_assets.fields.tax_amount') }}</label>
                                <input type="text" wire:model.defer="car_tax_amount" inputmode="numeric"
                                    class="block w-full px-3 py-2 text-sm text-right tabular-nums bg-surface text-content border border-line rounded-control focus:outline-none focus:ring-2 focus:ring-focus">
                                @error('car_tax_amount')
                                    <div class="text-xs text-status-danger-fg mt-1">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="w-64 grow">
                                <label class="block text-sm font-semibold text-content mb-1">{{ __('fixed_assets.fields.description') }}</label>
                                <input type="text" wire:model.defer="car_transaction_description"
                                    placeholder="{{ __('fixed_assets.placeholders.description') }}"
                                    class="block w-full px-3 py-2 text-sm bg-surface text-content border border-line rounded-control focus:outline-none focus:ring-2 focus:ring-focus">
                            </div>
                        </div>

                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-content">{{ __('fixed_assets.fields.payment_account') }}</label>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($this->paymentAccounts as $account)
                                    @foreach ($account->subAccounts as $subAccount)
                                        <button type="button"
                                            wire:click="$set('car_payment_sub_account_id', {{ $subAccount->id }})"
                                            @class([
                                                'px-3 py-1.5 text-sm font-medium rounded-control border transition',
                                                'bg-action-primary text-action-primary-fg border-transparent font-semibold' =>
                                                    $car_payment_sub_account_id === $subAccount->id,
                                                'bg-surface text-content border-line hover:bg-surface-muted' =>
                                                    $car_payment_sub_account_id !== $subAccount->id,
                                            ])>
                                            {{ $subAccount->displayName() }}
                                        </button>
                                    @endforeach
                                @endforeach
                            </div>
                            @error('car_payment_sub_account_id')
                                <div class="text-xs text-status-danger-fg">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="flex justify-end pt-1">
                            <x-ui.button variant="primary" type="button" wire:click="confirmCarPreset" class="min-w-[10rem]">
                                {{ __('fixed_assets.actions.confirm') }}
                            </x-ui.button>
                        </div>
                    </div>
                @endif

                {{-- その他 (advanced): 入力フェーズ --}}
                @if ($this->isAdvancedSelected() && ! $confirming)
                    <div wire:key="advanced-form" class="space-y-3">
                        <div class="flex flex-wrap gap-x-4 gap-y-3 items-start">
                            <div class="w-56 grow">
                                <label class="block text-sm font-semibold text-content mb-1">{{ __('fixed_assets.fields.name') }}</label>
                                <input type="text" wire:model.defer="adv_name"
                                    class="block w-full px-3 py-2 text-sm bg-surface text-content border border-line rounded-control focus:outline-none focus:ring-2 focus:ring-focus">
                                @error('adv_name')
                                    <div class="text-xs text-status-danger-fg mt-1">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="w-40">
                                <label class="block text-sm font-semibold text-content mb-1">{{ __('fixed_assets.fields.acquisition_date') }}</label>
                                <input type="date" wire:model.defer="adv_acquisition_date"
                                    class="block w-full px-3 py-2 text-sm bg-surface text-content border border-line rounded-control focus:outline-none focus:ring-2 focus:ring-focus">
                                @error('adv_acquisition_date')
                                    <div class="text-xs text-status-danger-fg mt-1">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="w-40">
                                <label class="block text-sm font-semibold text-content mb-1">{{ __('fixed_assets.fields.gross_amount') }}</label>
                                <input type="text" wire:model.defer="adv_gross_amount" inputmode="numeric"
                                    class="block w-full px-3 py-2 text-sm text-right tabular-nums bg-surface text-content border border-line rounded-control focus:outline-none focus:ring-2 focus:ring-focus">
                                @error('adv_gross_amount')
                                    <div class="text-xs text-status-danger-fg mt-1">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="w-32">
                                <label class="block text-sm font-semibold text-content mb-1">{{ __('fixed_assets.fields.tax_amount') }}</label>
                                <input type="text" wire:model.defer="adv_tax_amount" inputmode="numeric"
                                    class="block w-full px-3 py-2 text-sm text-right tabular-nums bg-surface text-content border border-line rounded-control focus:outline-none focus:ring-2 focus:ring-focus">
                                @error('adv_tax_amount')
                                    <div class="text-xs text-status-danger-fg mt-1">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="w-28">
                                <label class="block text-sm font-semibold text-content mb-1">{{ __('fixed_assets.fields.useful_life') }}</label>
                                <input type="text" wire:model.defer="adv_useful_life" inputmode="numeric"
                                    class="block w-full px-3 py-2 text-sm text-right tabular-nums bg-surface text-content border border-line rounded-control focus:outline-none focus:ring-2 focus:ring-focus">
                                @error('adv_useful_life')
                                    <div class="text-xs text-status-danger-fg mt-1">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="w-64 grow">
                                <label class="block text-sm font-semibold text-content mb-1">{{ __('fixed_assets.fields.description') }}</label>
                                <input type="text" wire:model.defer="adv_transaction_description"
                                    placeholder="{{ __('fixed_assets.placeholders.description') }}"
                                    class="block w-full px-3 py-2 text-sm bg-surface text-content border border-line rounded-control focus:outline-none focus:ring-2 focus:ring-focus">
                                @error('adv_transaction_description')
                                    <div class="text-xs text-status-danger-fg mt-1">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-content">{{ __('fixed_assets.fields.asset_account') }}</label>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($this->assetAccounts as $account)
                                    @foreach ($account->subAccounts as $subAccount)
                                        <button type="button"
                                            wire:click="$set('adv_asset_sub_account_id', {{ $subAccount->id }})"
                                            @class([
                                                'px-3 py-1.5 text-sm font-medium rounded-control border transition',
                                                'bg-action-primary text-action-primary-fg border-transparent font-semibold' =>
                                                    $adv_asset_sub_account_id === $subAccount->id,
                                                'bg-surface text-content border-line hover:bg-surface-muted' =>
                                                    $adv_asset_sub_account_id !== $subAccount->id,
                                            ])>
                                            {{ $account->name }}@if ($subAccount->name !== $account->name) / {{ $subAccount->name }}@endif
                                        </button>
                                    @endforeach
                                @endforeach
                            </div>
                            @error('adv_asset_sub_account_id')
                                <div class="text-xs text-status-danger-fg">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-content">{{ __('fixed_assets.fields.payment_account') }}</label>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($this->paymentAccounts as $account)
                                    @foreach ($account->subAccounts as $subAccount)
                                        <button type="button"
                                            wire:click="$set('adv_payment_sub_account_id', {{ $subAccount->id }})"
                                            @class([
                                                'px-3 py-1.5 text-sm font-medium rounded-control border transition',
                                                'bg-action-primary text-action-primary-fg border-transparent font-semibold' =>
                                                    $adv_payment_sub_account_id === $subAccount->id,
                                                'bg-surface text-content border-line hover:bg-surface-muted' =>
                                                    $adv_payment_sub_account_id !== $subAccount->id,
                                            ])>
                                            {{ $subAccount->displayName() }}
                                        </button>
                                    @endforeach
                                @endforeach
                            </div>
                            @error('adv_payment_sub_account_id')
                                <div class="text-xs text-status-danger-fg">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="flex justify-end pt-1">
                            <x-ui.button variant="primary" type="button" wire:click="confirmAdvanced" class="min-w-[10rem]">
                                {{ __('fixed_assets.actions.confirm') }}
                            </x-ui.button>
                        </div>
                    </div>
                @endif

                {{-- 確認フェーズ: 車両プリセット --}}
                @if ($this->isCarPresetSelected() && $confirming && $carSummary !== null)
                    <div wire:key="car-preset-confirm" class="rounded-card border border-line bg-surface p-4 space-y-3">
                        <div class="flex items-center justify-between flex-wrap gap-2">
                            <h4 class="text-sm font-semibold text-content">{{ __('fixed_assets.confirm.heading') }}</h4>
                            @if ($carSummary['is_past_acquisition'])
                                <span class="text-xs px-2 py-0.5 rounded-control bg-status-warning text-status-warning-fg border border-status-warning-border">
                                    {{ __('fixed_assets.confirm.past_acquisition_badge') }}
                                </span>
                            @endif
                        </div>

                        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
                            <div class="flex gap-2">
                                <dt class="text-content-muted w-24 shrink-0">{{ __('fixed_assets.confirm.labels.category') }}</dt>
                                <dd class="text-content">{{ $carSummary['category'] }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-content-muted w-24 shrink-0">{{ __('fixed_assets.confirm.labels.name') }}</dt>
                                <dd class="text-content">{{ $carSummary['name'] }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-content-muted w-24 shrink-0">{{ __('fixed_assets.confirm.labels.acquisition_date') }}</dt>
                                <dd class="text-content tabular-nums">{{ $carSummary['acquisition_date'] }}</dd>
                            </div>
                            @if ($carSummary['first_registration_date'])
                                <div class="flex gap-2">
                                    <dt class="text-content-muted w-24 shrink-0">{{ __('fixed_assets.confirm.labels.first_registration_date') }}</dt>
                                    <dd class="text-content tabular-nums">{{ $carSummary['first_registration_date'] }}</dd>
                                </div>
                            @endif
                            <div class="flex gap-2">
                                <dt class="text-content-muted w-24 shrink-0">{{ __('fixed_assets.confirm.labels.gross_amount') }}</dt>
                                <dd class="text-content tabular-nums">{{ number_format($carSummary['gross_amount']) }} {{ __('fixed_assets.units.yen') }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-content-muted w-24 shrink-0">{{ __('fixed_assets.confirm.labels.tax_amount') }}</dt>
                                <dd class="text-content tabular-nums">{{ number_format($carSummary['tax_amount']) }} {{ __('fixed_assets.units.yen') }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-content-muted w-24 shrink-0">{{ __('fixed_assets.confirm.labels.taxable_amount') }}</dt>
                                <dd class="text-content tabular-nums">{{ number_format($carSummary['taxable_amount']) }} {{ __('fixed_assets.units.yen') }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-content-muted w-24 shrink-0">{{ __('fixed_assets.confirm.labels.payment') }}</dt>
                                <dd class="text-content">{{ $carSummary['payment_label'] }}</dd>
                            </div>
                            <div class="flex gap-2 sm:col-span-2">
                                <dt class="text-content-muted w-24 shrink-0">{{ __('fixed_assets.confirm.labels.description') }}</dt>
                                <dd class="text-content">{{ $carSummary['description'] }}</dd>
                            </div>
                        </dl>

                        <div class="flex justify-end gap-2 pt-2">
                            <x-ui.button variant="secondary" type="button" wire:click="cancelConfirm">
                                {{ __('fixed_assets.actions.back') }}
                            </x-ui.button>
                            <x-ui.button variant="confirm" type="button" wire:click="submitCarPreset" class="min-w-[10rem]">
                                {{ __('fixed_assets.actions.submit') }}
                            </x-ui.button>
                        </div>
                    </div>
                @endif

                {{-- 確認フェーズ: その他 (advanced) --}}
                @if ($this->isAdvancedSelected() && $confirming && $advSummary !== null)
                    <div wire:key="advanced-confirm" class="rounded-card border border-line bg-surface p-4 space-y-3">
                        <div class="flex items-center justify-between flex-wrap gap-2">
                            <h4 class="text-sm font-semibold text-content">{{ __('fixed_assets.confirm.heading') }}</h4>
                            @if ($advSummary['is_past_acquisition'])
                                <span class="text-xs px-2 py-0.5 rounded-control bg-status-warning text-status-warning-fg border border-status-warning-border">
                                    {{ __('fixed_assets.confirm.past_acquisition_badge') }}
                                </span>
                            @endif
                        </div>

                        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 text-sm">
                            <div class="flex gap-2">
                                <dt class="text-content-muted w-24 shrink-0">{{ __('fixed_assets.confirm.labels.name') }}</dt>
                                <dd class="text-content">{{ $advSummary['name'] }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-content-muted w-24 shrink-0">{{ __('fixed_assets.confirm.labels.asset_account') }}</dt>
                                <dd class="text-content">{{ $advSummary['asset_account_label'] }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-content-muted w-24 shrink-0">{{ __('fixed_assets.confirm.labels.payment') }}</dt>
                                <dd class="text-content">{{ $advSummary['payment_label'] }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-content-muted w-24 shrink-0">{{ __('fixed_assets.confirm.labels.acquisition_date') }}</dt>
                                <dd class="text-content tabular-nums">{{ $advSummary['acquisition_date'] }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-content-muted w-24 shrink-0">{{ __('fixed_assets.confirm.labels.gross_amount') }}</dt>
                                <dd class="text-content tabular-nums">{{ number_format($advSummary['gross_amount']) }} {{ __('fixed_assets.units.yen') }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-content-muted w-24 shrink-0">{{ __('fixed_assets.confirm.labels.tax_amount') }}</dt>
                                <dd class="text-content tabular-nums">{{ number_format($advSummary['tax_amount']) }} {{ __('fixed_assets.units.yen') }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-content-muted w-24 shrink-0">{{ __('fixed_assets.confirm.labels.taxable_amount') }}</dt>
                                <dd class="text-content tabular-nums">{{ number_format($advSummary['taxable_amount']) }} {{ __('fixed_assets.units.yen') }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="text-content-muted w-24 shrink-0">{{ __('fixed_assets.confirm.labels.useful_life') }}</dt>
                                <dd class="text-content tabular-nums">{{ $advSummary['useful_life'] }} {{ __('fixed_assets.units.months') }}</dd>
                            </div>
                            <div class="flex gap-2 sm:col-span-2">
                                <dt class="text-content-muted w-24 shrink-0">{{ __('fixed_assets.confirm.labels.description') }}</dt>
                                <dd class="text-content">{{ $advSummary['description'] }}</dd>
                            </div>
                        </dl>

                        <div class="flex justify-end gap-2 pt-2">
                            <x-ui.button variant="secondary" type="button" wire:click="cancelConfirm">
                                {{ __('fixed_assets.actions.back') }}
                            </x-ui.button>
                            <x-ui.button variant="confirm" type="button" wire:click="submitAdvanced" class="min-w-[10rem]">
                                {{ __('fixed_assets.actions.submit') }}
                            </x-ui.button>
                        </div>
                    </div>
                @endif
            </section>
    </div>
</div>
