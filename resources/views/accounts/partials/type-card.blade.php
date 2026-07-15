<section class="overflow-hidden rounded-lg border {{ $meta['panel'] }} {{ $sectionClass ?? '' }}">
    <div class="border-b border-slate-300 px-4 py-3 {{ $meta['header'] }}">
        <div class="flex items-start justify-between gap-4">
            <h2 class="text-base font-medium">{{ $meta['label'] }}</h2>
            <p class="text-lg font-medium {{ $meta['accent'] }}">
                {{ number_format($card['total_amount']) }}
            </p>
        </div>
    </div>

    <div class="p-4">
        @forelse ($card['accounts'] as $account)
            @if (! $account['has_multiple_sub_accounts'])
                @php($subAccount = $account['sub_accounts'][0])

                <button type="button"
                    wire:click="openTransactionsModal(@js($accountType), {{ $account['account_id'] }}, {{ $subAccount['sub_account_id'] }}, @js($subAccount['sub_account_name']))"
                    class="flex w-full items-center justify-between gap-4 border-b border-slate-200 py-2 text-left transition hover:bg-slate-50 last:border-b-0">
                    <p class="min-w-0 truncate text-sm text-slate-800">{{ $subAccount['sub_account_name'] }}</p>
                    <p class="shrink-0 text-sm font-medium text-right text-slate-900">
                        {{ number_format($subAccount['amount']) }}
                    </p>
                </button>
            @else
                <div class="border-b border-slate-200 py-2 last:border-b-0">
                    <button type="button"
                    wire:click="openTransactionsModal(@js($accountType), {{ $account['account_id'] }}, null, @js($account['account_name']))"
                        class="flex w-full items-center justify-between gap-4 px-1 py-2 text-left transition hover:bg-slate-50">
                        <p class="min-w-0 truncate text-sm text-slate-900">{{ $account['account_name'] }}</p>
                        <p class="shrink-0 text-sm text-right text-slate-900">
                            {{ number_format($account['total_amount']) }}
                        </p>
                    </button>

                    <div class="pl-4">
                        @foreach ($account['sub_accounts'] as $subAccount)
                            <button type="button"
                                wire:click="openTransactionsModal(@js($accountType), {{ $account['account_id'] }}, {{ $subAccount['sub_account_id'] }}, @js($subAccount['sub_account_name']))"
                                class="block w-full border-b border-slate-200 py-2 text-left transition hover:bg-slate-50 last:border-b-0">
                                <p class="min-w-0 truncate text-sm text-slate-700">
                                    {{ $subAccount['sub_account_name'] }}
                                    <span class="text-slate-500">({{ number_format($subAccount['amount']) }})</span>
                                </p>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif
        @empty
            <div class="py-6 text-center text-sm text-slate-500">
                対象の集計はまだありません。
            </div>
        @endforelse
    </div>
</section>
