@props(['showIcon' => true])

<x-ui.button variant="danger" :icon="$showIcon ? 'trash' : null" icon-position="left" {{ $attributes }}>
    {{ $slot->isEmpty() ? '削除' : $slot }}
</x-ui.button>
