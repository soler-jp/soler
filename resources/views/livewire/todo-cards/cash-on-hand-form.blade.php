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
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-sm font-semibold text-slate-700">{{ __('setup_todos.cash_on_hand.form.item_label') }} {{ $index + 1 }}</p>

                        <button type="button" wire:click="removeItem('cash_accounts', {{ $index }})"
                            class="text-sm font-medium text-slate-500 transition hover:text-slate-700">
                            削除
                        </button>
                    </div>

                    <div class="mt-4 grid gap-4 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
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
                    </div>
                </section>
            @endforeach

            <div>
                <button type="button" wire:click="addItem('cash_accounts')"
                    class="rounded border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 transition hover:border-slate-400 hover:text-slate-900">
                    {{ __('setup_todos.cash_on_hand.form.add_button') }}
                </button>
            </div>

            <x-input-error :messages="$errors->get('inputs.cash_accounts')" />
        </div>

        <div class="flex flex-wrap items-center justify-end gap-3 pt-2">
            <button type="button" wire:click="complete" wire:loading.attr="disabled"
                class="rounded border border-slate-300 px-4 py-3 text-sm font-medium text-slate-600 transition hover:border-slate-400 hover:text-slate-900 disabled:cursor-not-allowed disabled:opacity-60">
                {{ __('setup_todos.cash_on_hand.form.skip_button') }}
            </button>

            <button type="submit" wire:loading.attr="disabled"
                class="rounded bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60">
                {{ __('setup_todos.cash_on_hand.form.submit_button') }}
            </button>
        </div>

        <p class="text-sm text-slate-500">
            {{ __('setup_todos.cash_on_hand.form.footer') }}
        </p>
    </form>
</div>
