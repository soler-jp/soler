@props(['showIcon' => true])

<x-ui.button variant="secondary" :icon="$showIcon ? 'pencil' : null" icon-position="left" {{ $attributes }}>
    {{ $slot->isEmpty() ? '編集' : $slot }}
</x-ui.button>
