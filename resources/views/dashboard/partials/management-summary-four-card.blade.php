<div class="flex flex-col gap-4 md:flex-row md:items-stretch">
    @foreach ($managementSummaryCards as $card)
        @php
            $wrapperClass = in_array($card['key'], ['revenue', 'expense', 'purchase'], true)
                ? 'md:w-[11rem] lg:w-[12rem] md:shrink-0'
                : 'md:min-w-0 md:flex-1';
        @endphp

        @include('dashboard.partials.management-summary-card', [
            'card' => $card,
            'wrapperClass' => $wrapperClass,
        ])
    @endforeach
</div>
