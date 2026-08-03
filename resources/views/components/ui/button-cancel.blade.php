@props(['showIcon' => true])

<x-ui.button variant="secondary" :icon="$showIcon ? 'x-mark' : null" icon-position="left" {{ $attributes }}>
    {{ $slot->isEmpty() ? 'キャンセル' : $slot }}
</x-ui.button>
