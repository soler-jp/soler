<div class="overflow-hidden border bg-white {{ $this->palette()['wrap'] }}">
    <div class="overflow-x-auto">
        <table class="min-w-full table-auto text-sm">
            <thead class="{{ $this->palette()['head'] }}">
                <tr>
                    <th class="px-4 py-3 text-left font-semibold">日付</th>
                    @if ($accountType === \App\Models\Account::TYPE_REVENUE)
                        <th class="px-4 py-3 text-left font-semibold">入金先(debit)</th>
                    @else
                        <th class="px-4 py-3 text-left font-semibold">支払い勘定科目(debit)</th>
                        <th class="px-4 py-3 text-left font-semibold">支払い元(credit)</th>
                    @endif
                    @if ($showTaxTypeColumn)
                        <th class="px-4 py-3 text-left font-semibold">消費税タイプ</th>
                    @endif
                    <th class="px-4 py-3 text-left font-semibold">CounterParty</th>
                    <th class="px-4 py-3 text-left font-semibold">注釈</th>
                    <th class="px-4 py-3 text-right font-semibold">金額</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($transactions as $transaction)
                    <tr wire:key="transaction-{{ $transaction['id'] }}" class="align-top text-gray-700">
                        <td class="whitespace-nowrap px-4 py-3">{{ $transaction['date'] }}</td>
                        @if ($accountType === \App\Models\Account::TYPE_REVENUE)
                            <td class="px-4 py-3">
                                <span class="inline-flex max-w-full items-center rounded-full border px-2.5 py-1 text-xs font-semibold shadow-sm {{ $transaction['debit_badge_class'] }}">
                                    {{ $transaction['debit_label'] }}
                                </span>
                            </td>
                        @else
                            <td class="px-4 py-3">
                                <span class="inline-flex max-w-full items-center rounded-full border px-2.5 py-1 text-xs font-semibold shadow-sm {{ $transaction['debit_badge_class'] }}">
                                    {{ $transaction['debit_label'] }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex max-w-full items-center rounded-full border px-2.5 py-1 text-xs font-semibold shadow-sm {{ $transaction['credit_badge_class'] }}">
                                    {{ $transaction['credit_label'] }}
                                </span>
                            </td>
                        @endif
                        @if ($showTaxTypeColumn)
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold shadow-sm {{ $transaction['tax_type_badge_class'] }}">
                                    {{ $transaction['tax_type_label'] }}
                                </span>
                            </td>
                        @endif
                        <td class="px-4 py-3">{{ $transaction['counterparty_name'] }}</td>
                        <td class="px-4 py-3">
                            <div class="space-y-1">
                                @if ($transaction['description'] !== '')
                                    <p class="text-sm text-gray-700">{{ $transaction['description'] }}</p>
                                @endif
                                @if ($transaction['allocation_note'] !== '')
                                    <p class="text-xs text-gray-400">{{ $transaction['allocation_note'] }}</p>
                                @endif
                            </div>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right font-semibold">{{ number_format($transaction['amount']) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $this->emptyStateColspan() }}"
                            class="px-4 py-6 text-center text-gray-500">
                            この月の対象取引はありません。
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
