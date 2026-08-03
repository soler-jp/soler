@props([
    'variant' => 'primary',
    'type' => 'button',
    'icon' => null,
    'iconPosition' => 'left',
])

@php
    $base = 'inline-flex items-center justify-center gap-2 px-4 py-2 text-sm font-semibold rounded-control focus:outline-none focus:ring-2 focus:ring-focus focus:ring-offset-2 transition ease-in-out duration-150 disabled:opacity-50 disabled:cursor-not-allowed';

    $variantClass = match ($variant) {
        'primary' => 'bg-action-primary text-action-primary-fg hover:bg-action-primary-hover',
        'confirm' => 'bg-action-confirm text-action-confirm-fg hover:bg-action-confirm-hover',
        'danger' => 'bg-action-danger text-action-danger-fg hover:bg-action-danger-hover',
        'secondary' => 'bg-surface text-content border border-line hover:bg-surface-muted',
        'ghost' => 'bg-transparent text-content hover:bg-surface-muted',
    };
@endphp

<button {{ $attributes->merge(['type' => $type, 'class' => $base.' '.$variantClass]) }}>
    @if ($icon && $iconPosition === 'left')
        <x-ui.icon :name="$icon" />
    @endif
    {{ $slot }}
    @if ($icon && $iconPosition === 'right')
        <x-ui.icon :name="$icon" />
    @endif
</button>
