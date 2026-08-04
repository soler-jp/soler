@props(['type' => 'text'])

<input {{ $attributes->merge([
    'type' => $type,
    'class' => 'block w-full px-3 py-2 text-sm bg-surface-muted text-content border border-line rounded-control focus:outline-none focus:ring-2 focus:ring-focus focus:border-focus focus:bg-surface',
]) }} />
