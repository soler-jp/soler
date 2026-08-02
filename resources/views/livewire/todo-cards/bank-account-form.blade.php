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
            ここで登録できるのは<strong>普通預金</strong>のみです。当座預金・定期預金は、サイドメニューの[銀行口座]から追加してください。
        </p>

        <div class="space-y-4">
            @foreach (($inputs['bank_accounts'] ?? []) as $index => $bankAccount)
                <section wire:key="todo-{{ $todo->id }}-bank-account-{{ $index }}"
                    class="rounded border border-slate-200 bg-slate-50 p-4">
                    <div class="grid gap-4 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto] md:items-start">
                        <div>
                            <x-input-label value="銀行名" />
                            <x-text-input wire:model="inputs.bank_accounts.{{ $index }}.label"
                                class="mt-1 block w-full"
                                placeholder="例: 〇〇銀行 ( 1234 )" />
                            <x-input-error :messages="$errors->get('inputs.bank_accounts.'.$index.'.label')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label value="残高" />
                            <x-text-input type="number"
                                wire:model="inputs.bank_accounts.{{ $index }}.opening_balance"
                                class="mt-1 block w-full"
                                placeholder="0" />
                            <x-input-error :messages="$errors->get('inputs.bank_accounts.'.$index.'.opening_balance')" class="mt-2" />
                        </div>

                        <div class="md:pt-7">
                            <button type="button" wire:click="removeItem('bank_accounts', {{ $index }})"
                                class="w-full rounded border border-slate-300 px-3 py-2 text-sm font-medium text-slate-600 transition hover:border-slate-400 hover:text-slate-900 md:w-auto">
                                削除
                            </button>
                        </div>
                    </div>
                </section>
            @endforeach

            <div>
                <button type="button" wire:click="addItem('bank_accounts')"
                    class="rounded border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 transition hover:border-slate-400 hover:text-slate-900">
                    口座を追加
                </button>
            </div>

            <x-input-error :messages="$errors->get('inputs.bank_accounts')" />
        </div>

        <div class="flex flex-wrap items-center justify-end gap-3 pt-2">
            <button type="button" wire:click="complete" wire:loading.attr="disabled"
                class="rounded border border-slate-300 px-4 py-3 text-sm font-medium text-slate-600 transition hover:border-slate-400 hover:text-slate-900 disabled:cursor-not-allowed disabled:opacity-60">
                登録しない
            </button>

            <button type="submit" wire:loading.attr="disabled"
                class="rounded bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60">
                銀行口座を登録する
            </button>
        </div>

        <p class="text-sm text-slate-500">
            後で追加する場合は、サイドメニューの[銀行口座]から追加できます。
        </p>

    </form>
</div>
