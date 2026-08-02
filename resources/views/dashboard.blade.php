<x-app-layout>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if ($pendingTodos->isNotEmpty())
                <div class="py-6 px-4 sm:px-6 lg:px-8">
                    <section class="space-y-4">
                        <div class="space-y-1">
                            <p class="text-sm font-semibold uppercase tracking-[0.18em] text-slate-500">ToDo</p>
                            <h2 class="text-2xl font-semibold tracking-tight text-slate-900">先に進める準備</h2>
                        </div>

                        <div class="grid gap-4 xl:grid-cols-2">
                            @foreach ($pendingTodos as $todo)
                                <livewire:todo-card :todo="$todo" :key="'todo-card-'.$todo->id" />
                            @endforeach
                        </div>
                    </section>
                </div>
            @endif

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
