<div>
    <x-ui.card>
        <x-ui.card-header>
            {{ __('fiscal_year_closing.inventory.section_title') }}
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

            @if ($preview['already_registered'])
                <p class="border border-status-info-border bg-status-info px-4 py-3 text-sm text-status-info-fg rounded-card">
                    {{ __('fiscal_year_closing.inventory.already_registered') }}
                </p>
            @elseif ($preview['sub_accounts'] === [])
                <p class="text-sm text-content-muted">
                    {{ __('fiscal_year_closing.inventory.no_sub_accounts') }}
                </p>
            @else
                <form wire:submit="register" class="space-y-6">
                    @foreach ($preview['sub_accounts'] as $sub)
                        <div wire:key="inventory-sub-{{ $sub['id'] }}" class="space-y-3">
                            @if (count($preview['sub_accounts']) > 1)
                                <p class="text-sm font-semibold text-content">
                                    {{ $sub['name'] }}
                                </p>
                            @endif

                            <p class="text-sm leading-7 text-content">
                                {{ __('fiscal_year_closing.inventory.explanation', [
                                    'opening' => number_format($sub['opening_balance']),
                                    'purchases' => number_format($preview['purchases_amount']),
                                    'year' => $fiscalYear->year,
                                ]) }}
                            </p>

                            <div class="flex items-center gap-2">
                                <x-ui.input
                                    type="text"
                                    inputmode="numeric"
                                    class="w-40 text-right"
                                    wire:model="closingAmounts.{{ $sub['id'] }}"
                                    :placeholder="__('fiscal_year_closing.inventory.closing_amount_placeholder')"
                                />
                                <span class="text-sm text-content-muted">
                                    {{ __('fiscal_year_closing.inventory.yen_unit') }}
                                </span>
                            </div>
                        </div>
                    @endforeach

                    <div class="flex justify-end">
                        <x-ui.button type="submit" variant="primary">
                            <span wire:loading.remove wire:target="register">
                                {{ __('fiscal_year_closing.inventory.register_button') }}
                            </span>
                            <span wire:loading wire:target="register">
                                {{ __('fiscal_year_closing.inventory.registering') }}
                            </span>
                        </x-ui.button>
                    </div>
                </form>
            @endif
        </div>
    </x-ui.card>
</div>
