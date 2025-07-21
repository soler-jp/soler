<div class="bg-white shadow rounded-2xl p-6">

    <h2 class="text-lg font-bold text-gray-800 mb-4">固定費の登録</h2>

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

    {{-- 名称 --}}
    <div class="mb-6">
        <label class="block text-sm font-medium text-gray-700 mb-1">名称</label>
        <input type="text" wire:model.defer="form.name"
            class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm">
        @error('form.name')
            <div class="text-xs text-red-600">{{ $message }}</div>
        @enderror
    </div>


    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">


        {{-- 借方補助科目選択（経費の種類） --}}
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">経費の種類</label>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                @foreach ($expenseAccounts as $account)
                    @foreach ($account->subAccounts as $subAccount)
                        <button type="button" wire:click="$set('form.debit_sub_account_id', {{ $subAccount->id }})"
                            class="px-2 py-1.5 text-sm rounded-md shadow-sm text-center
                            @if ($form['debit_sub_account_id'] == $subAccount->id) bg-indigo-600 text-white
                            @else bg-gray-100 text-gray-800 @endif">
                            {{ $account->name === $subAccount->name ? $account->name : $account->name . ' - ' . $subAccount->name }}
                        </button>
                    @endforeach
                @endforeach
            </div>
            @error('form.debit_sub_account_id')
                <div class="text-xs text-red-600 mt-1">{{ $message }}</div>
            @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">支払い頻度と支払日</label>

            <div class="flex flex-wrap items-center gap-2">

                {{-- 支払い頻度（ボタン群） --}}
                @foreach (['monthly' => '毎月', 'bimonthly' => '隔月', 'yearly' => '毎年'] as $key => $label)
                    <button type="button" wire:click="$set('form.interval', '{{ $key }}')"
                        class="px-3 py-1.5 text-sm rounded-md shadow-sm
                    @if ($form['interval'] === $key) bg-indigo-600 text-white
                    @else
                        bg-gray-100 text-gray-800 @endif">
                        {{ $label }}
                    </button>
                @endforeach

                {{-- 支払日入力欄（intervalに応じて切り替え） --}}
                @if ($form['interval'] === 'yearly')
                    {{-- 月 + 日 --}}
                    <div class="flex items-center gap-1 ml-4">
                        <input type="number" wire:model.defer="form.month_of_year" min="1" max="12"
                            placeholder="月" class="w-20 border-gray-300 rounded-md shadow-sm text-sm" />
                        <span class="text-sm text-gray-700">月</span>

                        <input type="number" wire:model.defer="form.day_of_month" min="1" max="31"
                            placeholder="日" class="w-20 border-gray-300 rounded-md shadow-sm text-sm" />
                        <span class="text-sm text-gray-700">日</span>
                    </div>
                @elseif (in_array($form['interval'], ['monthly', 'bimonthly']))
                    <div class="flex items-center flex-wrap gap-2 ml-4">

                        {{-- bimonthly の場合：奇数/偶数月選択 --}}
                        @if ($form['interval'] === 'bimonthly')
                            <span class="text-xs text-gray-600">支払い日:</span>
                            <div class="flex gap-1">
                                <button type="button" wire:click="$set('form.start_month_type', 'odd')"
                                    class="px-3 py-1.5 text-sm rounded-md shadow-sm
                    @if ($form['start_month_type'] === 'odd') bg-indigo-600 text-white
                    @else bg-gray-100 text-gray-800 @endif">
                                    奇数月
                                </button>
                                <button type="button" wire:click="$set('form.start_month_type', 'even')"
                                    class="px-3 py-1.5 text-sm rounded-md shadow-sm
                    @if ($form['start_month_type'] === 'even') bg-indigo-600 text-white
                    @else bg-gray-100 text-gray-800 @endif">
                                    偶数月
                                </button>
                            </div>
                        @endif

                        {{-- 支払日入力 --}}
                        <div class="flex items-center gap-1">
                            <input type="number" wire:model.defer="form.day_of_month" min="1" max="31"
                                placeholder="日" class="w-20 border-gray-300 rounded-md shadow-sm text-sm" />
                            <span class="text-sm text-gray-700">日</span>
                        </div>
                    </div>

                    @error('form.start_month_type')
                        <div class="text-xs text-red-600 mt-1 ml-4">{{ $message }}</div>
                    @enderror
                    @error('form.day_of_month')
                        <div class="text-xs text-red-600 mt-1 ml-4">{{ $message }}</div>
                    @enderror

                @endif

            </div>

            {{-- エラーメッセージ --}}
            <div class="mt-1 space-y-1">
                @error('form.interval')
                    <div class="text-xs text-red-600">{{ $message }}</div>
                @enderror
                @if ($form['interval'] === 'yearly')
                    @error('form.month_of_year')
                        <div class="text-xs text-red-600">{{ $message }}</div>
                    @enderror
                @endif
                @error('form.day_of_month')
                    <div class="text-xs text-red-600">{{ $message }}</div>
                @enderror
            </div>

            {{-- 金額 --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">金額<p class="text-xs inline">
                        (支払い時に修正できるので仮の金額を入力)</p>
                </label>
                <input type="number" wire:model.defer="form.amount"
                    class="w-full border-gray-300 rounded-md shadow-sm focus:ring-indigo-500 focus:border-indigo-500 text-sm">
                @error('form.amount')
                    <div class="text-xs text-red-600">{{ $message }}</div>
                @enderror
            </div>

            {{-- 支払い方法（貸方補助科目） --}}
            <div class="mt-1 space-y-1">
                <label class="block text-sm font-medium text-gray-700 mb-2">支払方法</label>
                <div class="flex flex-wrap gap-2">
                    @foreach ($creditAccounts as $account)
                        @foreach ($account->subAccounts as $subAccount)
                            <button type="button"
                                wire:click="$set('form.credit_sub_account_id', {{ $subAccount->id }})"
                                class="px-3 py-1.5 text-sm rounded-md shadow-sm
                            @if ($form['credit_sub_account_id'] == $subAccount->id) bg-indigo-600 text-white
                            @else bg-gray-100 text-gray-800 @endif">
                                {{ $account->name === $subAccount->name ? $account->name : $account->name . ' - ' . $subAccount->name }}
                            </button>
                        @endforeach
                    @endforeach
                </div>
                @error('form.credit_account_id')
                    <div class="text-xs text-red-600 mt-1">{{ $message }}</div>
                @enderror
            </div>

        </div>

        <div></div>

        {{-- 登録ボタン --}}
        <div class="text-right pt-6">
            <button type="submit" wire:click="save"
                class="px-5 py-2 bg-indigo-600 text-white text-sm font-semibold rounded-md shadow hover:bg-indigo-700">
                登録する
            </button>
        </div>

    </div>

</div>
