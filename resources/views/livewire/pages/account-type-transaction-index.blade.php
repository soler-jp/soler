<div class="py-12">
    @php($palette = $this->palette())

    <div class="max-w-7xl mx-auto space-y-6 px-4 sm:px-6 lg:px-8">
        @if (! $groupByMonth)
            <section class="rounded-2xl border shadow-sm {{ $palette['monthCard'] }}">
                <div class="space-y-4 p-5 sm:p-6">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="space-y-1">
                            <p class="text-sm font-medium text-gray-900">{{ $title }}</p>
                            <p class="text-sm text-gray-500">{{ $description }}</p>
                        </div>

                        <div class="flex items-baseline gap-3 self-start rounded-full border border-red-100 bg-red-50/60 px-3 py-1.5 text-sm">
                            <span class="text-gray-500">{{ count($transactions) }} 件</span>
                            <span class="font-semibold {{ $palette['amount'] }}">{{ number_format($this->selectedTotalAmount()) }}</span>
                        </div>
                    </div>

                    <div class="space-y-3 rounded-2xl border border-gray-100 bg-gray-50/80 p-3 sm:p-4">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-xs font-medium tracking-wide text-gray-500">表示する経費の種類</p>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" wire:click="selectAllAccountNames"
                                    class="rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-gray-600 transition hover:border-gray-300 hover:text-gray-900">
                                    全選択
                                </button>
                                <button type="button" wire:click="clearAccountNames"
                                    class="rounded-full border border-gray-200 bg-white px-2.5 py-1 text-xs font-medium text-gray-600 transition hover:border-gray-300 hover:text-gray-900">
                                    全解除
                                </button>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            @foreach ($availableAccountNames as $accountName)
                                <label wire:key="expense-type-{{ md5($accountName) }}" class="cursor-pointer">
                                    <input type="checkbox" value="{{ $accountName }}" wire:model.live="accountNames"
                                        class="sr-only">
                                    <span @class([
                                        'inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-medium transition-colors',
                                        'border-red-200 bg-red-100 text-red-800' => in_array($accountName, $accountNames, true),
                                        'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:text-gray-900' => ! in_array($accountName, $accountNames, true),
                                    ])>
                                        <span>{{ $accountName }}</span>
                                        <span @class([
                                            'rounded-full px-1.5 py-0.5 text-[10px] font-semibold',
                                            'bg-white/80 text-red-700' => in_array($accountName, $accountNames, true),
                                            'bg-gray-100 text-gray-500' => ! in_array($accountName, $accountNames, true),
                                        ])>{{ $availableAccountCounts[$accountName] ?? 0 }}件</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="overflow-hidden border bg-white {{ $palette['tableWrap'] }}">
                        <div class="overflow-x-auto">
                            <table class="min-w-full table-auto text-sm">
                                <thead class="{{ $palette['tableHead'] }}">
                                    <tr>
                                        <th class="px-4 py-3 text-left font-semibold">日付</th>
                                        @if ($accountType === \App\Models\Account::TYPE_REVENUE)
                                            <th class="px-4 py-3 text-left font-semibold">入金先</th>
                                        @else
                                            <th class="px-4 py-3 text-left font-semibold">種類</th>
                                            <th class="px-4 py-3 text-left font-semibold">支払い元</th>
                                        @endif
                                        @if ($showTaxTypeColumn)
                                            <th class="px-4 py-3 text-left font-semibold">消費税</th>
                                        @endif
                                        <th class="px-4 py-3 text-left font-semibold">相手</th>
                                        <th class="px-4 py-3 text-left font-semibold">注釈</th>
                                        <th class="px-4 py-3 text-right font-semibold">金額</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @forelse ($transactions as $transaction)
                                        <tr wire:key="index-transaction-{{ $transaction['id'] }}" class="align-top text-gray-700">
                                            <td class="whitespace-nowrap px-4 py-3">{{ $transaction['date'] }}</td>
                                            @if ($accountType === \App\Models\Account::TYPE_REVENUE)
                                                <td class="px-4 py-3">
                                                    <span class="inline-flex max-w-full items-center rounded-full border px-2.5 py-1 text-xs font-semibold shadow-sm {{ $transaction['debit_badge_class'] }}">
                                                        {{ $transaction['debit_label'] }}
                                                    </span>
                                                </td>
                                            @else
                                                <td class="px-4 py-3">
                                                    <span class="inline-flex max-w-full items-center rounded-full border px-2.5 py-1 text-xs font-semibold shadow-sm {{ $transaction['debit_badge_class'] }}">
                                                        {{ $transaction['debit_label'] }}
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <span class="inline-flex max-w-full items-center rounded-full border px-2.5 py-1 text-xs font-semibold shadow-sm {{ $transaction['credit_badge_class'] }}">
                                                        {{ $transaction['credit_label'] }}
                                                    </span>
                                                </td>
                                            @endif
                                            @if ($showTaxTypeColumn)
                                                <td class="px-4 py-3">
                                                    <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold shadow-sm {{ $transaction['tax_type_badge_class'] }}">
                                                        {{ $transaction['tax_type_label'] }}
                                                    </span>
                                                </td>
                                            @endif
                                            <td class="px-4 py-3">{{ $transaction['counterparty_name'] }}</td>
                                            <td class="px-4 py-3">
                                                <div class="space-y-1">
                                                    @if ($transaction['description'] !== '')
                                                        <p class="text-sm text-gray-700">{{ $transaction['description'] }}</p>
                                                    @endif
                                                    @if ($transaction['allocation_note'] !== '')
                                                        <p class="text-xs text-gray-400">{{ $transaction['allocation_note'] }}</p>
                                                    @endif
                                                </div>
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-3 text-right font-semibold">{{ number_format($transaction['amount']) }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="{{ $this->emptyStateColspan() }}"
                                                class="px-4 py-6 text-center text-gray-500">
                                                表示する経費の種類を選ぶと、対象取引がここに表示されます。
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>
        @else
            @forelse ($months as $month)
                <section class="rounded-2xl border shadow-sm {{ $palette['monthCard'] }}">
                    <div class="sticky top-0 z-20 rounded-t-2xl border-b px-4 py-3 backdrop-blur supports-[backdrop-filter]:backdrop-blur-md {{ $palette['monthHeader'] }}">
                        <div class="flex flex-wrap items-center justify-between gap-4">
                            <div class="flex items-baseline gap-3">
                                <h2 class="text-2xl font-bold tracking-tight">{{ $month['label'] }}</h2>
                                <p class="text-sm font-medium opacity-75">{{ count($month['transactions']) }} 件</p>
                            </div>

                            <div class="rounded-xl border border-white/40 bg-white/40 px-3 py-1.5 shadow-sm backdrop-blur">
                                <p class="text-2xl font-bold tracking-tight {{ $palette['amount'] }}">{{ number_format($month['amount']) }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="p-6">
                        <div class="overflow-hidden border bg-white {{ $palette['tableWrap'] }}">
                            <div class="overflow-x-auto">
                                <table class="min-w-full table-auto text-sm">
                                    <thead class="{{ $palette['tableHead'] }}">
                                        <tr>
                                            <th class="px-4 py-3 text-left font-semibold">日付</th>
                                            @if ($accountType === \App\Models\Account::TYPE_REVENUE)
                                                <th class="px-4 py-3 text-left font-semibold">入金先</th>
                                            @else
                                                <th class="px-4 py-3 text-left font-semibold">種類</th>
                                                <th class="px-4 py-3 text-left font-semibold">支払い元</th>
                                            @endif
                                            @if ($showTaxTypeColumn)
                                                <th class="px-4 py-3 text-left font-semibold">消費税</th>
                                            @endif
                                            <th class="px-4 py-3 text-left font-semibold">相手</th>
                                            <th class="px-4 py-3 text-left font-semibold">注釈</th>
                                            <th class="px-4 py-3 text-right font-semibold">金額</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @forelse ($month['transactions'] as $transaction)
                                            <tr wire:key="index-transaction-{{ $transaction['id'] }}" class="align-top text-gray-700">
                                                <td class="whitespace-nowrap px-4 py-3">{{ $transaction['date'] }}</td>
                                                @if ($accountType === \App\Models\Account::TYPE_REVENUE)
                                                    <td class="px-4 py-3">
                                                        <span class="inline-flex max-w-full items-center rounded-full border px-2.5 py-1 text-xs font-semibold shadow-sm {{ $transaction['debit_badge_class'] }}">
                                                            {{ $transaction['debit_label'] }}
                                                        </span>
                                                    </td>
                                                @else
                                                    <td class="px-4 py-3">
                                                        <span class="inline-flex max-w-full items-center rounded-full border px-2.5 py-1 text-xs font-semibold shadow-sm {{ $transaction['debit_badge_class'] }}">
                                                            {{ $transaction['debit_label'] }}
                                                        </span>
                                                    </td>
                                                    <td class="px-4 py-3">
                                                        <span class="inline-flex max-w-full items-center rounded-full border px-2.5 py-1 text-xs font-semibold shadow-sm {{ $transaction['credit_badge_class'] }}">
                                                            {{ $transaction['credit_label'] }}
                                                        </span>
                                                    </td>
                                                @endif
                                                @if ($showTaxTypeColumn)
                                                    <td class="px-4 py-3">
                                                        <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold shadow-sm {{ $transaction['tax_type_badge_class'] }}">
                                                            {{ $transaction['tax_type_label'] }}
                                                        </span>
                                                    </td>
                                                @endif
                                                <td class="px-4 py-3">{{ $transaction['counterparty_name'] }}</td>
                                                <td class="px-4 py-3">
                                                    <div class="space-y-1">
                                                        @if ($transaction['description'] !== '')
                                                            <p class="text-sm text-gray-700">{{ $transaction['description'] }}</p>
                                                        @endif
                                                        @if ($transaction['allocation_note'] !== '')
                                                            <p class="text-xs text-gray-400">{{ $transaction['allocation_note'] }}</p>
                                                        @endif
                                                    </div>
                                                </td>
                                                <td class="whitespace-nowrap px-4 py-3 text-right font-semibold">{{ number_format($transaction['amount']) }}</td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="{{ $this->emptyStateColspan() }}"
                                                    class="px-4 py-6 text-center text-gray-500">
                                                    この月の対象取引はありません。
                                                </td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </section>
            @empty
                <div class="rounded-2xl border border-gray-200 bg-white p-8 text-center text-sm text-gray-500 shadow-sm">
                    対象の取引はまだありません。
                </div>
            @endforelse
        @endif
    </div>
</div>
