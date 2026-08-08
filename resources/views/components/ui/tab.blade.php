@props([
    'active' => false,
    'count' => null,
    'type' => 'button',
    'variant' => 'pill',
    'activeClass' => null,
    'inactiveClass' => null,
    'activeCountClass' => null,
    'inactiveCountClass' => null,
])

@php
    $buttonClasses = match ($variant) {
        'connected' => [
            'inline-flex items-center gap-2 rounded-t-card border px-3 py-2 text-sm font-semibold transition',
            ($activeClass ?? 'border-line border-b-surface bg-surface text-content -mb-px shadow-card') => $active,
            ($inactiveClass ?? 'border-l border-y border-r-0 border-line bg-surface-muted text-content-muted hover:bg-surface hover:text-content last:border-r last:border-line') => ! $active,
        ],
        default => [
            'inline-flex items-center gap-2 rounded-control border px-3 py-2 text-sm font-semibold transition',
            'border-transparent bg-surface text-content shadow-card' => $active,
            'border-transparent bg-transparent text-content-muted hover:bg-surface hover:text-content' => ! $active,
        ],
    };

    $countClasses = match ($variant) {
        'connected' => [
            'rounded-badge px-2 py-0.5 text-xs font-semibold',
            ($activeCountClass ?? 'bg-surface-muted text-content') => $active,
            ($inactiveCountClass ?? 'bg-surface text-content-muted') => ! $active,
        ],
        default => [
            'rounded-badge px-2 py-0.5 text-xs font-semibold',
            'bg-action-primary text-action-primary-fg' => $active,
            'bg-surface text-content-muted' => ! $active,
        ],
    };
@endphp

<button
    {{ $attributes->class($buttonClasses)->merge(['type' => $type]) }}
>
    <span>{{ $slot }}</span>

    @if ($count !== null)
        <span @class($countClasses)>{{ $count }}</span>
    @endif
</button>
