@props(['showIcon' => true])

<x-ui.button variant="primary" :icon="$showIcon ? 'plus' : null" icon-position="left" {{ $attributes }}>
    {{ $slot->isEmpty() ? '追加' : $slot }}
</x-ui.button>
