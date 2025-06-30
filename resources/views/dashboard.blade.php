<x-app-layout>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">

            <div class="py-6 px-4 sm:px-6 lg:px-8">
                <h1 class="text-2xl font-bold mb-6">経営サマリー（実績）</h1>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
                    <!-- 売上カード -->
                    <div class="bg-white shadow rounded-2xl p-6">
                        <h2 class="text-gray-500 text-sm mb-2">売上</h2>
                        <p class="text-2xl font-bold text-blue-600">
                            {{ number_format($summary['actual']['total_income']) }} 円
                        </p>
                    </div>

                    <!-- 経費カード -->
                    <div class="bg-white shadow rounded-2xl p-6">
                        <h2 class="text-gray-500 text-sm mb-2">経費</h2>
                        <p class="text-2xl font-bold text-red-600">
                            {{ number_format($summary['actual']['total_expense']) }} 円
                        </p>
                    </div>

                    <!-- 利益カード -->
                    <div class="bg-white shadow rounded-2xl p-6">
                        <h2 class="text-gray-500 text-sm mb-2">利益</h2>
                        <p class="text-2xl font-bold text-green-600">
                            {{ number_format($summary['actual']['profit']) }} 円
                        </p>
                    </div>
                </div>
            </div>

            <div class="py-6 px-4 sm:px-6 lg:px-8">
                <h1 class="text-2xl font-bold mb-6">経費入力</h1>
                <livewire:dashboard-expense-input />
            </div>
        </div>
    </div>
</x-app-layout>
