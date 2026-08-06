<div>
    <x-ui.card>
        <x-ui.card-header>
            {{ __('fiscal_year_closing.planned.section_title') }}
        </x-ui.card-header>

        <div class="space-y-4 px-4 py-4">
            @if ($noticeMessage)
                <p class="border border-status-success-border bg-status-success px-4 py-3 text-sm text-status-success-fg rounded-card">
                    {{ $noticeMessage }}
                </p>
            @endif

            @if ($errorMessage)
                <p class="border border-status-danger-border bg-status-danger px-4 py-3 text-sm text-status-danger-fg rounded-card">
                    {{ $errorMessage }}
                </p>
            @endif

            @if ($items === [])
                <p class="text-sm text-content-muted">
                    {{ __('fiscal_year_closing.planned.no_items') }}
                </p>
            @else
                <p class="text-sm leading-7 text-content">
                    {{ __('fiscal_year_closing.planned.description') }}
                </p>

                <div class="space-y-3">
                    @foreach ($items as $item)
                        <div wire:key="planned-tx-{{ $item['id'] }}" class="border border-line rounded-card px-4 py-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="space-y-1">
                                <p class="text-sm font-semibold text-content">
                                    {{ $item['description'] }}
                                </p>
                                <p class="text-xs text-content-muted">
                                    {{ $item['date'] }} ／ {{ number_format($item['amount']) }} 円
                                </p>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <x-ui.button
                                    type="button"
                                    variant="primary"
                                    wire:click="confirm({{ $item['id'] }})"
                                >
                                    <span wire:loading.remove wire:target="confirm({{ $item['id'] }})">
                                        {{ __('fiscal_year_closing.planned.confirm_button') }}
                                    </span>
                                    <span wire:loading wire:target="confirm({{ $item['id'] }})">
                                        {{ __('fiscal_year_closing.planned.confirming') }}
                                    </span>
                                </x-ui.button>

                                <x-ui.button
                                    type="button"
                                    variant="danger"
                                    wire:click="cancel({{ $item['id'] }})"
                                >
                                    <span wire:loading.remove wire:target="cancel({{ $item['id'] }})">
                                        {{ __('fiscal_year_closing.planned.cancel_button') }}
                                    </span>
                                    <span wire:loading wire:target="cancel({{ $item['id'] }})">
                                        {{ __('fiscal_year_closing.planned.canceling') }}
                                    </span>
                                </x-ui.button>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </x-ui.card>
</div>
