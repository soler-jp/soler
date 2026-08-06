<div>
    @if ($this->shouldShow)
        <div class="bg-status-warning text-status-warning-fg border-b border-status-warning-border">
            <div class="mx-auto flex w-full max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-2 sm:px-6 lg:px-8">
                <p class="text-sm">
                    {{ __('fiscal_year_switcher.message', ['year' => $this->currentFiscalYear->year]) }}
                </p>

                <div class="flex flex-wrap items-center gap-2">
                    @if ($errorMessage)
                        <span class="text-xs text-status-danger-fg">{{ $errorMessage }}</span>
                    @endif

                    @foreach ($this->switchableFiscalYears as $fiscalYear)
                        <button
                            type="button"
                            wire:click="switchTo({{ $fiscalYear->id }})"
                            wire:loading.attr="disabled"
                            class="inline-flex items-center gap-1 rounded-control bg-action-primary px-3 py-1.5 text-xs font-semibold text-action-primary-fg transition hover:bg-action-primary-hover disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            {{ __('fiscal_year_switcher.switch_button', ['year' => $fiscalYear->year]) }}
                        </button>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
