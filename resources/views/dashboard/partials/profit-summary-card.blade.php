<div class="{{ $wrapperClass }} flex">
    @if ($card['variant'] === 'cash_balance')
        <div class="w-full rounded-2xl border border-fuchsia-300 bg-fuchsia-50/90 px-5 py-4 shadow-sm">
            <div class="flex flex-col gap-3 xl:flex-row xl:items-stretch xl:gap-4">
                <div class="flex items-center xl:shrink-0">
                    <h2 class="text-xs font-medium text-fuchsia-800">{{ $card['title'] }}</h2>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 xl:flex xl:min-w-0 xl:flex-1 xl:items-stretch">
                    @forelse ($card['breakdowns'] ?? [] as $breakdown)
                        <div class="rounded-2xl border border-fuchsia-200 bg-white/90 px-4 py-3 xl:min-w-[10rem] xl:flex-1">
                            <p class="mb-1 text-xs font-medium text-fuchsia-900">{{ $breakdown['label'] }}</p>
                            <p class="flex items-end gap-1 leading-none text-fuchsia-900">
                                <span class="text-xl font-bold lg:text-2xl">{{ number_format($breakdown['amount']) }}</span>
                                <span class="text-[11px] font-medium leading-none">円</span>
                            </p>
                        </div>
                    @empty
                        <div class="rounded-2xl border border-fuchsia-200 bg-white/80 px-4 py-3 text-sm text-fuchsia-800 xl:flex-1">
                            まだ残高はありません。
                        </div>
                    @endforelse
                </div>

                <div class="flex items-center xl:shrink-0 xl:justify-end">
                    <p class="flex items-end gap-1 leading-none text-fuchsia-700">
                        <span class="text-sm font-semibold">合計</span>
                        <span class="text-lg font-bold">{{ number_format($card['amount']) }}</span>
                        <span class="text-[11px] font-medium leading-none">円</span>
                    </p>
                </div>
            </div>
        </div>
    @elseif ($card['type'] === 'account_type')
        @livewire(
            'dashboard.monthly-account-type-panel',
            [
                'accountType' => $card['account_type'],
                'title' => $card['title'],
                'variant' => $card['variant'],
                'accountNames' => $card['account_names'],
                'excludedAccountNames' => $card['excluded_account_names'],
            ],
            key('profit-summary-'.$card['key'])
        )
    @else
        @php
            $cardStyle = $card['variant'] === 'current_difference'
                ? [
                    'panel' => 'border-emerald-200 bg-emerald-50/80',
                    'title' => 'text-emerald-700',
                    'amount' => 'text-emerald-700',
                ]
                : ($card['variant'] === 'cash_balance'
                    ? [
                        'panel' => 'border-sky-200 bg-sky-50/80',
                        'title' => 'text-sky-700',
                        'amount' => 'text-sky-700',
                    ]
                : ($card['variant'] === 'purchase'
                    ? [
                        'panel' => 'border-accent-purchase-border bg-accent-purchase',
                        'title' => 'text-accent-purchase-fg',
                        'amount' => 'text-accent-purchase-fg',
                    ]
                    : [
                        'panel' => 'border-green-200 bg-green-50/80',
                        'title' => 'text-green-700',
                        'amount' => 'text-green-600',
                    ]));
        @endphp

        <div class="h-full w-full rounded-2xl border px-5 py-4 shadow-sm {{ $cardStyle['panel'] }}">
            <h2 class="mb-1 text-xs font-medium {{ $cardStyle['title'] }}">{{ $card['title'] }}</h2>
            <p class="flex items-end gap-1 leading-none {{ $cardStyle['amount'] }}">
                <span class="text-xl font-bold lg:text-2xl">{{ number_format($card['amount']) }}</span>
                <span class="text-[11px] font-medium leading-none">円</span>
            </p>

            @if ($card['note_lines'] !== [])
                <div class="mt-3 space-y-1 text-[11px] leading-4 text-gray-600">
                    @foreach ($card['note_lines'] as $noteLine)
                        <p>{{ $noteLine }}</p>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</div>
