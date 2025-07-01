<div class="bg-white shadow rounded-2xl p-6">

    <h2 class="text-lg font-bold text-gray-800 mb-4">売上の入力</h2>

    {{-- フラッシュメッセージ --}}
    @if (session()->has('message'))
        <div class="mb-4 p-2 rounded bg-green-100 text-green-800 text-sm">
            {{ session('message') }}
        </div>
    @endif

    {{-- エラーメッセージ --}}
    @if (session()->has('error'))
        <div class="mb-4 p-2 rounded bg-red-100 text-red-800 text-sm">
            {{ session('error') }}
        </div>
    @endif

    {{-- 入力フォーム --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

        {{-- 左ブロック：入金先ボタン --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">入金先</label>

            <div class="space-y-4">
                @foreach (['cash' => '現金', 'bank' => '銀行', 'other' => 'その他'] as $key => $label)
                    <div>
                        <h3 class="text-sm font-bold text-gray-600 mb-2">{{ $label }}</h3>

                        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2">
                            @foreach ($receiptGroups[$key] as $item)
                                @php
                                    $id = class_basename($item) . ':' . $item->id;
                                @endphp

                                <button type="button" wire:click="$set('selectedReceiptId', '{{ $id }}')"
                                    class="w-full px-3 py-2 text-sm rounded-md shadow-sm text-center
                            @if ($selectedReceiptId === $id) bg-indigo-600 text-white
                            @else bg-gray-100 text-gray-800 @endif">
                                    {{ $item->name }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>



            @error('selectedReceiptId')
                <div class="text-xs text-red-600 mt-1">{{ $message }}</div>
            @enderror
        </div>

        {{-- 右ブロック：日付・金額・摘要・源泉徴収 --}}
        <div class="space-y-4">

            {{-- 日付・金額 --}}
            <div class="flex gap-4">
                <div class="flex-1">
                    <label class="block text-sm font-medium text-gray-700 mb-1">日付</label>
                    <input type="date" wire:model.defer="date"
                        class="w-full border-gray-300 rounded-md shadow-sm text-sm" />
                    @error('date')
                        <div class="text-xs text-red-600">{{ $message }}</div>
                    @enderror
                </div>

                <div class="flex-1">
                    <label class="block text-sm font-medium text-gray-700 mb-1">売上金額（源泉徴収前）</label>
                    <input type="number" wire:model.defer="gross_amount"
                        class="w-full border-gray-300 rounded-md shadow-sm text-sm" />
                    @error('gross_amount')
                        <div class="text-xs text-red-600">{{ $message }}</div>
                    @enderror

                </div>
            </div>

            {{-- 摘要 --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">摘要</label>
                <textarea wire:model.defer="description" rows="2"
                    class="w-full border-gray-300 rounded-md shadow-sm text-sm resize-none"></textarea>
                @error('description')
                    <div class="text-xs text-red-600">{{ $message }}</div>
                @enderror
            </div>

            {{-- 源泉徴収チェックと金額 --}}
            <div x-data>
                {{-- チェックボックス --}}
                <label class="inline-flex items-center">
                    <input type="checkbox" wire:model="withholding" class="mr-2">
                    <span class="text-sm text-gray-700">源泉徴収あり</span>
                </label>

                {{-- 源泉徴収額入力欄（JS側で切り替える） --}}
                <div x-show="$wire.withholding" x-cloak>
                    <label class="block text-sm font-medium text-gray-700 mb-1">源泉徴収額</label>
                    <input type="number" wire:model.defer="holding_amount"
                        class="w-full border-gray-300 rounded-md shadow-sm text-sm" />
                    @error('holding_amount')
                        <div class="text-xs text-red-600">{{ $message }}</div>
                    @enderror
                </div>
            </div>


            {{-- 入金先 --}}
            {{-- 登録ボタン --}}
            <div class="text-right pt-2">
                <button type="button" wire:click="save"
                    class="px-5 py-2 bg-indigo-600 text-white text-sm font-semibold rounded-md shadow hover:bg-indigo-700">
                    登録する
                </button>
            </div>
        </div>
    </div>
</div>
