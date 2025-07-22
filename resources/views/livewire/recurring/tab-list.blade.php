<div class="space-y-6">

    <div class="bg-white rounded-md shadow">

        {{-- タブヘッダー --}}
        <div class="flex gap-1 px-4 pt-4 relative -mb-px">
            @foreach ($plans as $plan)
                @php $selected = $selectedPlan && $selectedPlan->id === $plan->id; @endphp
                <button wire:click="selectPlan({{ $plan->id }})"
                    class="px-4 py-2 text-sm font-medium rounded-t-md
                {{ $selected
                    ? 'bg-white text-indigo-600 border-x border-t border-b-0 border-gray-300'
                    : 'bg-gray-100 text-gray-600 border-b hover:bg-gray-200' }}">
                    {{ $plan->name }}
                </button>
            @endforeach
        </div>

        {{-- 取引一覧 --}}
        <div>

            @forelse ($transactions as $tx)
                <div class="border-t border-gray-200">
                    <form wire:submit.prevent="confirm({{ $tx->id }})"
                        class="flex flex-wrap justify-between items-center gap-2 px-4 py-3 text-sm">

                        {{-- 日付 --}}
                        <div class="w-36 text-gray-700">
                            @if ($tx->is_planned)
                                <input type="date" wire:model.defer="inputs.{{ $tx->id }}.date"
                                    class="w-full text-sm border-gray-300 rounded" />
                            @else
                                {{ $tx->date->format('Y/m/d') }}
                            @endif
                        </div>

                        {{-- 金額 --}}
                        <div class="w-32 text-gray-700">
                            @if ($tx->is_planned)
                                <input type="number" wire:model.defer="inputs.{{ $tx->id }}.amount"
                                    class="w-full text-sm border-gray-300 rounded px-2 py-1" />
                            @else
                                ¥{{ number_format($tx->total_amount) }}
                            @endif
                        </div>

                        {{-- 支払元 --}}
                        <div class="flex-1 text-gray-700">
                            @if ($tx->is_planned)
                                <div class="flex flex-wrap gap-1">
                                    @foreach ($creditAccounts as $account)
                                        @foreach ($account->subAccounts as $subAccount)
                                            @php
                                                $selected =
                                                    data_get($inputs, "{$tx->id}.credit_sub_account_id") ==
                                                    $subAccount->id;
                                            @endphp
                                            <button type="button"
                                                wire:click="$set('inputs.{{ $tx->id }}.credit_sub_account_id', {{ $subAccount->id }})"
                                                class="px-2 py-1 text-xs rounded-md shadow-sm transition
                                            {{ $selected ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-800' }}">
                                                {{ $account->name === $subAccount->name ? $account->name : "{$account->name} - {$subAccount->name}" }}
                                            </button>
                                        @endforeach
                                    @endforeach
                                </div>
                                @error("inputs.{$tx->id}.credit_sub_account_id")
                                    <div class="text-xs text-red-600 mt-1">{{ $message }}</div>
                                @enderror
                            @else
                                {{ $tx->credit_accounts_label ?? '—' }}
                            @endif
                        </div>

                        {{-- 確定ボタン or ステータス --}}
                        <div class="text-right">
                            @if ($tx->is_planned)
                                <button type="submit"
                                    class="bg-indigo-600 text-white px-3 py-1 rounded text-sm hover:bg-indigo-700">
                                    確定
                                </button>
                            @else
                                <span class="inline-block px-2 py-1 text-xs bg-green-100 text-green-800 rounded-full">
                                    確定済
                                </span>
                            @endif
                        </div>
                    </form>
                </div>
            @empty
                <div class="px-4 py-6 text-sm text-gray-400">登録された取引はありません。</div>
            @endforelse
        </div>

    </div>
</div>
