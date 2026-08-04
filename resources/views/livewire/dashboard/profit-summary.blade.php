@php
    $cards = $this->cards();
    $hasPurchaseCard = count($cards) === 4;
@endphp

<div class="flex flex-col gap-4 md:flex-row md:items-stretch">
    @foreach ($cards as $card)
        @php
            $wrapperClass = 'md:min-w-0 md:flex-1';
        @endphp

        @include('dashboard.partials.profit-summary-card', [
            'card' => $card,
            'wrapperClass' => $wrapperClass,
        ])
    @endforeach
</div>
