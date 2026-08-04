@php($assetAccounts = $inputs['asset_accounts'] ?? [])
@php($liabilityAccounts = $inputs['liability_accounts'] ?? [])
@php($customAssetAccounts = $inputs['custom_asset_accounts'] ?? [])
@php($customLiabilityAccounts = $inputs['custom_liability_accounts'] ?? [])
@php($numberInputClass = '[appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none')
@php($imagePath = asset('images/setup/opening-entry-masked.png'))

<x-ui.card class="xl:col-span-2">
    <x-ui.card-body class="p-6">
        <div class="space-y-2">
            @if ($todo->due_on !== null)
                <div class="text-xs font-semibold uppercase tracking-[0.16em] text-status-warning-fg">
                    {{ __('setup_todos.opening_balance.form.due_on', ['date' => $todo->due_on->format('Y-m-d')]) }}
                </div>
            @endif

            <h2 class="text-xl font-semibold tracking-tight text-content">{{ $todo->title }}</h2>

            <x-todo-body :body="$todo->body" />
        </div>

        <form wire:submit="submit" class="mt-6 space-y-8" x-data="{ previewOpen: false }">
            <p class="max-w-5xl text-sm leading-6 text-content-muted">
                {{ __('setup_todos.opening_balance.form.description') }}
            </p>

            <div
                x-cloak
                x-show="previewOpen"
                x-transition.opacity
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/65 p-4"
                @click.self="previewOpen = false"
                @keydown.escape.window="previewOpen = false"
            >
                <div class="relative max-h-[90vh] max-w-6xl overflow-auto rounded-card bg-surface p-3 shadow-card">
                    <button
                        type="button"
                        @click="previewOpen = false"
                        class="absolute right-3 top-3 rounded-full border border-line bg-surface px-3 py-1 text-sm text-content"
                    >
                        {{ __('common.close') }}
                    </button>

                    <img
                        src="{{ $imagePath }}"
                        alt="{{ __('setup_todos.opening_balance.form.image_alt') }}"
                        class="block h-auto max-h-[85vh] w-auto max-w-full"
                    >
                </div>
            </div>

            <div class="grid gap-6 xl:grid-cols-[minmax(280px,0.9fr)_minmax(320px,1fr)_minmax(320px,1fr)] xl:items-start">
                <section class="space-y-3">
                    <button
                        type="button"
                        @click="previewOpen = true"
                        class="block overflow-hidden rounded-card border border-line bg-canvas text-left transition hover:border-focus"
                    >
                        <img
                            src="{{ $imagePath }}"
                            alt="{{ __('setup_todos.opening_balance.form.image_alt') }}"
                            class="block w-full"
                        >
                    </button>

                    <p class="text-xs text-content-muted">
                        {{ __('setup_todos.opening_balance.form.image_hint') }}
                    </p>
                </section>

                <section class="space-y-4">
                    <div class="space-y-1">
                        <h3 class="text-base font-semibold text-content">{{ __('setup_todos.opening_balance.form.asset_section_title') }}</h3>
                        <p class="text-sm text-content-muted">{{ __('setup_todos.opening_balance.form.asset_section_description') }}</p>
                    </div>

                    <div class="space-y-3">
                        @foreach ($assetAccounts as $index => $assetAccount)
                            <section wire:key="todo-{{ $todo->id }}-asset-account-{{ $index }}"
                                class="rounded-card border border-line bg-canvas p-4">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="text-sm font-medium text-content">
                                        {{ $assetAccount['account_name'] ?? '' }}
                                    </div>

                                    <div class="w-full sm:w-[104px]">
                                        <x-ui.input type="number"
                                            wire:model="inputs.asset_accounts.{{ $index }}.amount"
                                            class="{{ $numberInputClass }}"
                                            placeholder="0" />
                                            <x-input-error :messages="$errors->get('inputs.asset_accounts.'.$index.'.amount')" class="mt-2" />
                                    </div>
                                </div>
                            </section>
                        @endforeach
                    </div>

                    <div class="space-y-3">
                        <div class="space-y-1">
                            <h4 class="text-sm font-semibold text-content">{{ __('setup_todos.opening_balance.form.custom_asset_section_title') }}</h4>
                            <p class="text-sm text-content-muted">{{ __('setup_todos.opening_balance.form.custom_asset_section_description') }}</p>
                        </div>

                        @foreach ($customAssetAccounts as $index => $assetAccount)
                            <section wire:key="todo-{{ $todo->id }}-custom-asset-account-{{ $index }}"
                                class="rounded-card border border-line bg-canvas p-4">
                                <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_96px_auto] md:items-center">
                                    <div>
                                        <x-ui.input type="text"
                                            wire:model="inputs.custom_asset_accounts.{{ $index }}.account_name"
                                            :placeholder="__('setup_todos.opening_balance.form.custom_asset_placeholder')" />
                                        <x-input-error :messages="$errors->get('inputs.custom_asset_accounts.'.$index.'.account_name')" class="mt-2" />
                                    </div>

                                    <div>
                                        <x-ui.input type="number"
                                            wire:model="inputs.custom_asset_accounts.{{ $index }}.amount"
                                            class="{{ $numberInputClass }}"
                                            placeholder="0" />
                                        <x-input-error :messages="$errors->get('inputs.custom_asset_accounts.'.$index.'.amount')" class="mt-2" />
                                    </div>

                                    <div>
                                        <x-ui.button-delete
                                            type="button"
                                            :show-icon="false"
                                            wire:click="removeItem('custom_asset_accounts', {{ $index }})"
                                            class="w-full px-2.5 py-1.5 text-xs md:w-auto"
                                        />
                                    </div>
                                </div>
                            </section>
                        @endforeach

                        <div>
                            <x-ui.button-add type="button" wire:click="addItem('custom_asset_accounts')">{{ __('setup_todos.opening_balance.form.add_custom_asset_button') }}</x-ui.button-add>
                        </div>

                        <x-input-error :messages="$errors->get('inputs.custom_asset_accounts')" />
                    </div>
                </section>

                <section class="space-y-4">
                    <div class="space-y-1">
                        <h3 class="text-base font-semibold text-content">{{ __('setup_todos.opening_balance.form.liability_section_title') }}</h3>
                        <p class="text-sm text-content-muted">{{ __('setup_todos.opening_balance.form.liability_section_description') }}</p>
                    </div>

                    <div class="space-y-3">
                        @foreach ($liabilityAccounts as $index => $liabilityAccount)
                            <section wire:key="todo-{{ $todo->id }}-liability-account-{{ $index }}"
                                class="rounded-card border border-line bg-canvas p-4">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="text-sm font-medium text-content">
                                        {{ $liabilityAccount['account_name'] ?? '' }}
                                    </div>

                                    <div class="w-full sm:w-[104px]">
                                        <x-ui.input type="number"
                                            wire:model="inputs.liability_accounts.{{ $index }}.amount"
                                            class="{{ $numberInputClass }}"
                                            placeholder="0" />
                                        <x-input-error :messages="$errors->get('inputs.liability_accounts.'.$index.'.amount')" class="mt-2" />
                                    </div>
                                </div>
                            </section>
                        @endforeach
                    </div>

                    <div class="space-y-3">
                        <div class="space-y-1">
                            <h4 class="text-sm font-semibold text-content">{{ __('setup_todos.opening_balance.form.custom_liability_section_title') }}</h4>
                            <p class="text-sm text-content-muted">{{ __('setup_todos.opening_balance.form.custom_liability_section_description') }}</p>
                        </div>

                        @foreach ($customLiabilityAccounts as $index => $liabilityAccount)
                            <section wire:key="todo-{{ $todo->id }}-custom-liability-account-{{ $index }}"
                                class="rounded-card border border-line bg-canvas p-4">
                                <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_96px_auto] md:items-center">
                                    <div>
                                        <x-ui.input type="text"
                                            wire:model="inputs.custom_liability_accounts.{{ $index }}.account_name"
                                            :placeholder="__('setup_todos.opening_balance.form.custom_liability_placeholder')" />
                                        <x-input-error :messages="$errors->get('inputs.custom_liability_accounts.'.$index.'.account_name')" class="mt-2" />
                                    </div>

                                    <div>
                                        <x-ui.input type="number"
                                            wire:model="inputs.custom_liability_accounts.{{ $index }}.amount"
                                            class="{{ $numberInputClass }}"
                                            placeholder="0" />
                                        <x-input-error :messages="$errors->get('inputs.custom_liability_accounts.'.$index.'.amount')" class="mt-2" />
                                    </div>

                                    <div>
                                        <x-ui.button-delete
                                            type="button"
                                            :show-icon="false"
                                            wire:click="removeItem('custom_liability_accounts', {{ $index }})"
                                            class="w-full px-2.5 py-1.5 text-xs md:w-auto"
                                        />
                                    </div>
                                </div>
                            </section>
                        @endforeach

                        <div>
                            <x-ui.button-add type="button" wire:click="addItem('custom_liability_accounts')">{{ __('setup_todos.opening_balance.form.add_custom_liability_button') }}</x-ui.button-add>
                        </div>

                        <x-input-error :messages="$errors->get('inputs.custom_liability_accounts')" />
                    </div>
                </section>
            </div>

            <div class="flex justify-end pt-2">
                <x-ui.button-submit wire:loading.attr="disabled">{{ __('setup_todos.opening_balance.form.submit_button') }}</x-ui.button-submit>
            </div>
        </form>
    </x-ui.card-body>
</x-ui.card>
