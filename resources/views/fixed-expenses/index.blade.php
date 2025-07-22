<x-app-layout>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            <div class="py-6 px-4 sm:px-6 lg:px-8">
                <livewire:recurring.tab-list />
            </div>

            <div class="py-6 px-4 sm:px-6 lg:px-8">
                <livewire:recurring.form />
                {{-- 実行状況（今月分の支払状況を確認・登録） --}}
                {{--  
        <livewire:fixed-expense.execution-status :year="$fiscalYear->year" :month="now()->month" />
        --}}

            </div>

        </div>
    </div>
</x-app-layout>
