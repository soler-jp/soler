<div class="flex w-full flex-col gap-4">
    @php($palette = $this->palette())

    <div class="h-full w-full rounded-2xl border px-5 py-4 shadow-sm transition {{ $palette['panel'] }} {{ $palette['hover'] }}">
        <button type="button" wire:click="openMonthsModal" class="w-full text-left">
            <h2 class="mb-1 text-xs font-medium {{ $palette['title'] }}">{{ $title }}</h2>
            <p class="flex items-end gap-1 leading-none {{ $palette['amount'] }}">
                <span class="text-xl font-bold lg:text-2xl">{{ number_format($totalAmount) }}</span>
                <span class="text-[11px] font-medium leading-none">円</span>
            </p>
        </button>
    </div>

    <div wire:show="showMonthsModal" x-transition.duration.200ms
        class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-0">
        <div class="fixed inset-0 bg-gray-500/75" wire:click="closeMonthsModal"></div>

        <div class="relative mx-auto mb-6 w-full max-w-2xl overflow-hidden rounded-lg bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">{{ $title }}の月別一覧</h3>
                    <p class="mt-1 text-sm text-gray-500">合計から月ごとの金額を選択できます。</p>
                </div>
                <button type="button" wire:click="closeMonthsModal" class="text-sm text-gray-500 hover:text-gray-700">
                    閉じる
                </button>
            </div>

            <div class="space-y-3 p-6">
                <div class="flex items-center justify-between">
                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $palette['chip'] }}">
                        {{ count($months) }} ヶ月
                    </span>
                    <span class="text-sm text-gray-500">合計 {{ number_format($totalAmount) }} 円</span>
                </div>

                @if ($months !== [])
                    <div class="grid gap-2">
                        @foreach ($months as $month)
                            <button type="button" wire:key="month-{{ $accountType }}-{{ $month['year_month'] }}"
                                wire:click="selectMonth('{{ $month['year_month'] }}')"
                                class="flex items-center justify-between rounded-xl border px-4 py-3 text-sm transition {{ $palette['monthDefault'] }}">
                                <span class="font-medium">{{ $month['label'] }}</span>
                                <span class="font-semibold">{{ number_format($month['amount']) }} 円</span>
                            </button>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-gray-500">まだ対象の取引はありません。</p>
                @endif
            </div>
        </div>
    </div>

    <div wire:show="showTransactionsModal" x-transition.duration.200ms
        class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-0">
        <div class="fixed inset-0 bg-gray-500/75" wire:click="closeTransactionsModal"></div>

        <div class="relative mx-auto mb-6 w-full max-w-6xl overflow-hidden rounded-lg bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">{{ $title }}の取引一覧</h3>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ collect($months)->firstWhere('year_month', $selectedMonth)['label'] ?? '' }}
                    </p>
                </div>
                <button type="button" wire:click="closeTransactionsModal"
                    class="text-sm text-gray-500 hover:text-gray-700">
                    閉じる
                </button>
            </div>

            <div class="p-6">
                @if ($selectedMonth)
                    <livewire:dashboard.monthly-transaction-table :account-type="$accountType"
                        :year-month="$selectedMonth" :variant="$variant" :account-names="$accountNames"
                        :excluded-account-names="$excludedAccountNames"
                        :key="$accountType.'-'.implode('-', $accountNames).'-'.implode('-', $excludedAccountNames).'-'.$selectedMonth" />
                @endif
            </div>
        </div>
    </div>
</div>
