<div class="py-8">
    <div class="mx-auto space-y-3 px-4 sm:px-6 lg:px-8">
        <livewire:soler-ui.transaction-entry.journal-form.standard />

        <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="space-y-3 p-3 sm:p-4">
            <div class="flex flex-col gap-2 xl:flex-row xl:items-start xl:justify-between">
                <div class="space-y-1">
                    <h1 class="text-base font-semibold text-slate-900">仕訳一覧</h1>
                    <p class="text-xs text-slate-500">Transaction 単位で借方・貸方を横並び表示します。</p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <div class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs text-slate-600">
                        {{ $this->transactions->total() }} 件
                    </div>
                    <button type="button" wire:click="resetFilters"
                        class="rounded-full border border-slate-200 bg-white px-3 py-1.5 text-[10px] font-medium text-slate-600 transition hover:border-slate-300 hover:text-slate-900">
                        条件をリセット
                    </button>
                </div>
            </div>

            <div class="items-end grid gap-3 xl:grid-cols-[minmax(0,1fr)_auto_8rem_8rem_8rem_7rem]">
                <label class="flex h-full flex-col justify-end gap-1">
                    <span class="flex h-[14px] items-end text-[10px] font-medium uppercase tracking-[0.14em] text-slate-500">フリーワード</span>
                    <input type="text" wire:model.live.debounce.300ms="keyword"
                        class="w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-0"
                        placeholder="摘要・相手先・勘定科目・補助科目">
                </label>

                <div class="flex h-full flex-col justify-end gap-1">
                    <div class="flex h-[14px] items-end justify-between gap-2">
                        <span class="text-[10px] font-medium uppercase tracking-[0.14em] text-slate-500">月</span>
                        <button type="button" wire:click="clearMonths"
                            class="text-[10px] font-medium leading-none text-slate-600 transition hover:text-slate-900">
                            全解除
                        </button>
                    </div>

                    <div class="inline-flex flex-wrap overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        @foreach ($this->monthOptions() as $month)
                            <button type="button" wire:click="toggleMonth({{ $month }})"
                                class="{{ in_array($month, $months, true) ? 'bg-slate-800 text-white' : 'bg-white text-slate-600 hover:bg-slate-100' }} flex min-w-8 items-center justify-center border-r border-slate-200 px-2 py-1.5 text-xs leading-4 last:border-r-0">
                                {{ $month }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <label class="flex h-full flex-col justify-end gap-1">
                    <span class="flex h-[14px] items-end text-[10px] font-medium uppercase tracking-[0.14em] text-slate-500">金額一致</span>
                    <input type="text" inputmode="numeric" wire:model.live="exactAmount"
                        class="w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-0"
                        placeholder="10000">
                </label>

                <label class="flex h-full flex-col justify-end gap-1">
                    <span class="flex h-[14px] items-end text-[10px] font-medium uppercase tracking-[0.14em] text-slate-500">金額以上</span>
                    <input type="text" inputmode="numeric" wire:model.live="minAmount"
                        class="w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-0"
                        placeholder="5000">
                </label>

                <label class="flex h-full flex-col justify-end gap-1">
                    <span class="flex h-[14px] items-end text-[10px] font-medium uppercase tracking-[0.14em] text-slate-500">金額以下</span>
                    <input type="text" inputmode="numeric" wire:model.live="maxAmount"
                        class="w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-0"
                        placeholder="50000">
                </label>

                <label class="flex h-full flex-col justify-end gap-1">
                    <span class="flex h-[14px] items-end text-[10px] font-medium uppercase tracking-[0.14em] text-slate-500">表示件数</span>
                    <select wire:model.live="perPage"
                        class="w-full rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs text-slate-900 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-0">
                        <option value="50">50</option>
                        <option value="100">100</option>
                        <option value="200">200</option>
                    </select>
                </label>
            </div>

            <div class="grid gap-3 xl:grid-cols-2">
                <section class="space-y-2 rounded-xl border border-rose-100 bg-rose-50/60 p-2.5">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-xs font-semibold text-rose-900">借方勘定科目</h2>
                            <p class="text-[10px] text-rose-800/80">未選択なら借方条件なし</p>
                        </div>
                        <button type="button" wire:click="clearDebitAccountNames"
                            class="text-[10px] font-medium text-rose-700 transition hover:text-rose-900">
                            全解除
                        </button>
                    </div>

                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($availableDebitAccountCounts as $accountName => $count)
                            @php($isSelected = in_array($accountName, $debitAccountNames, true))
                            @php($isAvailable = $this->debitAccountOptionIsAvailable($accountName))
                            @php($displayCount = $this->debitAccountOptionCounts[$accountName] ?? $count)
                            <label wire:key="debit-account-{{ md5($accountName) }}" class="cursor-pointer">
                                <input type="checkbox" value="{{ $accountName }}" wire:model.live="debitAccountNames" class="sr-only">
                                <span class="{{ $isSelected ? 'border-rose-200 bg-rose-100 text-rose-800' : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:text-gray-900' }} {{ ! $isAvailable ? 'opacity-40 line-through' : '' }} inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[10px] font-medium transition">
                                    <span>{{ $accountName }}</span>
                                    <span class="{{ $isSelected ? 'bg-white/80 text-rose-700' : 'bg-gray-100 text-gray-500' }} rounded-full px-1.5 py-0.5 text-[9px] font-semibold">{{ $displayCount }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </section>

                <section class="space-y-2 rounded-xl border border-sky-100 bg-sky-50/60 p-2.5">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-xs font-semibold text-sky-900">貸方勘定科目</h2>
                            <p class="text-[10px] text-sky-800/80">未選択なら貸方条件なし</p>
                        </div>
                        <button type="button" wire:click="clearCreditAccountNames"
                            class="text-[10px] font-medium text-sky-700 transition hover:text-sky-900">
                            全解除
                        </button>
                    </div>

                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($availableCreditAccountCounts as $accountName => $count)
                            @php($isSelected = in_array($accountName, $creditAccountNames, true))
                            @php($isAvailable = $this->creditAccountOptionIsAvailable($accountName))
                            @php($displayCount = $this->creditAccountOptionCounts[$accountName] ?? $count)
                            <label wire:key="credit-account-{{ md5($accountName) }}" class="cursor-pointer">
                                <input type="checkbox" value="{{ $accountName }}" wire:model.live="creditAccountNames" class="sr-only">
                                <span class="{{ $isSelected ? 'border-sky-200 bg-sky-100 text-sky-800' : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:text-gray-900' }} {{ ! $isAvailable ? 'opacity-40 line-through' : '' }} inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[10px] font-medium transition">
                                    <span>{{ $accountName }}</span>
                                    <span class="{{ $isSelected ? 'bg-white/80 text-sky-700' : 'bg-gray-100 text-gray-500' }} rounded-full px-1.5 py-0.5 text-[9px] font-semibold">{{ $displayCount }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </section>
            </div>
            </div>

            <div class="overflow-x-auto border-t border-slate-200 p-3 sm:p-4">
                <table class="min-w-[1240px] table-fixed overflow-hidden rounded-lg border border-slate-200 text-xs text-slate-800">
                    <thead class="bg-slate-800 text-slate-100">
                        <tr>
                            <th class="w-24 px-3 py-3 text-left font-semibold">
                                <button type="button" wire:click="sort('entry_number')" class="inline-flex items-center gap-1 transition hover:text-white/80">
                                    <span>No</span>
                                    <span class="text-[10px]">{{ $this->sortIndicator('entry_number') }}</span>
                                </button>
                            </th>
                            <th class="w-28 px-3 py-3 text-left font-semibold">
                                <button type="button" wire:click="sort('date')" class="inline-flex items-center gap-1 transition hover:text-white/80">
                                    <span>日付</span>
                                    <span class="text-[10px]">{{ $this->sortIndicator('date') }}</span>
                                </button>
                            </th>
                            <th class="w-[22rem] border-l border-slate-700 px-3 py-3 text-left font-semibold">借方</th>
                            <th class="w-[22rem] border-l border-slate-700 px-3 py-3 text-left font-semibold">貸方</th>
                            <th class="w-28 border-l border-slate-700 px-3 py-3 text-right font-semibold">
                                <button type="button" wire:click="sort('amount')" class="inline-flex items-center gap-1 transition hover:text-white/80">
                                    <span>金額</span>
                                    <span class="text-[10px]">{{ $this->sortIndicator('amount') }}</span>
                                </button>
                            </th>
                            <th class="w-32 border-l border-slate-700 px-3 py-3 text-left font-semibold">税区分</th>
                            <th class="w-64 border-l border-slate-700 px-3 py-3 text-left font-semibold">
                                <button type="button" wire:click="sort('description')" class="inline-flex items-center gap-1 transition hover:text-white/80">
                                    <span>摘要</span>
                                    <span class="text-[10px]">{{ $this->sortIndicator('description') }}</span>
                                </button>
                            </th>
                            <th class="w-48 border-l border-slate-700 px-3 py-3 text-left font-semibold">
                                <button type="button" wire:click="sort('counterparty')" class="inline-flex items-center gap-1 transition hover:text-white/80">
                                    <span>相手先</span>
                                    <span class="text-[10px]">{{ $this->sortIndicator('counterparty') }}</span>
                                </button>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @forelse ($this->transactions as $transaction)
                            <tr wire:key="transaction-journal-{{ $transaction->id }}" class="align-top">
                                <td class="whitespace-nowrap px-3 py-2.5 text-slate-500">
                                    {{ $transaction->entry_number }}
                                </td>
                                <td class="whitespace-nowrap px-3 py-2.5 font-medium text-slate-700">
                                    {{ $transaction->date->format('Y-m-d') }}
                                </td>
                                <td class="border-l border-slate-200 bg-rose-50/40 px-3 py-2.5">
                                    <div class="space-y-1">
                                        @foreach ($transaction->debitJournalEntries() as $journalEntry)
                                            <div class="flex items-start justify-between gap-3">
                                                <div class="min-w-0">
                                                    <p class="font-semibold text-rose-900">{{ $journalEntry->subAccount->account->name }}</p>
                                                    @if ($journalEntry->subAccount->name !== $journalEntry->subAccount->account->name)
                                                        <p class="truncate text-[11px] text-rose-800/80">{{ $journalEntry->subAccount->name }}</p>
                                                    @endif
                                                </div>
                                                <p class="shrink-0 whitespace-nowrap font-semibold tabular-nums text-rose-950">{{ number_format($journalEntry->gross_amount) }}</p>
                                            </div>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="border-l border-slate-200 bg-sky-50/35 px-3 py-2.5">
                                    <div class="space-y-1">
                                        @foreach ($transaction->creditJournalEntries() as $journalEntry)
                                            <div class="flex items-start justify-between gap-3">
                                                <div class="min-w-0">
                                                    <p class="font-semibold text-sky-900">{{ $journalEntry->subAccount->account->name }}</p>
                                                    @if ($journalEntry->subAccount->name !== $journalEntry->subAccount->account->name)
                                                        <p class="truncate text-[11px] text-sky-800/80">{{ $journalEntry->subAccount->name }}</p>
                                                    @endif
                                                </div>
                                                <p class="shrink-0 whitespace-nowrap font-semibold tabular-nums text-sky-950">{{ number_format($journalEntry->gross_amount) }}</p>
                                            </div>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="border-l border-slate-200 px-3 py-2.5 text-right font-semibold tabular-nums text-slate-900">
                                    {{ number_format($transaction->total_amount_for_search ?? $transaction->total_amount) }}
                                </td>
                                <td class="border-l border-slate-200 px-3 py-2.5 text-slate-600">
                                    {{ $transaction->journal_tax_type_summary }}
                                </td>
                                <td class="border-l border-slate-200 px-3 py-2.5">
                                    <div class="space-y-1">
                                        @if ($transaction->description !== null && $transaction->description !== '')
                                            <p class="font-medium text-slate-900">{{ $transaction->description }}</p>
                                        @endif
                                        @if ($transaction->remarks !== null && $transaction->remarks !== '')
                                            <p class="text-[11px] text-slate-500">{{ $transaction->remarks }}</p>
                                        @endif
                                    </div>
                                </td>
                                <td class="border-l border-slate-200 px-3 py-2.5 text-slate-700">
                                    {{ $transaction->counterparty?->name ?? '-' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-3 py-8 text-center text-sm text-slate-500">
                                    条件に一致する取引はありません。
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="p-3 sm:p-4">
                {{ $this->transactions->links() }}
            </div>
        </section>
    </div>
</div>
