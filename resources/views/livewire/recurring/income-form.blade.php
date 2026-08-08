<div>
    <x-ui.card>
        <x-ui.card-header>
            {{ __('recurring_income_form.title') }}
        </x-ui.card-header>

        <div class="space-y-4 px-4 py-4">
            @if (session()->has('message'))
                <p class="rounded-card border border-status-success-border bg-status-success px-4 py-3 text-sm text-status-success-fg">
                    {{ session('message') }}
                </p>
            @endif

            @if (! $confirming)
            <form wire:submit="submit" class="space-y-4">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-content">
                            {{ __('recurring_income_form.fields.counterparty_id') }}
                        </label>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($counterparties as $counterparty)
                                <x-ui.button
                                    type="button"
                                    variant="{{ (int) ($form['counterparty_id'] ?? 0) === $counterparty->id ? 'primary' : 'secondary' }}"
                                    wire:click="$set('form.counterparty_id', {{ $counterparty->id }})"
                                >
                                    {{ $counterparty->name }}
                                </x-ui.button>
                            @endforeach
                        </div>
                        <p class="mt-1 text-xs text-content-muted">
                            {{ __('recurring_income_form.help.counterparty_id') }}
                        </p>
                        @error('form.counterparty_id')
                            <p class="mt-1 text-xs text-status-danger-fg">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-content">
                            {{ __('recurring_income_form.fields.name') }}
                        </label>
                        <x-ui.input wire:model="form.name" :placeholder="__('recurring_income_form.placeholders.name')" />
                        @error('form.name')
                            <p class="mt-1 text-xs text-status-danger-fg">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-content">
                            {{ __('recurring_income_form.fields.interval') }}
                        </label>
                        <div class="flex flex-wrap gap-2">
                            <x-ui.button
                                type="button"
                                variant="{{ $form['interval'] === 'monthly' ? 'primary' : 'secondary' }}"
                                wire:click="$set('form.interval', 'monthly')"
                            >
                                {{ __('recurring_income_form.options.interval.monthly') }}
                            </x-ui.button>
                            <x-ui.button
                                type="button"
                                variant="{{ $form['interval'] === 'yearly' ? 'primary' : 'secondary' }}"
                                wire:click="$set('form.interval', 'yearly')"
                            >
                                {{ __('recurring_income_form.options.interval.yearly') }}
                            </x-ui.button>
                        </div>
                        @error('form.interval')
                            <p class="mt-1 text-xs text-status-danger-fg">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-semibold text-content">
                            {{ __('recurring_income_form.fields.debit_sub_account_id') }}
                        </label>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($receiptStandardSubAccounts as $subAccount)
                                <x-ui.button
                                    type="button"
                                    variant="{{ $form['debit_sub_account_id'] == $subAccount->id ? 'primary' : 'secondary' }}"
                                    wire:click="$set('form.debit_sub_account_id', {{ $subAccount->id }})"
                                >
                                    {{ $subAccount->displayName() }}
                                </x-ui.button>
                            @endforeach

                            @foreach ($receiptOwnerDrawSubAccounts as $subAccount)
                                <x-ui.button
                                    type="button"
                                    variant="{{ $form['debit_sub_account_id'] == $subAccount->id ? 'primary' : 'secondary' }}"
                                    wire:click="$set('form.debit_sub_account_id', {{ $subAccount->id }})"
                                >
                                    {{ $subAccount->displayName() }}
                                </x-ui.button>
                            @endforeach
                        </div>
                        @error('form.debit_sub_account_id')
                            <p class="mt-1 text-xs text-status-danger-fg">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                @if ($form['interval'] === 'yearly')
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-semibold text-content">
                                {{ __('recurring_income_form.fields.month_of_year') }}
                            </label>
                            <x-ui.input type="text" inputmode="numeric" pattern="\d*" wire:model.live="form.month_of_year" />
                            @error('form.month_of_year')
                                <p class="mt-1 text-xs text-status-danger-fg">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-semibold text-content">
                                {{ __('recurring_income_form.fields.day_of_month') }}
                            </label>
                            <x-ui.input type="text" inputmode="numeric" pattern="\d*" wire:model.live="form.day_of_month" />
                            @error('form.day_of_month')
                                <p class="mt-1 text-xs text-status-danger-fg">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                @endif

                <div class="flex flex-wrap items-start gap-4">
                    <div class="min-w-[12rem]">
                        <label class="mb-1 block text-sm font-semibold text-content">
                            {{ __('recurring_income_form.fields.gross_amount') }}
                        </label>
                        <x-ui.input type="text" inputmode="numeric" pattern="\d*" wire:model.live="form.gross_amount" />
                        @error('form.gross_amount')
                            <p class="mt-1 text-xs text-status-danger-fg">{{ $message }}</p>
                        @enderror
                    </div>

                    @if (auth()->user()->selectedBusinessUnitOrFail()->currentFiscalYear?->is_taxable)
                        <div class="min-w-[10rem]">
                            <div class="flex min-h-[42px] flex-wrap items-center gap-2 pt-7">
                                <x-ui.button
                                    type="button"
                                    variant="{{ $form['tax_option'] === '10' ? 'primary' : 'secondary' }}"
                                    wire:click="$set('form.tax_option', '10')"
                                >
                                    {{ __('recurring_income_form.options.tax_option.10') }}
                                </x-ui.button>
                                <x-ui.button
                                    type="button"
                                    variant="{{ $form['tax_option'] === '8' ? 'primary' : 'secondary' }}"
                                    wire:click="$set('form.tax_option', '8')"
                                >
                                    {{ __('recurring_income_form.options.tax_option.8') }}
                                </x-ui.button>
                            </div>
                            @error('form.tax_option')
                                <p class="mt-1 text-xs text-status-danger-fg">{{ $message }}</p>
                            @enderror
                        </div>
                    @endif

                    <div class="min-w-[10rem]">
                        <label class="inline-flex min-h-[42px] items-center gap-2 pt-7 text-sm font-semibold text-content">
                            <input type="checkbox" wire:model.live="form.is_withholding" class="rounded border-line text-action-primary focus:ring-focus">
                            {{ __('recurring_income_form.fields.is_withholding') }}
                        </label>
                    </div>

                    @if ($form['is_withholding'])
                        <div class="min-w-[12rem]">
                            <label class="mb-1 block text-sm font-semibold text-content">
                                {{ __('recurring_income_form.fields.withholding_tax_amount') }}
                            </label>
                            <x-ui.input type="text" inputmode="numeric" pattern="\d*" wire:model.live="form.withholding_tax_amount" />
                            @error('form.withholding_tax_amount')
                                <p class="mt-1 text-xs text-status-danger-fg">{{ $message }}</p>
                            @enderror
                        </div>
                    @endif
                </div>

                <div class="flex justify-end">
                    <x-ui.button type="submit" variant="confirm">
                        {{ __('recurring_income_form.actions.review') }}
                    </x-ui.button>
                </div>
            </form>
            @else
            <div class="space-y-4">
                <div class="text-sm font-semibold text-content">
                    {{ __('recurring_income_form.confirm.title') }}
                </div>

                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-content-muted">{{ __('recurring_income_form.fields.counterparty_id') }}</dt>
                        <dd class="text-content">{{ $this->selectedCounterpartyLabel() }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-content-muted">{{ __('recurring_income_form.fields.name') }}</dt>
                        <dd class="text-content">{{ $form['name'] }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-content-muted">{{ __('recurring_income_form.fields.interval') }}</dt>
                        <dd class="text-content">{{ $this->selectedScheduleLabel() }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-content-muted">{{ __('recurring_income_form.fields.debit_sub_account_id') }}</dt>
                        <dd class="text-content">{{ $this->selectedReceiptLabel() }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-content-muted">{{ __('recurring_income_form.fields.gross_amount') }}</dt>
                        <dd class="text-content tabular-nums">{{ $this->grossAmountDisplay() }}</dd>
                    </div>
                    @if (auth()->user()->selectedBusinessUnitOrFail()->currentFiscalYear?->is_taxable)
                        <div class="flex justify-between gap-4">
                            <dt class="text-content-muted">{{ __('recurring_income_form.fields.tax_option') }}</dt>
                            <dd class="text-content">{{ __('recurring_income_form.options.tax_option.' . $form['tax_option']) }}</dd>
                        </div>
                    @endif
                    @if ($form['is_withholding'])
                        <div class="flex justify-between gap-4">
                            <dt class="text-content-muted">{{ __('recurring_income_form.fields.withholding_tax_amount') }}</dt>
                            <dd class="text-content tabular-nums">{{ $this->withholdingAmountDisplay() }}</dd>
                        </div>
                    @endif
                </dl>

                <div class="flex gap-2 pt-1">
                    <x-ui.button type="button" variant="secondary" wire:click="back" class="flex-1">
                        {{ __('recurring_income_form.actions.back') }}
                    </x-ui.button>
                    <x-ui.button type="button" variant="confirm" wire:click="save" class="flex-1">
                        {{ __('recurring_income_form.actions.submit') }}
                    </x-ui.button>
                </div>
            </div>
            @endif
        </div>
    </x-ui.card>
</div>
