@php
    $rightColumnOrder = ['revenue', 'asset', 'equity', 'liability'];
    $cardMeta = [
        'asset' => [
            'label' => '資産',
            'panel' => 'border-slate-300 bg-white',
            'header' => 'bg-slate-100 text-slate-800',
            'accent' => 'text-slate-900',
        ],
        'liability' => [
            'label' => '負債',
            'panel' => 'border-slate-300 bg-white',
            'header' => 'bg-slate-100 text-slate-800',
            'accent' => 'text-slate-900',
        ],
        'revenue' => [
            'label' => '収益',
            'panel' => 'border-slate-300 bg-white',
            'header' => 'bg-slate-100 text-slate-800',
            'accent' => 'text-slate-900',
        ],
        'equity' => [
            'label' => '純資産',
            'panel' => 'border-slate-300 bg-white',
            'header' => 'bg-slate-100 text-slate-800',
            'accent' => 'text-slate-900',
        ],
        'expense' => [
            'label' => '費用',
            'panel' => 'border-slate-300 bg-white',
            'header' => 'bg-slate-100 text-slate-800',
            'accent' => 'text-slate-900',
        ],
    ];
@endphp

<div class="py-8">
    <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,0.78fr)_minmax(0,1fr)]">
            @php
                $expenseCard = $accountTypeCards['expense'];
                $expenseMeta = $cardMeta['expense'];
            @endphp

            @include('accounts.partials.type-card', [
                'card' => $expenseCard,
                'meta' => $expenseMeta,
                'accountType' => 'expense',
                'sectionClass' => 'xl:self-start',
            ])

            <div class="grid grid-cols-1 gap-5">
                @foreach ($rightColumnOrder as $type)
                    @php
                        $card = $accountTypeCards[$type];
                        $meta = $cardMeta[$type];
                    @endphp

                    @include('accounts.partials.type-card', [
                        'card' => $card,
                        'meta' => $meta,
                        'accountType' => $type,
                        'sectionClass' => '',
                    ])
                @endforeach
            </div>
        </div>
    </div>

    <div wire:show="showTransactionsModal" x-transition.duration.200ms
        class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-0">
        <div class="fixed inset-0 bg-gray-500/75" wire:click="closeTransactionsModal"></div>

        <div class="relative mx-auto mb-6 w-full max-w-5xl overflow-hidden rounded-lg bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
                <h3 class="text-base font-medium text-gray-900">{{ $selectedLabel }} の元帳</h3>
                <button type="button" wire:click="closeTransactionsModal" class="text-sm text-gray-500 hover:text-gray-700">
                    閉じる
                </button>
            </div>

            <div class="p-6">
                <div class="overflow-hidden border border-slate-200 bg-white">
                    <div class="overflow-x-auto">
                        <table class="min-w-full table-auto text-sm">
                            <thead class="bg-slate-100 text-slate-800">
                                <tr>
                                    <th class="px-4 py-3 text-left font-medium">日付</th>
                                    <th class="px-4 py-3 text-left font-medium">摘要</th>
                                    <th class="px-4 py-3 text-right font-medium">増減額</th>
                                    <th class="px-4 py-3 text-right font-medium">残高</th>
                                    <th class="px-4 py-3 text-right font-medium text-slate-500">相手科目</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($transactions as $transaction)
                                    <tr wire:key="transaction-modal-{{ $transaction['id'] }}"
                                        @class([
                                            'group align-top text-gray-700 transition-colors duration-100',
                                            'bg-sky-50/80' => $transaction['month_stripe'] === 0,
                                            'bg-white' => $transaction['month_stripe'] === 1,
                                            'hover:bg-amber-50' => true,
                                        ])>
                                        <td class="whitespace-nowrap px-4 py-3 group-hover:font-semibold">{{ $transaction['date'] }}</td>
                                        <td class="px-4 py-3">
                                            <div class="space-y-1">
                                                <p class="group-hover:font-semibold">{{ $transaction['description'] !== '' ? $transaction['description'] : '-' }}</p>
                                                @if ($transaction['counterparty_name'] !== '')
                                                    <p class="text-xs text-slate-500">{{ $transaction['counterparty_name'] }}</p>
                                                @endif
                                            </div>
                                        </td>
                                        <td @class([
                                            'whitespace-nowrap px-4 py-3 text-right group-hover:font-semibold',
                                            'text-emerald-700' => $transaction['amount'] > 0,
                                            'text-rose-700' => $transaction['amount'] < 0,
                                            'text-slate-900' => $transaction['amount'] === 0,
                                        ])>{{ $transaction['amount'] < 0 ? '-' : '' }}{{ number_format(abs($transaction['amount'])) }}</td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right text-slate-900 group-hover:font-semibold">{{ number_format($transaction['balance']) }}</td>
                                        <td class="max-w-48 px-4 py-3 text-right text-xs text-slate-500">
                                            <span class="inline-block truncate align-top">{{ $transaction['counterpart_label'] }}</span>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-4 py-6 text-center text-gray-500">
                                            対象の取引はありません。
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
