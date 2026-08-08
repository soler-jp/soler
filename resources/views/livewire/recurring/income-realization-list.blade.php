<div>
    <x-ui.card>
        <x-ui.card-header>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <span>{{ __('recurring_income_realizations.title') }}</span>
                <div class="grid w-full max-w-sm grid-cols-2 gap-0 overflow-hidden rounded-control border border-line bg-surface-muted sm:w-auto">
                    @foreach (['gross' => __('recurring_income_realizations.options.input_mode.gross_full'), 'net_tax' => __('recurring_income_realizations.options.input_mode.net_tax_full')] as $inputMode => $label)
                        <button
                            type="button"
                            wire:click="$set('inputMode', '{{ $inputMode }}')"
                            @class([
                                'px-3 py-1.5 text-xs font-semibold transition',
                                'bg-surface text-content shadow-card' => $inputMode === $this->inputMode,
                                'bg-surface-muted text-content-muted hover:bg-surface hover:text-content' => $inputMode !== $this->inputMode,
                                'border-l border-line' => $inputMode === 'net_tax',
                            ])
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>
        </x-ui.card-header>

        <div class="space-y-4 px-4 py-4">
            @if ($noticeMessage)
                <p class="rounded-card border border-status-success-border bg-status-success px-4 py-3 text-sm text-status-success-fg">
                    {{ $noticeMessage }}
                </p>
            @endif

            @if ($errorMessage)
                <p class="rounded-card border border-status-danger-border bg-status-danger px-4 py-3 text-sm text-status-danger-fg">
                    {{ $errorMessage }}
                </p>
            @endif

            @if ($plans->isEmpty())
                <p class="text-sm text-content-muted">
                    {{ __('recurring_income_realizations.empty_plans') }}
                </p>
            @else
                <x-ui.tab-list variant="connected" class="w-full">
                    @foreach ($plans as $plan)
                        <x-ui.tab
                            variant="connected"
                            :active="$selectedPlan && $selectedPlan->id === $plan->id"
                            wire:click="selectPlan({{ $plan->id }})"
                        >
                            <span class="flex flex-col items-start">
                                <span>{{ $plan->counterparty?->name ?? __('recurring_income_realizations.no_counterparty') }}</span>
                                <span class="text-xs font-medium opacity-80">{{ $plan->name }}</span>
                            </span>
                        </x-ui.tab>
                    @endforeach
                </x-ui.tab-list>

                @if ($transactions->isEmpty())
                    <p class="text-sm text-content-muted">
                        {{ __('recurring_income_realizations.empty_transactions') }}
                    </p>
                @else
                    <div class="overflow-hidden rounded-card border border-line">
                        @foreach ($transactions as $transaction)
                            <div wire:key="income-plan-tx-{{ $transaction->id }}" class="grid gap-0 border-t border-line bg-surface first:border-t-0 md:grid-cols-[9rem_minmax(0,1fr)]">
                                <div class="border-b border-line bg-surface-muted px-4 py-4 md:border-b-0 md:border-r">
                                    <p class="text-sm font-semibold text-content">
                                        {{ $periodLabels[$transaction->id] ?? '' }}
                                    </p>
                                </div>

                                <div class="px-4 py-4">
                                    @if ($transaction->is_planned)
                                        <form wire:submit.prevent="realize({{ $transaction->id }})" class="space-y-4">
                                            <div class="grid gap-4 lg:grid-cols-[minmax(11rem,14rem)_minmax(10rem,12rem)_minmax(10rem,12rem)_minmax(9rem,10rem)_minmax(10rem,12rem)]">
                                                <div>
                                                    <label class="mb-1 block text-sm font-semibold text-content">
                                                        {{ __('recurring_income_realizations.fields.receipt_date') }}
                                                    </label>
                                                    <x-ui.input
                                                        type="date"
                                                        wire:model.live="inputs.{{ $transaction->id }}.receipt_date"
                                                    />
                                                    @error("inputs.{$transaction->id}.receipt_date")
                                                        <p class="mt-1 text-xs text-status-danger-fg">{{ $message }}</p>
                                                    @enderror
                                                </div>

                                                @if ($this->inputMode === 'gross')
                                                    <div>
                                                        <label class="mb-1 block text-sm font-semibold text-content">
                                                            {{ __('recurring_income_realizations.fields.amount') }}
                                                        </label>
                                                        <x-ui.input
                                                            type="text"
                                                            inputmode="numeric"
                                                            pattern="\d*"
                                                            wire:model.live="inputs.{{ $transaction->id }}.amount"
                                                        />
                                                        @error("inputs.{$transaction->id}.amount")
                                                            <p class="mt-1 text-xs text-status-danger-fg">{{ $message }}</p>
                                                        @enderror
                                                    </div>
                                                @else
                                                    <div>
                                                        <label class="mb-1 block text-sm font-semibold text-content">
                                                            {{ __('recurring_income_realizations.fields.net_amount') }}
                                                        </label>
                                                        <x-ui.input
                                                            type="text"
                                                            inputmode="numeric"
                                                            pattern="\d*"
                                                            wire:model.live="inputs.{{ $transaction->id }}.net_amount"
                                                        />
                                                        @error("inputs.{$transaction->id}.net_amount")
                                                            <p class="mt-1 text-xs text-status-danger-fg">{{ $message }}</p>
                                                        @enderror
                                                    </div>

                                                    <div>
                                                        <label class="mb-1 block text-sm font-semibold text-content">
                                                            {{ __('recurring_income_realizations.fields.tax_amount') }}
                                                        </label>
                                                        <x-ui.input
                                                            type="text"
                                                            inputmode="numeric"
                                                            pattern="\d*"
                                                            wire:model.live="inputs.{{ $transaction->id }}.tax_amount"
                                                        />
                                                        @error("inputs.{$transaction->id}.tax_amount")
                                                            <p class="mt-1 text-xs text-status-danger-fg">{{ $message }}</p>
                                                        @enderror
                                                    </div>
                                                @endif

                                                @if (auth()->user()->selectedBusinessUnitOrFail()->currentFiscalYear?->is_taxable && $this->inputMode === 'gross')
                                                    <div>
                                                        <label class="mb-1 block text-sm font-semibold text-content">
                                                            {{ __('recurring_income_realizations.fields.tax_rate') }}
                                                        </label>
                                                        <div class="flex flex-wrap gap-2">
                                                            @foreach (['8', '10'] as $taxOption)
                                                                <x-ui.button
                                                                    type="button"
                                                                    variant="{{ data_get($inputs, $transaction->id . '.tax_option') === $taxOption ? 'primary' : 'secondary' }}"
                                                                    wire:click="$set('inputs.{{ $transaction->id }}.tax_option', '{{ $taxOption }}')"
                                                                >
                                                                    {{ $taxOption }}%
                                                                </x-ui.button>
                                                            @endforeach
                                                        </div>
                                                        @error("inputs.{$transaction->id}.tax_option")
                                                            <p class="mt-1 text-xs text-status-danger-fg">{{ $message }}</p>
                                                        @enderror
                                                    </div>
                                                @endif

                                                <div>
                                                    <label class="mb-1 block text-sm font-semibold text-content">
                                                        {{ __('recurring_income_realizations.fields.withholding_tax_amount') }}
                                                    </label>
                                                    <x-ui.input
                                                        type="text"
                                                        inputmode="numeric"
                                                        pattern="\d*"
                                                        wire:model.live="inputs.{{ $transaction->id }}.withholding_tax_amount"
                                                    />
                                                    @error("inputs.{$transaction->id}.withholding_tax_amount")
                                                        <p class="mt-1 text-xs text-status-danger-fg">{{ $message }}</p>
                                                    @enderror
                                                </div>
                                            </div>

                                            <div>
                                                <label class="mb-1 block text-sm font-semibold text-content">
                                                    {{ __('recurring_income_realizations.fields.receipt_sub_account') }}
                                                </label>
                                                <div class="flex flex-wrap gap-2">
                                                    @foreach ($receiptStandardSubAccounts as $subAccount)
                                                        <x-ui.button
                                                            type="button"
                                                            variant="{{ data_get($inputs, $transaction->id . '.receipt_sub_account_id') === $subAccount->id ? 'primary' : 'secondary' }}"
                                                            wire:click="$set('inputs.{{ $transaction->id }}.receipt_sub_account_id', {{ $subAccount->id }})"
                                                        >
                                                            {{ $subAccount->displayName() }}
                                                        </x-ui.button>
                                                    @endforeach

                                                    @foreach ($receiptOwnerDrawSubAccounts as $subAccount)
                                                        <x-ui.button
                                                            type="button"
                                                            variant="{{ data_get($inputs, $transaction->id . '.receipt_sub_account_id') === $subAccount->id ? 'primary' : 'secondary' }}"
                                                            wire:click="$set('inputs.{{ $transaction->id }}.receipt_sub_account_id', {{ $subAccount->id }})"
                                                        >
                                                            {{ $subAccount->displayName() }}
                                                        </x-ui.button>
                                                    @endforeach

                                                    @foreach ($receiptSpecialSubAccounts as $subAccount)
                                                        <x-ui.button
                                                            type="button"
                                                            variant="{{ data_get($inputs, $transaction->id . '.receipt_sub_account_id') === $subAccount->id ? 'primary' : 'secondary' }}"
                                                            wire:click="$set('inputs.{{ $transaction->id }}.receipt_sub_account_id', {{ $subAccount->id }})"
                                                        >
                                                            {{ $subAccount->displayName() }}
                                                        </x-ui.button>
                                                    @endforeach
                                                </div>
                                                @error("inputs.{$transaction->id}.receipt_sub_account_id")
                                                    <p class="mt-1 text-xs text-status-danger-fg">{{ $message }}</p>
                                                @enderror
                                            </div>

                                            @if (($previewErrorMessages[$transaction->id] ?? null) !== null)
                                                <div class="rounded-card border border-status-danger-border bg-status-danger px-4 py-3">
                                                    <p class="text-sm text-status-danger-fg">
                                                        {{ $previewErrorMessages[$transaction->id] }}
                                                    </p>
                                                </div>
                                            @elseif (! empty($previewMessages[$transaction->id] ?? []))
                                                <div class="rounded-card border border-status-info-border bg-status-info px-4 py-3">
                                                    @foreach ($previewMessages[$transaction->id] as $message)
                                                        <p class="text-sm text-status-info-fg">
                                                            {{ $message }}
                                                        </p>
                                                    @endforeach
                                                </div>
                                            @endif

                                            <div class="flex justify-end">
                                                <x-ui.button
                                                    type="submit"
                                                    variant="confirm"
                                                    wire:loading.attr="disabled"
                                                    wire:target="realize({{ $transaction->id }})"
                                                >
                                                    <span wire:loading.remove wire:target="realize({{ $transaction->id }})">
                                                        {{ __('recurring_income_realizations.actions.submit') }}
                                                    </span>
                                                    <span wire:loading wire:target="realize({{ $transaction->id }})">
                                                        {{ __('recurring_income_realizations.actions.submitting') }}
                                                    </span>
                                                </x-ui.button>
                                            </div>
                                        </form>
                                    @else
                                        <div class="space-y-2">
                                            <p class="text-sm text-content">
                                                {{ $realizedMessages[$transaction->id] ?? __('recurring_income_realizations.realized_default') }}
                                            </p>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>
    </x-ui.card>
</div>
