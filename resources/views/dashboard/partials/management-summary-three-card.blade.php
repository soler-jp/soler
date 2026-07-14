<div class="flex flex-col gap-4 md:flex-row md:items-stretch">
    @foreach ($managementSummaryCards as $card)
        @include('dashboard.partials.management-summary-card', [
            'card' => $card,
            'wrapperClass' => 'md:min-w-0 md:flex-1',
        ])
    @endforeach
</div>
