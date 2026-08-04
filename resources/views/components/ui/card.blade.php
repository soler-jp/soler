@props([
    'variant' => 'default',
    'collapsible' => false,
    'collapsed' => false,
])

@php
    $variantClass = match ($variant) {
        'revenue'  => 'bg-accent-revenue border-accent-revenue-border',
        'expense'  => 'bg-accent-expense border-accent-expense-border',
        'purchase' => 'bg-accent-purchase border-accent-purchase-border',
        default    => 'bg-surface border-line',
    };
@endphp

<div
    @if ($collapsible)
        x-data="{ open: {{ $collapsed ? 'false' : 'true' }} }"
    @endif
    {{ $attributes->merge(['class' => 'text-content border rounded-card shadow-card overflow-hidden '.$variantClass]) }}>
    {{ $slot }}
</div>
