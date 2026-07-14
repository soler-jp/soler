@php
    $hasPurchaseCard = count($managementSummaryCards) === 4;
@endphp

@if ($hasPurchaseCard)
    @includeIsolated('dashboard.partials.management-summary-four-card', [
        'managementSummaryCards' => $managementSummaryCards,
    ])
@else
    @includeIsolated('dashboard.partials.management-summary-three-card', [
        'managementSummaryCards' => $managementSummaryCards,
    ])
@endif
