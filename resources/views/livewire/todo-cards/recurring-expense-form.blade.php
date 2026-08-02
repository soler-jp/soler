@php($planDefinition = $schema['plans'] ?? [])
@php($itemSchema = $planDefinition['item_schema'] ?? [])
@php($creditSourceOptions = $itemSchema['credit_sub_account_id']['options'] ?? [])
@php($intervalOptions = $itemSchema['interval']['options'] ?? [])
@php($taxTypeOptions = $itemSchema['tax_type']['options'] ?? [])
@php($numberInputClass = 'block w-full [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none')

<div class="xl:col-span-2 rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
    <div class="space-y-2">
        @if ($todo->due_on !== null)
            <div class="text-xs font-semibold uppercase tracking-[0.16em] text-amber-700">
                期限 {{ $todo->due_on->format('Y-m-d') }}
            </div>
        @endif

        <h2 class="text-xl font-semibold tracking-tight text-slate-900">{{ $todo->title }}</h2>

        <x-todo-body :body="$todo->body" />
    </div>

    <form wire:submit="submit" class="mt-6 space-y-6">
        <div class="text-sm leading-6 text-slate-700">
            {{ __('recurring_transaction_plans.todo_card.intro') }}
        </div>

        <div class="space-y-5">
            @foreach (($inputs['plans'] ?? []) as $index => $plan)
                @php($isTaxTypeLocked = (bool) ($plan['tax_type_locked'] ?? false))
                @php($interval = $plan['interval'] ?? 'monthly')
                @php($planName = $plan['name'] ?? '')
                @php($shouldRegister = ! array_key_exists('should_register', $plan) || (bool) $plan['should_register'])
                @php($isRentTemplate = ($plan['template_key'] ?? null) === 'rent')
                @php($currentTaxTypeOptions = $isRentTemplate ? [
                    ['value' => \App\Models\JournalEntry::TAX_TYPE_EXEMPT, 'label' => __('recurring_transaction_plans.todo_card.options.tax_type.exempt')],
                    ['value' => \App\Models\JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10, 'label' => __('recurring_transaction_plans.todo_card.options.tax_type.taxable_10')],
                ] : $taxTypeOptions)

                <section wire:key="todo-{{ $todo->id }}-recurring-expense-{{ $index }}"
                    @class([
                        'rounded border border-slate-200 bg-slate-50 p-4 transition-opacity md:p-5',
                        'opacity-45' => ! $shouldRegister,
                    ])>
                    <div class="flex items-start justify-between gap-4">
                        <div class="space-y-1">
                            <h3 class="text-lg font-semibold text-slate-900">{{ $planName }}</h3>
                        </div>

                        <label @class([
                            'inline-flex shrink-0 cursor-pointer items-center rounded-full px-3 py-1.5 text-xs font-semibold transition',
                            'bg-slate-900 text-white' => ! $shouldRegister,
                            'border border-slate-300 bg-white text-slate-700 hover:border-slate-400 hover:text-slate-900' => $shouldRegister,
                        ])>
                            <input type="checkbox"
                                wire:model.live="inputs.plans.{{ $index }}.should_register"
                                class="sr-only">
                            <span>{{ $shouldRegister ? '登録しない' : '登録する' }}</span>
                        </label>
                    </div>

                    <div class="mt-5 space-y-5">
                        <div class="grid gap-5 xl:grid-cols-[max-content_minmax(0,1fr)] xl:items-start">
                            <section class="space-y-2">
                                <x-input-label :value="$itemSchema['credit_sub_account_id']['label'] ?? ''" />

                                <div class="rounded-xl bg-slate-100 p-1">
                                    <div class="space-y-1">
                                        @foreach ($creditSourceOptions as $option)
                                            @php($isSelected = (int) ($plan['credit_sub_account_id'] ?? 0) === (int) $option['value'])

                                            <label @class([
                                                'flex cursor-pointer items-center rounded-lg px-4 py-3 text-sm font-medium transition',
                                                'bg-white text-slate-900 shadow-sm' => $isSelected,
                                                'text-slate-600 hover:text-slate-900' => ! $isSelected,
                                            ])>
                                                <input type="radio"
                                                    wire:model.live="inputs.plans.{{ $index }}.credit_sub_account_id"
                                                    value="{{ $option['value'] }}"
                                                    class="sr-only">
                                                <span>{{ $option['label'] }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>

                                <x-input-error :messages="$errors->get('inputs.plans.'.$index.'.credit_sub_account_id')" class="mt-2" />
                            </section>

                            <div class="min-w-0 space-y-4">
                                <section class="space-y-2">
                                    <x-input-label :value="$itemSchema['amount']['label'] ?? ''" />
                                    <div class="flex flex-wrap items-start gap-3">
                                        <x-text-input type="number"
                                            wire:model="inputs.plans.{{ $index }}.amount"
                                            class="{{ $numberInputClass }} max-w-[112px]"
                                            placeholder="0" />
                                        <p class="min-w-0 flex-1 pt-2 text-sm text-slate-500">{{ $itemSchema['amount']['help'] ?? '' }}</p>
                                    </div>
                                    <x-input-error :messages="$errors->get('inputs.plans.'.$index.'.amount')" class="mt-2" />
                                </section>

                                <section class="space-y-2">
                                    <x-input-label :value="$itemSchema['tax_type']['label'] ?? ''" />

                                    <div class="flex flex-wrap items-start gap-3">
                                        @if ($isTaxTypeLocked)
                                            <div class="overflow-x-auto pb-1">
                                                <div class="inline-flex flex-nowrap rounded-xl bg-slate-100 p-1">
                                                    <span class="flex min-w-[84px] shrink-0 items-center justify-center rounded-lg bg-white px-4 py-2.5 text-sm font-medium text-slate-900 shadow-sm">
                                                        {{ __('recurring_transaction_plans.todo_card.options.tax_type.exempt') }}
                                                    </span>
                                                </div>
                                            </div>
                                        @else
                                            <div class="overflow-x-auto pb-1">
                                                <div class="inline-flex flex-nowrap rounded-xl bg-slate-100 p-1">
                                                    @foreach ($currentTaxTypeOptions as $option)
                                                        @php($isSelected = ($plan['tax_type'] ?? null) === $option['value'])

                                                        <label @class([
                                                            'flex min-w-[84px] shrink-0 cursor-pointer items-center justify-center rounded-lg px-4 py-2.5 text-sm font-medium transition',
                                                            'bg-white text-slate-900 shadow-sm' => $isSelected,
                                                            'text-slate-600 hover:text-slate-900' => ! $isSelected,
                                                        ])>
                                                            <input type="radio"
                                                                wire:model.live="inputs.plans.{{ $index }}.tax_type"
                                                                value="{{ $option['value'] }}"
                                                                class="sr-only">
                                                            <span class="whitespace-nowrap">{{ $option['label'] }}</span>
                                                        </label>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endif

                                        @if ($isRentTemplate)
                                            <div class="min-w-0 flex-1 space-y-1 pt-2 text-sm text-slate-500">
                                                <p>{{ __('recurring_transaction_plans.todo_card.help.rent_tax_type_residential') }}</p>
                                                <p>{{ __('recurring_transaction_plans.todo_card.help.rent_tax_type_business') }}</p>
                                                <a href="{{ __('recurring_transaction_plans.todo_card.help.rent_tax_type_source_url') }}"
                                                    target="_blank" rel="noopener noreferrer"
                                                    class="inline-flex items-center text-sky-700 underline underline-offset-2 transition hover:text-sky-800">
                                                    {{ __('recurring_transaction_plans.todo_card.help.rent_tax_type_source_label') }}
                                                </a>
                                            </div>
                                        @endif
                                    </div>

                                    <x-input-error :messages="$errors->get('inputs.plans.'.$index.'.tax_type')" class="mt-2" />
                                </section>

                                <section class="space-y-2">
                                    <x-input-label :value="$itemSchema['business_ratio']['label'] ?? ''" />
                                    <div class="flex flex-wrap items-start gap-3">
                                        <x-text-input type="number"
                                            wire:model="inputs.plans.{{ $index }}.business_ratio"
                                            class="{{ $numberInputClass }} max-w-[88px]"
                                            placeholder="例: 40" />
                                        <p class="min-w-0 flex-1 pt-2 text-sm text-slate-500">{{ $itemSchema['business_ratio']['help'] ?? '' }}</p>
                                    </div>
                                    <x-input-error :messages="$errors->get('inputs.plans.'.$index.'.business_ratio')" class="mt-2" />
                                </section>

                                <section class="space-y-2">
                                    <div class="flex flex-wrap items-start gap-4">
                                        <div class="space-y-2">
                                            <x-input-label :value="$itemSchema['interval']['label'] ?? ''" />
                                            <div class="overflow-x-auto pb-1">
                                                <div class="inline-flex flex-nowrap rounded-xl bg-slate-100 p-1">
                                                    @foreach ($intervalOptions as $option)
                                                        <label @class([
                                                            'flex min-w-[92px] shrink-0 cursor-pointer items-center justify-center rounded-lg px-4 py-2.5 text-sm font-medium transition',
                                                            'bg-white text-slate-900 shadow-sm' => $interval === $option['value'],
                                                            'text-slate-600 hover:text-slate-900' => $interval !== $option['value'],
                                                        ])>
                                                            <input type="radio"
                                                                wire:model.live="inputs.plans.{{ $index }}.interval"
                                                                value="{{ $option['value'] }}"
                                                                class="sr-only">
                                                            <span class="whitespace-nowrap">{{ $option['label'] }}</span>
                                                        </label>
                                                    @endforeach
                                                </div>
                                            </div>
                                        </div>

                                        @if ($interval === 'monthly')
                                            <div class="space-y-2">
                                                <x-input-label :value="$itemSchema['day_of_month']['label'] ?? ''" />
                                                <x-text-input type="number"
                                                    wire:model="inputs.plans.{{ $index }}.day_of_month"
                                                    class="{{ $numberInputClass }} max-w-[88px]"
                                                    placeholder="例: 27" />
                                                <x-input-error :messages="$errors->get('inputs.plans.'.$index.'.day_of_month')" class="mt-2" />
                                            </div>
                                        @elseif ($interval === 'bimonthly')
                                            <div class="space-y-2">
                                                <x-input-label :value="$itemSchema['start_month_type']['label'] ?? ''" />
                                                <div class="overflow-x-auto pb-1">
                                                    <div class="inline-flex flex-nowrap rounded-xl bg-slate-100 p-1">
                                                        @foreach (['odd' => __('recurring_transaction_plans.todo_card.options.month_type.odd'), 'even' => __('recurring_transaction_plans.todo_card.options.month_type.even')] as $value => $label)
                                                            <label @class([
                                                                'flex min-w-[92px] shrink-0 cursor-pointer items-center justify-center rounded-lg px-4 py-2.5 text-sm font-medium transition',
                                                                'bg-white text-slate-900 shadow-sm' => ($plan['start_month_type'] ?? null) === $value,
                                                                'text-slate-600 hover:text-slate-900' => ($plan['start_month_type'] ?? null) !== $value,
                                                            ])>
                                                                <input type="radio"
                                                                    wire:model.live="inputs.plans.{{ $index }}.start_month_type"
                                                                    value="{{ $value }}"
                                                                    class="sr-only">
                                                                <span class="whitespace-nowrap">{{ $label }}</span>
                                                            </label>
                                                        @endforeach
                                                    </div>
                                                </div>
                                                <x-input-error :messages="$errors->get('inputs.plans.'.$index.'.start_month_type')" class="mt-2" />
                                            </div>

                                            <div class="space-y-2">
                                                <x-input-label :value="$itemSchema['day_of_month']['label'] ?? ''" />
                                                <x-text-input type="number"
                                                    wire:model="inputs.plans.{{ $index }}.day_of_month"
                                                    class="{{ $numberInputClass }} max-w-[88px]"
                                                    placeholder="例: 27" />
                                                <x-input-error :messages="$errors->get('inputs.plans.'.$index.'.day_of_month')" class="mt-2" />
                                            </div>
                                        @elseif ($interval === 'yearly')
                                            <div class="space-y-2">
                                                <x-input-label :value="$itemSchema['month_of_year']['label'] ?? ''" />
                                                <x-text-input type="number"
                                                    wire:model="inputs.plans.{{ $index }}.month_of_year"
                                                    class="{{ $numberInputClass }} max-w-[88px]"
                                                    placeholder="例: 7" />
                                                <x-input-error :messages="$errors->get('inputs.plans.'.$index.'.month_of_year')" class="mt-2" />
                                            </div>

                                            <div class="space-y-2">
                                                <x-input-label :value="$itemSchema['day_of_month']['label'] ?? ''" />
                                                <x-text-input type="number"
                                                    wire:model="inputs.plans.{{ $index }}.day_of_month"
                                                    class="{{ $numberInputClass }} max-w-[88px]"
                                                    placeholder="例: 15" />
                                                <x-input-error :messages="$errors->get('inputs.plans.'.$index.'.day_of_month')" class="mt-2" />
                                            </div>
                                        @endif
                                    </div>

                                    <x-input-error :messages="$errors->get('inputs.plans.'.$index.'.interval')" class="mt-2" />
                                </section>
                            </div>
                        </div>
                </section>
            @endforeach
        </div>

        <div class="flex justify-end pt-2">
            <button type="submit" wire:loading.attr="disabled"
                class="rounded-xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60">
                {{ __('recurring_transaction_plans.todo_card.actions.submit') }}
            </button>
        </div>
    </form>
</div>
