@php
    $cards = $this->cards();
    $cashBalanceCard = collect($cards)->firstWhere('variant', 'cash_balance');
    $summaryCards = collect($cards)
        ->reject(fn (array $card): bool => $card['variant'] === 'cash_balance')
        ->values()
        ->all();
@endphp

<div class="space-y-4">
    @if ($cashBalanceCard)
        @include('dashboard.partials.profit-summary-card', [
            'card' => $cashBalanceCard,
            'wrapperClass' => 'min-w-0',
        ])
    @endif

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        @foreach ($summaryCards as $card)
            @include('dashboard.partials.profit-summary-card', [
                'card' => $card,
                'wrapperClass' => 'min-w-0',
            ])
        @endforeach
    </div>
</div>
