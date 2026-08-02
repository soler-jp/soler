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
            {!! __('setup_todos.counterparty.form.description') !!}
        </p>

        <div class="space-y-4">
            @foreach (($inputs['counterparties'] ?? []) as $index => $counterparty)
                <section wire:key="todo-{{ $todo->id }}-counterparty-{{ $index }}"
                    class="rounded border border-slate-200 bg-slate-50 p-4">
                    <div class="space-y-4">
                        <div>
                            <x-input-label :value="__('setup_todos.counterparty.form.name_label')" />
                            <x-text-input wire:model="inputs.counterparties.{{ $index }}.name"
                                class="mt-1 block w-full"
                                :placeholder="__('setup_todos.counterparty.form.name_placeholder')" />
                            <x-input-error :messages="$errors->get('inputs.counterparties.'.$index.'.name')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label :value="__('setup_todos.counterparty.form.notes_label')" />
                            <textarea wire:model="inputs.counterparties.{{ $index }}.notes" rows="3"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="{{ __('setup_todos.counterparty.form.notes_placeholder') }}"></textarea>
                            <x-input-error :messages="$errors->get('inputs.counterparties.'.$index.'.notes')" class="mt-2" />
                        </div>

                        <div class="flex justify-end">
                            <button type="button" wire:click="removeItem('counterparties', {{ $index }})"
                                class="w-full rounded border border-slate-300 px-3 py-2 text-sm font-medium text-slate-600 transition hover:border-slate-400 hover:text-slate-900 sm:w-auto">
                                削除
                            </button>
                        </div>
                    </div>
                </section>
            @endforeach

            <div>
                <button type="button" wire:click="addItem('counterparties')"
                    class="rounded border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 transition hover:border-slate-400 hover:text-slate-900">
                    {{ __('setup_todos.counterparty.form.add_button') }}
                </button>
            </div>

            <x-input-error :messages="$errors->get('inputs.counterparties')" />
        </div>

        <div class="flex flex-wrap items-center justify-end gap-3 pt-2">
            <button type="button" wire:click="complete" wire:loading.attr="disabled"
                class="rounded border border-slate-300 px-4 py-3 text-sm font-medium text-slate-600 transition hover:border-slate-400 hover:text-slate-900 disabled:cursor-not-allowed disabled:opacity-60">
                {{ __('setup_todos.counterparty.form.skip_button') }}
            </button>

            <button type="submit" wire:loading.attr="disabled"
                class="rounded bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60">
                {{ __('setup_todos.counterparty.form.submit_button') }}
            </button>
        </div>

        <p class="text-sm text-slate-500">
            {{ __('setup_todos.counterparty.form.footer') }}
        </p>
    </form>
</div>
