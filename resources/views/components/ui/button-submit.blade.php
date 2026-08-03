@props(['showIcon' => true])

<x-ui.button variant="confirm" type="submit" :icon="$showIcon ? 'check' : null" icon-position="left" {{ $attributes }}>
    {{ $slot->isEmpty() ? '送信' : $slot }}
</x-ui.button>
