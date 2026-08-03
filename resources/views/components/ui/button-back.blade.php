@props(['showIcon' => true])

<x-ui.button variant="ghost" :icon="$showIcon ? 'arrow-left' : null" icon-position="left" {{ $attributes }}>
    {{ $slot->isEmpty() ? '戻る' : $slot }}
</x-ui.button>
