<div class="{{ $wrapperClass }} flex">
    @if ($card['type'] === 'account_type')
        @livewire(
            'dashboard.monthly-account-type-panel',
            [
                'accountType' => $card['account_type'],
                'title' => $card['title'],
                'variant' => $card['variant'],
                'accountNames' => $card['account_names'],
                'excludedAccountNames' => $card['excluded_account_names'],
            ],
            key('management-summary-'.$card['key'])
        )
    @else
        @php
            $cardStyle = $card['variant'] === 'current_difference'
                ? [
                    'panel' => 'border-emerald-200 bg-emerald-50/80',
                    'title' => 'text-emerald-700',
                    'amount' => 'text-emerald-700',
                ]
                : [
                    'panel' => 'border-green-200 bg-green-50/80',
                    'title' => 'text-green-700',
                    'amount' => 'text-green-600',
                ];
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
