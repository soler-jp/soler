<div>
    <x-ui.card>
        <x-ui.card-body class="space-y-4">
            <div class="space-y-1">
                <h2 class="text-lg font-semibold tracking-tight text-content">
                    {{ __('next_fiscal_year_prompt.heading', ['year' => $nextYear]) }}
                </h2>
                <p class="text-sm text-content-muted">
                    {{ __('next_fiscal_year_prompt.description', [
                        'current_year' => $currentYear,
                        'next_year' => $nextYear,
                    ]) }}
                </p>
            </div>

            @if ($errorMessage)
                <div class="bg-status-danger text-status-danger-fg border border-status-danger-border rounded-card px-3 py-2 text-sm">
                    {{ $errorMessage }}
                </div>
            @endif

            <div>
                <x-ui.button variant="primary" wire:click="start" wire:loading.attr="disabled">
                    {{ __('next_fiscal_year_prompt.start_button', ['year' => $nextYear]) }}
                </x-ui.button>
            </div>
        </x-ui.card-body>
    </x-ui.card>
</div>
