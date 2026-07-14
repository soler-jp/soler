<x-app-layout>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            <div class="py-6 px-4 sm:px-6 lg:px-8">
                @includeIsolated('dashboard.partials.management-summary', [
                    'managementSummaryCards' => $managementSummaryCards,
                ])
            </div>

            <div class="py-6 px-4 sm:px-6 lg:px-8">
                <livewire:dashboard-expense-input />
            </div>

            <div class="py-6 px-4 sm:px-6 lg:px-8">
                <livewire:dashboard-revenue-input />
            </div>

        </div>
    </div>
</x-app-layout>
