<div class="bg-white shadow rounded-2xl p-6">

    <h2 class="text-lg font-bold text-gray-800 mb-4">経費の入力</h2>

    {{-- フラッシュメッセージ --}}
    @if (session()->has('message'))
        <div class="mb-4 p-2 rounded bg-green-100 text-green-800 text-sm">
            {{ session('message') }}
        </div>
    @endif

    @if (session()->has('error'))
        <div class="mb-4 p-2 rounded bg-red-100 text-red-800 text-sm">
            {{ session('error') }}
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

        {{-- 左ブロック：debit 勘定科目（grid配置） --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">経費の種類</label>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                @foreach ($expenseAccounts as $account)
                    <button type="button" wire:click="$set('debit_account_id', {{ $account->id }})"
                        class="px-2 py-1.5 text-sm rounded-md shadow-sm text-center
                                   @if ($debit_account_id === $account->id) bg-indigo-600 text-white
                                   @else
                                       bg-gray-100 text-gray-800 @endif">
                        {{ $account->name }}
                    </button>
                @endforeach
            </div>
            @error('debit_account_id')
                <div class="text-xs text-red-600 mt-1">{{ $message }}</div>
            @enderror
        </div>

        {{-- 右ブロック：日付・金額・摘要・credit・登録 --}}
        <div class="space-y-4">

            {{-- 日付・金額 --}}
            <div class="flex gap-4">
                <div class="flex-1">
                    <label class="block text-sm font-medium text-gray-700 mb-1">日付</label>
                    <input type="date" wire:model.defer="date"
                        class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm">
                    @error('date')
                        <div class="text-xs text-red-600">{{ $message }}</div>
                    @enderror
                </div>

                <div class="flex-1">
                    <label class="block text-sm font-medium text-gray-700 mb-1">金額</label>
                    <input type="number" wire:model.defer="amount"
                        class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm">
                    @error('amount')
                        <div class="text-xs text-red-600">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            {{-- 摘要（複数行） --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">摘要</label>
                <textarea wire:model.defer="description" rows="2"
                    class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm resize-none"></textarea>
                @error('description')
                    <div class="text-xs text-red-600">{{ $message }}</div>
                @enderror
            </div>

            {{-- 支払方法（credit ボタン群） --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">支払方法</label>
                <div class="flex flex-wrap gap-2">
                    @foreach ($creditAccounts as $account)
                        <button type="button" wire:click="$set('credit_account_id', {{ $account->id }})"
                            class="px-3 py-1.5 text-sm rounded-md shadow-sm
                                       @if ($credit_account_id === $account->id) bg-indigo-600 text-white
                                       @else
                                           bg-gray-100 text-gray-800 @endif">
                            {{ $account->name }}
                        </button>
                    @endforeach
                </div>
                @error('credit_account_id')
                    <div class="text-xs text-red-600 mt-1">{{ $message }}</div>
                @enderror
            </div>

            {{-- 登録ボタン --}}
            <div class="text-right pt-2">
                <button type="submit" wire:click="submit"
                    class="px-5 py-2 bg-indigo-600 text-white text-sm font-semibold rounded-md shadow hover:bg-indigo-700">
                    登録する
                </button>
            </div>
        </div>
    </div>
</div>
