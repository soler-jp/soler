@props([
    'variant' => 'pill',
])

@php
    $baseClass = match ($variant) {
        'connected' => 'inline-flex min-w-max items-end gap-0 border-b border-line px-2 pt-2',
        default => 'inline-flex min-w-max items-stretch gap-1 rounded-card border border-line bg-surface-muted p-1',
    };
@endphp

<div {{ $attributes->merge(['class' => $baseClass]) }}>
    {{ $slot }}
</div>
