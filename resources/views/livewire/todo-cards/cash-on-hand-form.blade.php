<div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
    <div class="space-y-2">
        @if ($todo->due_on !== null)
            <div class="text-xs font-semibold uppercase tracking-[0.16em] text-amber-700">
                期限 {{ $todo->due_on->format('Y-m-d') }}
            </div>
        @endif

        <h2 class="text-xl font-semibold tracking-tight text-slate-900">{{ $todo->title }}</h2>

        <x-todo-body :body="$todo->body" />
    </div>

    <form wire:submit="submit" class="mt-6 space-y-5">
        <p class="text-sm leading-6 text-slate-700">
            {!! __('setup_todos.cash_on_hand.form.description') !!}
        </p>

        <div class="space-y-4">
            @foreach (($inputs['cash_accounts'] ?? []) as $index => $cashAccount)
                <section wire:key="todo-{{ $todo->id }}-cash-account-{{ $index }}"
                    class="rounded border border-slate-200 bg-slate-50 p-4">
                    <div class="grid gap-4 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto] md:items-start">
                        <div>
                            <x-input-label :value="__('setup_todos.cash_on_hand.form.name_label')" />
                            <x-text-input wire:model="inputs.cash_accounts.{{ $index }}.label"
                                class="mt-1 block w-full"
                                :placeholder="__('setup_todos.cash_on_hand.form.placeholder')" />
                            <x-input-error :messages="$errors->get('inputs.cash_accounts.'.$index.'.label')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label :value="__('setup_todos.cash_on_hand.form.opening_balance_label')" />
                            <x-text-input type="number"
                                wire:model="inputs.cash_accounts.{{ $index }}.opening_balance"
                                class="mt-1 block w-full"
                                placeholder="0" />
                            <x-input-error :messages="$errors->get('inputs.cash_accounts.'.$index.'.opening_balance')" class="mt-2" />
                        </div>

                        <div class="md:pt-7">
                            <x-ui.button-delete type="button" wire:click="removeItem('cash_accounts', {{ $index }})" class="w-full md:w-auto" />
                        </div>
                    </div>
                </section>
            @endforeach

            <div>
                <x-ui.button-add type="button" wire:click="addItem('cash_accounts')">{{ __('setup_todos.cash_on_hand.form.add_button') }}</x-ui.button-add>
            </div>

            <x-input-error :messages="$errors->get('inputs.cash_accounts')" />
        </div>

        <div class="flex flex-wrap items-center justify-end gap-3 pt-2">
            <x-ui.button-cancel type="button" wire:click="complete" wire:loading.attr="disabled">{{ __('setup_todos.cash_on_hand.form.skip_button') }}</x-ui.button-cancel>

            <x-ui.button-submit wire:loading.attr="disabled">{{ __('setup_todos.cash_on_hand.form.submit_button') }}</x-ui.button-submit>
        </div>

        <p class="text-sm text-slate-500">
            {{ __('setup_todos.cash_on_hand.form.footer') }}
        </p>
    </form>
</div>
