<div>
    <x-ui.card>
        <x-ui.card-body class="space-y-4">
            <div class="space-y-1">
                <h2 class="text-lg font-semibold tracking-tight text-content">
                    {{ __('previous_fiscal_year_rollover_prompt.heading', ['previous_year' => $previousYear, 'current_year' => $currentYear]) }}
                </h2>
                <p class="text-sm text-content-muted">
                    {{ __('previous_fiscal_year_rollover_prompt.description', ['previous_year' => $previousYear, 'current_year' => $currentYear]) }}
                </p>
                <p class="text-sm text-content-muted">
                    {{ __('previous_fiscal_year_rollover_prompt.note') }}
                </p>
            </div>

            @if (! $previousFiscalYearClosed)
                <div class="rounded-card border border-status-danger-border bg-status-danger px-3 py-2 text-sm text-status-danger-fg">
                    {{ __('previous_fiscal_year_rollover_prompt.not_closed', ['year' => $previousYear]) }}
                </div>
                <p class="text-sm">
                    <a href="{{ route('fiscal-years.index') }}" class="font-semibold text-content underline underline-offset-4">
                        {{ __('previous_fiscal_year_rollover_prompt.go_to_fiscal_years') }}
                    </a>
                </p>
            @endif

            @if ($errorMessage)
                <div class="rounded-card border border-status-danger-border bg-status-danger px-3 py-2 text-sm text-status-danger-fg">
                    {{ $errorMessage }}
                </div>
            @endif

            @if ($previousFiscalYearClosed)
                @if (! $showConfirmation)
                    <div>
                        <x-ui.button
                            variant="primary"
                            wire:click="openConfirmation"
                            wire:loading.attr="disabled"
                        >
                            {{ __('previous_fiscal_year_rollover_prompt.start_button', ['previous_year' => $previousYear, 'current_year' => $currentYear]) }}
                        </x-ui.button>
                    </div>
                @else
                    <div class="space-y-3">
                        <div class="space-y-1">
                            <h3 class="text-base font-semibold text-content">
                                {{ __('previous_fiscal_year_rollover_prompt.confirm_heading') }}
                            </h3>
                            <p class="text-sm text-content-muted">
                                {{ __('previous_fiscal_year_rollover_prompt.confirm_description') }}
                            </p>
                        </div>

                        <div class="overflow-hidden rounded-card border border-line">
                            <table class="min-w-full divide-y divide-line text-sm">
                                <thead class="bg-surface-muted text-content-muted">
                                    <tr>
                                        <th class="px-3 py-2 text-left font-semibold">{{ __('previous_fiscal_year_rollover_prompt.table.account') }}</th>
                                        <th class="px-3 py-2 text-left font-semibold">{{ __('previous_fiscal_year_rollover_prompt.table.sub_account') }}</th>
                                        <th class="px-3 py-2 text-left font-semibold">{{ __('previous_fiscal_year_rollover_prompt.table.type') }}</th>
                                        <th class="px-3 py-2 text-right font-semibold">{{ __('previous_fiscal_year_rollover_prompt.table.amount') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-line bg-surface">
                                    @foreach ($rolloverPreview['opening_entries'] as $entry)
                                        <tr>
                                            <td class="px-3 py-2 text-content">{{ $entry['account_name'] }}</td>
                                            <td class="px-3 py-2 text-content-muted">{{ $entry['sub_account_name'] }}</td>
                                            <td class="px-3 py-2 text-content">{{ $entry['type'] === 'debit' ? __('previous_fiscal_year_rollover_prompt.table.debit') : __('previous_fiscal_year_rollover_prompt.table.credit') }}</td>
                                            <td class="px-3 py-2 text-right font-semibold text-content">{{ number_format($entry['amount']) }}円</td>
                                        </tr>
                                    @endforeach
                                    @if ($rolloverPreview['capital_entry'] !== null)
                                        <tr>
                                            <td class="px-3 py-2 text-content">{{ $rolloverPreview['capital_entry']['account_name'] }}</td>
                                            <td class="px-3 py-2 text-content-muted">{{ $rolloverPreview['capital_entry']['sub_account_name'] }}</td>
                                            <td class="px-3 py-2 text-content">{{ $rolloverPreview['capital_entry']['type'] === 'debit' ? __('previous_fiscal_year_rollover_prompt.table.debit') : __('previous_fiscal_year_rollover_prompt.table.credit') }}</td>
                                            <td class="px-3 py-2 text-right font-semibold text-content">{{ number_format($rolloverPreview['capital_entry']['amount']) }}円</td>
                                        </tr>
                                    @endif
                                </tbody>
                                <tfoot class="divide-y divide-line bg-surface-muted">
                                    <tr>
                                        <td colspan="3" class="px-3 py-2 text-right font-semibold text-content-muted">{{ __('previous_fiscal_year_rollover_prompt.table.debit_total') }}</td>
                                        <td class="px-3 py-2 text-right font-semibold text-content">{{ number_format($rolloverDebitTotal) }}円</td>
                                    </tr>
                                    <tr>
                                        <td colspan="3" class="px-3 py-2 text-right font-semibold text-content-muted">{{ __('previous_fiscal_year_rollover_prompt.table.credit_total') }}</td>
                                        <td class="px-3 py-2 text-right font-semibold text-content">{{ number_format($rolloverCreditTotal) }}円</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                            <x-ui.button variant="secondary" wire:click="closeConfirmation">
                                {{ __('previous_fiscal_year_rollover_prompt.cancel_button') }}
                            </x-ui.button>
                            <x-ui.button
                                variant="primary"
                                wire:click="start"
                                wire:loading.attr="disabled"
                            >
                                <span wire:loading.remove wire:target="start">
                                    {{ __('previous_fiscal_year_rollover_prompt.confirm_button') }}
                                </span>
                                <span wire:loading wire:target="start">
                                    {{ __('previous_fiscal_year_rollover_prompt.loading') }}
                                </span>
                            </x-ui.button>
                        </div>
                    </div>
                @endif
            @endif
        </x-ui.card-body>
    </x-ui.card>
</div>
