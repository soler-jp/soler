<div>
    <x-ui.card>
        <x-ui.card-header>
            {{ __('fiscal_year_closing.depreciation.section_title') }}
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
                    {{ __('fiscal_year_closing.depreciation.no_assets') }}
                </p>
            @else
                <div class="space-y-6">
                    @foreach ($items as $item)
                        <div wire:key="depreciation-entry-{{ $item['entry_id'] }}" class="border border-line rounded-card px-4 py-4 space-y-3">
                            <p class="text-sm font-semibold text-content">
                                {{ $item['name'] }}
                            </p>

                            @if ($item['is_posted'])
                                <p class="border border-status-info-border bg-status-info px-4 py-3 text-sm text-status-info-fg rounded-card">
                                    {{ __('fiscal_year_closing.depreciation.already_posted', [
                                        'amount' => number_format($item['deductible_amount']),
                                    ]) }}
                                </p>
                            @else
                                <p class="text-sm leading-7 text-content">
                                    {{ __('fiscal_year_closing.depreciation.explanation', [
                                        'amount' => number_format($item['total_amount']),
                                    ]) }}
                                </p>

                                <form wire:submit="post({{ $item['entry_id'] }})" class="flex flex-wrap items-center gap-3">
                                    <div class="flex items-center gap-2">
                                        <x-ui.input
                                            type="text"
                                            inputmode="numeric"
                                            class="w-24 text-right"
                                            wire:model="businessUsagePercents.{{ $item['entry_id'] }}"
                                        />
                                        <span class="text-sm text-content-muted">
                                            {{ __('fiscal_year_closing.depreciation.percent_unit') }}
                                        </span>
                                    </div>

                                    <x-ui.button type="submit" variant="primary">
                                        <span wire:loading.remove wire:target="post({{ $item['entry_id'] }})">
                                            {{ __('fiscal_year_closing.depreciation.post_button') }}
                                        </span>
                                        <span wire:loading wire:target="post({{ $item['entry_id'] }})">
                                            {{ __('fiscal_year_closing.depreciation.posting') }}
                                        </span>
                                    </x-ui.button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </x-ui.card>
</div>
