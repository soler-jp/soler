@props(['showIcon' => true])

<x-ui.button variant="primary" :icon="$showIcon ? 'arrow-right' : null" icon-position="right" {{ $attributes }}>
    {{ $slot->isEmpty() ? '次へ' : $slot }}
</x-ui.button>
