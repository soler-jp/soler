@props([
    'transactions' => [],
    'accountType',
    'showTaxTypeColumn' => true,
    'tableWrapClass' => '',
    'tableHeadClass' => '',
    'emptyMessage' => '対象の取引はありません。',
    'emptyStateColspan' => 1,
    'keyPrefix' => 'transaction',
    'revenueDebitHeader' => '入金先',
    'expenseDebitHeader' => '種類',
    'expenseCreditHeader' => '支払い元',
    'taxTypeHeader' => '消費税',
    'counterpartyHeader' => '相手',
    'deleteAction' => null,
    'deleteConfirm' => 'この取引を削除しますか？',
    'editAction' => null,
    'editingTransactionId' => null,
    'editLivewireComponent' => null,
])

@php
    $actionColumnCount = ($deleteAction ? 1 : 0) + ($editAction ? 1 : 0);
    $totalColumnCount = $emptyStateColspan + $actionColumnCount;
@endphp

<div {{ $attributes->class(['overflow-hidden border bg-white', $tableWrapClass]) }}>
    <div class="overflow-x-auto">
        <table class="min-w-full table-auto text-sm">
            <thead class="{{ $tableHeadClass }}">
                <tr>
                    <th class="px-4 py-3 text-left font-semibold">日付</th>
                    @if ($accountType === \App\Models\Account::TYPE_REVENUE)
                        <th class="px-4 py-3 text-left font-semibold">{{ $revenueDebitHeader }}</th>
                    @else
                        <th class="px-4 py-3 text-left font-semibold">{{ $expenseDebitHeader }}</th>
                        <th class="px-4 py-3 text-left font-semibold">{{ $expenseCreditHeader }}</th>
                    @endif
                    @if ($showTaxTypeColumn)
                        <th class="px-4 py-3 text-left font-semibold">{{ $taxTypeHeader }}</th>
                    @endif
                    <th class="px-4 py-3 text-left font-semibold">{{ $counterpartyHeader }}</th>
                    <th class="px-4 py-3 text-left font-semibold">注釈</th>
                    <th class="px-4 py-3 text-right font-semibold">金額</th>
                    @if ($actionColumnCount > 0)
                        <th class="px-4 py-3 text-right font-semibold" colspan="{{ $actionColumnCount }}"><span class="sr-only">操作</span></th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($transactions as $transaction)
                    <tr wire:key="{{ $keyPrefix }}-{{ $transaction['id'] }}" class="align-top text-gray-700">
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
                        @if ($editAction)
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                @if (! empty($transaction['is_single_pair']))
                                    <button type="button"
                                        wire:click="{{ $editAction }}({{ $transaction['id'] }})"
                                        class="text-xs font-medium text-blue-600 hover:text-blue-800 hover:underline">
                                        編集
                                    </button>
                                @endif
                            </td>
                        @endif
                        @if ($deleteAction)
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                <button type="button"
                                    wire:click="{{ $deleteAction }}({{ $transaction['id'] }})"
                                    wire:confirm="{{ $deleteConfirm }}"
                                    class="text-xs font-medium text-red-600 hover:text-red-800 hover:underline">
                                    削除
                                </button>
                            </td>
                        @endif
                    </tr>
                    @if ($editAction && $editLivewireComponent && $editingTransactionId === $transaction['id'])
                        <tr wire:key="{{ $keyPrefix }}-edit-{{ $transaction['id'] }}">
                            <td colspan="{{ $totalColumnCount }}" class="bg-gray-50 px-4 py-4">
                                @livewire($editLivewireComponent, ['transactionId' => $transaction['id']], key('edit-'.$transaction['id']))
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="{{ $totalColumnCount }}" class="px-4 py-6 text-center text-gray-500">
                            {{ $emptyMessage }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
