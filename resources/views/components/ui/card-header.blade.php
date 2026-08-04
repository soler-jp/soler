@props([
    'toggle' => false,
    'variant' => 'default',
])

@php
    $accentBorder = match ($variant) {
        'revenue'  => 'border-accent-revenue-border',
        'expense'  => 'border-accent-expense-border',
        'purchase' => 'border-accent-purchase-border',
        default    => 'border-line',
    };

    $base = 'px-4 py-3 border-b text-content font-bold text-base '.$accentBorder;
@endphp

@if ($toggle)
    <button type="button" @click="open = !open"
        {{ $attributes->merge(['class' => 'w-full flex items-center justify-between gap-2 hover:bg-surface-muted transition '.$base]) }}>
        <span class="text-left">{{ $slot }}</span>
        <x-ui.icon name="chevron-down"
            class="w-4 h-4 shrink-0 transition-transform text-content-muted"
            x-bind:class="open ? 'rotate-180' : ''" />
    </button>
@else
    <div {{ $attributes->merge(['class' => $base]) }}>
        {{ $slot }}
    </div>
@endif
