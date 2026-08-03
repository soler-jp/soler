@props(['showIcon' => true])

<x-ui.button variant="secondary" :icon="$showIcon ? 'arrow-down-tray' : null" icon-position="left" {{ $attributes }}>
    {{ $slot->isEmpty() ? 'ダウンロード' : $slot }}
</x-ui.button>
