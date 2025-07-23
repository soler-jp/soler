<div class="max-w-2xl mx-auto mt-10 space-y-6">

    @if ($submitError)
        <div class="text-sm text-red-600 mt-4">
            {{ $submitError }}
        </div>
    @endif


    {{-- ステップインジケーター --}}
    <div class="flex justify-between text-sm text-gray-500">
        <div class="{{ $step === 1 ? 'font-bold text-blue-600' : '' }}">1. 基本情報</div>
        <div class="{{ $step === 2 ? 'font-bold text-blue-600' : '' }}">2. 会計年度</div>
        <div class="{{ $step === 3 ? 'font-bold text-blue-600' : '' }}">3. 現金残高</div>
        <div class="{{ $step === 4 ? 'font-bold text-blue-600' : '' }}">4. 銀行口座</div>
        <div class="{{ $step === 5 ? 'font-bold text-blue-600' : '' }}">5. その他資産</div>
        <div class="{{ $step === 6 ? 'font-bold text-blue-600' : '' }}">6. 売上の分類</div>
        <div class="{{ $step === 7 ? 'font-bold text-blue-600' : '' }}">7. 確認</div>
    </div>

    <div class="max-w-3xl mx-auto p-6 space-y-6">
        @if ($step === 1)
            <div>
                <label class="block font-bold mb-2">事業体名</label>
                <p>
                    複数の事業を行う場合に区別するための名称です。<br>
                    1つしか事業を行わない場合は、任意の文字列で構いません。<br>
                    後から変更も可能です。
                </p>
                <input type="text" wire:model="name" class="w-full border rounded px-3 py-2">
                @error('name')
                    <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div class="mt-4">
                <label class="block font-bold mb-2">事業種別</label>
                <div class="grid grid-cols-3 gap-4">
                    <button type="button"
                        class="w-full px-6 py-6 border rounded flex flex-col items-center text-xl @if ($business_type === 'general') bg-blue-600 text-white @endif"
                        wire:click="$set('business_type', 'general')">
                        <x-heroicon-o-briefcase class="w-12 h-12 mb-1" />
                        一般
                    </button>
                    <!--
                    <button type="button" disabled
                        class="w-full px-6 py-6 border rounded flex flex-col items-center text-xl bg-gray-200 text-gray-500 cursor-not-allowed">
                        <x-heroicon-o-sparkles class="w-12 h-12 mb-1" />
                        農業（未対応）
                    </button>
                    <button type="button" disabled
                        class="w-full px-6 py-6 border rounded flex flex-col items-center text-xl bg-gray-200 text-gray-500 cursor-not-allowed">
                        <x-heroicon-o-home-modern class="w-12 h-12 mb-1" />
                        不動産（未対応）
                    </button>
                    -->
                </div>
                @error('business_type')
                    <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div class="mt-6 text-right">
                <button wire:click="next" class="px-6 py-3 bg-blue-600 text-white rounded">次へ</button>
            </div>
        @endif

        @if ($step === 2)
            <div>
                <label class="block font-bold mb-2">会計年度（西暦）</label>
                <input type="number" wire:model="year" class="w-full border rounded px-3 py-2">
                @error('year')
                    <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div class="mt-4">
                <label class="block font-bold mb-2">課税業者ですか？</label>
                <div class="grid grid-cols-2 gap-4">
                    <button type="button"
                        class="w-full px-6 py-6 border rounded text-xl text-center bg-gray-200 text-gray-500 opacity-50 cursor-not-allowed">はい(未対応)</button>
                    <button type="button" disabled
                        class="w-full px-6 py-6 border rounded text-xl text-center　@if ($is_taxable === false) bg-blue-600 text-white @endif">いいえ、免税業者です</button>
                </div>
            </div>

            <div class="mt-4">
                <label class="block font-bold mb-2">帳簿処理</label>
                <div class="grid grid-cols-2 gap-4">
                    <button type="button"
                        class="w-full px-6 py-6 border rounded text-xl text-center bg-gray-200 text-gray-500 opacity-50 cursor-not-allowed">税抜経理(未対応)</button>
                    <button type="button" disabled
                        class="w-full px-6 py-6 border rounded text-xl text-center @if ($is_tax_exclusive === false) bg-blue-600 text-white @endif">税込経理</button>
                </div>
            </div>

            <div class="mt-6 flex justify-between">
                <button wire:click="$set('step', 1)" class="px-6 py-3 bg-gray-300 rounded">戻る</button>
                <button wire:click="next" class="px-6 py-3 bg-blue-600 text-white rounded">次へ</button>
            </div>
        @endif

        @if ($step === 3)
            <div>
                <label class="block font-bold mb-2">今年の事業開始時の、<strong>事業専用の現金</strong>を設定します。</label>
                <p class="text-sm text-gray-600 mb-4">
                    レジや金庫などで管理している<strong>事業専用の現金</strong>の金額を入力してください(個人の財布などは含めません)<br>
                    既に「<strong>レジ現金</strong>」という内訳が用意されています。必要に応じて「金庫」「両替用小銭入れ」など追加してください。<br>
                </p>

                @foreach ($cash_accounts as $index => $cash)
                    <div class="flex items-center gap-4 mb-2">
                        @if ($cash['is_locked'] ?? false)
                            <div class="flex-1 px-3 py-2 border rounded bg-gray-100 text-gray-700">
                                {{ $cash['sub_account_name'] }}
                            </div>
                        @else
                            <input type="text" wire:model="cash_accounts.{{ $index }}.sub_account_name"
                                placeholder="名称（例：レジ現金）" class="flex-1 border rounded px-3 py-2">
                        @endif

                        <input type="number" wire:model="cash_sub_accounts.{{ $index }}.amount"
                            placeholder="金額" class="w-32 border rounded px-3 py-2">
                        @if (empty($cash['is_locked']))
                            <button type="button" wire:click="removeCashSubAccount({{ $index }})"
                                class="text-red-600">削除</button>
                        @endif

                    </div>
                @endforeach
                <button type="button" wire:click="addCashSubAccount" class="mt-2 text-blue-600">＋ 追加</button>

                @error('cash_sub_accounts.*.amount')
                    <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                @enderror
            </div>
            <div class="mt-6 flex justify-between">
                <button wire:click="$set('step', 2)" class="px-6 py-3 bg-gray-300 rounded">戻る</button>
                <button wire:click="next" class="px-6 py-3 bg-blue-600 text-white rounded">次へ</button>
            </div>
        @endif


        @if ($step === 4)
            <div>
                <label class="block font-bold mb-2">事業専用の銀行口座がある場合は、その口座の名前と金額を入力してください</label>
                @foreach ($bank_accounts as $index => $account)
                    <div class="flex items-center gap-4 mb-2">
                        <input type="text" wire:model="bank_accounts.{{ $index }}.sub_account_name"
                            placeholder="銀行名" class="flex-1 border rounded px-3 py-2">
                        <input type="number" wire:model="bank_accounts.{{ $index }}.amount" placeholder="金額"
                            class="w-32 border rounded px-3 py-2">
                        <button type="button" wire:click="removeBankAccount({{ $index }})"
                            class="text-red-600">削除</button>
                    </div>
                @endforeach
                <button type="button" wire:click="addBankAccount" class="mt-2 text-blue-600">＋ 追加</button>
            </div>
            <div class="mt-6 flex justify-between">
                <button wire:click="$set('step', 3)" class="px-6 py-3 bg-gray-300 rounded">戻る</button>
                <button wire:click="next" class="px-6 py-3 bg-blue-600 text-white rounded">次へ</button>
            </div>
        @endif

        @if ($step === 5)
            <div>
                <label
                    class="block font-bold mb-2">去年、確定申告をした人で、車やか棚卸資産がある場合は入力してください。金額は、去年の確定申告の[ココ]の金額を入力してください</label>
                @foreach ($other_assets as $index => $asset)
                    <div class="flex items-center gap-4 mb-2">
                        <select wire:model="other_assets.{{ $index }}.account_name"
                            class="border rounded px-3 py-2">
                            <option value="">-- 勘定科目 --</option>
                            <option value="車両運搬具">車両運搬具</option>
                            <option value="棚卸資産">棚卸資産</option>
                        </select>
                        <input type="text" wire:model="other_assets.{{ $index }}.sub_account_name"
                            placeholder="名称（任意）" class="flex-1 border rounded px-3 py-2">
                        <input type="number" wire:model="other_assets.{{ $index }}.amount" placeholder="金額"
                            class="w-32 border rounded px-3 py-2">
                        <button type="button" wire:click="removeOtherAsset({{ $index }})"
                            class="text-red-600">削除</button>
                    </div>
                @endforeach
                <button type="button" wire:click="addOtherAsset" class="mt-2 text-blue-600">＋ 追加</button>
            </div>
            <div class="mt-6 flex justify-between">
                <button wire:click="$set('step', 4)" class="px-6 py-3 bg-gray-300 rounded">戻る</button>
                <button wire:click="next" class="px-6 py-3 bg-blue-600 text-white rounded">次へ</button>
            </div>
        @endif


        @if ($step === 6)
            <div>
                <label class="block font-bold mb-2">売上の分類（任意）</label>
                <p class="text-sm text-gray-600 mb-4">
                    売上をあとで見返しやすくするために、あらかじめ分類（例：ネット販売、顧客A など）を登録しておくことができます。<br>
                    既に「<strong>一般売上</strong>」という分類が1つ用意されています。必要に応じて追加してください。
                </p>

                @foreach ($revenue_sub_accounts as $index => $sub)
                    <div class="flex items-center gap-4 mb-2">
                        @if ($sub['is_locked'] ?? false)
                            <div
                                class="flex-1 px-3 py-2 border rounded bg-gray-100 text-gray-700 italic cursor-not-allowed">
                                {{ $sub['name'] }}
                            </div>
                        @else
                            <input type="text" wire:model="revenue_sub_accounts.{{ $index }}.name"
                                placeholder="名称（例：ネット販売）" class="flex-1 border rounded px-3 py-2">
                            <button type="button" wire:click="removeRevenueSubAccount({{ $index }})"
                                class="text-red-600">削除</button>
                        @endif
                    </div>
                @endforeach

                <button type="button" wire:click="addRevenueSubAccount" class="mt-2 text-blue-600">＋ 追加</button>
            </div>

            <div class="mt-6 flex justify-between">
                <button wire:click="$set('step', 5)" class="px-6 py-3 bg-gray-300 rounded">戻る</button>
                <button wire:click="next" class="px-6 py-3 bg-blue-600 text-white rounded">次へ</button>
            </div>
        @endif

        @if ($step === 7)
            <div>
                <h2 class="text-xl font-bold mb-4">以下の内容で初期設定を行います</h2>
                <ul class="space-y-2">
                    <li><strong>事業体名:</strong> {{ $name }}</li>
                    <li><strong>事業種別:</strong> {{ $business_type }}</li>
                    <li><strong>会計年度:</strong> {{ $year }} 年</li>
                    <li>
                        <strong>現金残高:</strong>
                        <ul class="ml-4 list-disc">
                            @foreach ($cash_accounts as $account)
                                <li>{{ $account['sub_account_name'] }}: {{ number_format($account['amount']) }} 円</li>
                            @endforeach
                        </ul>

                    </li>
                    <li>
                        <strong>銀行口座:</strong>
                        <ul class="ml-4 list-disc">
                            @foreach ($bank_accounts as $account)
                                <li>{{ $account['sub_account_name'] }}: {{ number_format($account['amount']) }} 円</li>
                            @endforeach
                        </ul>
                    </li>
                    <li>
                        <strong>その他資産:</strong>
                        <ul class="ml-4 list-disc">
                            @foreach ($other_assets as $asset)
                                <li>{{ $asset['account_name'] }} ({{ $asset['sub_account_name'] ?? 'なし' }}):
                                    {{ number_format($asset['amount']) }} 円</li>
                            @endforeach
                        </ul>
                    </li>
                </ul>
                <hr>
                <div>
                    売上の分類:
                    <ul class="ml-4 list-disc">
                        @foreach ($revenue_sub_accounts as $sub)
                            <li>{{ $sub['name'] }}</li>
                        @endforeach
                    </ul>
                </div>

                <div class="mt-6 flex justify-between">
                    <button wire:click="$set('step', 5)" class="px-6 py-3 bg-gray-300 rounded">戻る</button>
                    <button wire:click="submit" class="px-6 py-3 bg-green-600 text-white rounded">この内容で登録</button>
                </div>
        @endif

    </div>

</div>
