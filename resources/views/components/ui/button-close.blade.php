@props(['showIcon' => true])

<x-ui.button variant="ghost" :icon="$showIcon ? 'x-mark' : null" icon-position="left" {{ $attributes }}>
    {{ $slot->isEmpty() ? '閉じる' : $slot }}
</x-ui.button>
