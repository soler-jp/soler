<div class="py-8">
    <div class="mx-auto flex max-w-4xl flex-col gap-6 px-4 sm:px-6 lg:px-8">
        <header class="space-y-2">
            <h1 class="text-2xl font-semibold text-content">
                {{ __('fiscal_year_closing.heading', ['year' => $fiscalYear->year]) }}
            </h1>
            <p class="text-sm leading-6 text-content-muted">
                {{ __('fiscal_year_closing.description') }}
            </p>
        </header>

        <livewire:fiscal-year-closing.planned-transactions-section :fiscal-year-id="$fiscalYear->id" />

        <livewire:fiscal-year-closing.inventory-closing-section :fiscal-year-id="$fiscalYear->id" />

        <livewire:fiscal-year-closing.depreciation-section :fiscal-year-id="$fiscalYear->id" />
    </div>
</div>
