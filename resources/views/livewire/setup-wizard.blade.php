<div class="mx-auto w-full max-w-5xl space-y-8">
    @if ($submitError)
        <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ $submitError }}
        </div>
    @endif

    <div class="space-y-3">
        <p class="text-sm font-semibold uppercase tracking-[0.2em] text-slate-500">Initial Setup</p>
        <div class="flex flex-wrap gap-x-4 gap-y-2 text-sm text-slate-500 xl:flex-nowrap xl:justify-between xl:gap-x-6">
            @foreach ([
                1 => '1. 事業名',
                2 => '2. 記録を始める年',
                3 => '3. 開始状態',
                4 => '4. 確認すること',
                5 => '5. 消費税申告',
                6 => '6. 確認',
            ] as $index => $label)
                @php($isReachable = $index <= $max_unlocked_step)
                <button type="button" wire:click="goToStep({{ $index }})"
                    @disabled(! $isReachable)
                    class="{{ $step === $index ? 'font-bold text-blue-600' : '' }} {{ $isReachable ? 'hover:text-slate-700' : 'cursor-not-allowed text-slate-300' }} px-1 py-1 text-left leading-5 transition xl:flex-none">
                    {{ $label }}
                </button>
            @endforeach

            <div aria-hidden="true" class="hidden xl:block xl:w-12 xl:flex-none"></div>
        </div>
    </div>

    <div class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm sm:p-10">
        @if ($step === 1)
            <div class="space-y-6">
                <div class="space-y-2">
                    <h2 class="text-3xl font-semibold tracking-tight text-slate-900">あなたの屋号を教えてください</h2>
                    <p class="text-sm leading-6 text-slate-600">
                        開業届で申請した屋号を入力するか、「個人事業」など任意の文字列でも構いません。<br>あとで修正できます。
                    </p>
                </div>

                <div>
                    <label class="mb-2 block text-sm font-semibold text-slate-800">事業名</label>
                    <input type="text" wire:model="name"
                        class="w-full rounded-xl border border-slate-300 px-4 py-3 text-slate-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200">
                    @error('name')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        @endif

        @if ($step === 2)
            <div class="space-y-6">
                <div class="space-y-2">
                    <h2 class="text-3xl font-semibold tracking-tight text-slate-900">何年の分から記録を始めますか？</h2>
                    <p class="text-sm leading-6 text-amber-700">2022より以前の記帳については未サポートです。</p>
                </div>

                <div class="space-y-3">
                    <label class="block text-sm font-semibold text-slate-800">記録を始める年</label>
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        @foreach ($availableYears as $availableYear)
                            <button type="button" wire:click="$set('year', {{ $availableYear }})"
                                class="{{ $year === $availableYear ? 'border-blue-600 bg-blue-50 text-blue-700 ring-2 ring-blue-200' : 'border-slate-200 text-slate-700 hover:border-slate-300' }} rounded-xl border px-4 py-4 text-left transition">
                                <span class="block text-base font-semibold">{{ $availableYear }}年</span>
                            </button>
                        @endforeach
                    </div>
                    @error('year')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </div>
        @endif

        @if ($step === 3)
            <div class="space-y-6">
                <div class="space-y-2">
                    <h2 class="text-3xl font-semibold tracking-tight text-slate-900">{{ $this->yearLabel() }}は、どの状態ですか？</h2>
                    <p class="text-sm leading-6 text-slate-600">
                        {{ $this->yearLabel() }}が、前年の決算書から引き継ぐ年なのか、この年に新しく事業を始めた年なのかを選んでください。
                    </p>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <button type="button" wire:click="$set('opening_context', 'first_year')"
                        class="rounded-2xl border px-6 py-6 text-left transition {{ $opening_context === 'first_year' ? 'border-blue-600 bg-blue-50 ring-2 ring-blue-200' : 'border-slate-200 hover:border-slate-300' }}">
                        <p class="text-lg font-semibold text-slate-900">{{ $this->yearLabel() }}に新しく事業を始めた</p>
                        <p class="mt-3 text-sm leading-6 text-slate-600">
                            前年の決算書から引き継ぐ金額はありません。開業のために用意した現金・預金などの元手は、このあと入力できます。
                        </p>
                    </button>

                    <button type="button" wire:click="$set('opening_context', 'carry_forward')"
                        class="rounded-2xl border px-6 py-6 text-left transition {{ $opening_context === 'carry_forward' ? 'border-blue-600 bg-blue-50 ring-2 ring-blue-200' : 'border-slate-200 hover:border-slate-300' }}">
                        <p class="text-lg font-semibold text-slate-900">{{ $this->yearLabel() }}より前から事業を続けている</p>
                        <p class="mt-3 text-sm leading-6 text-slate-600">
                            {{ $this->yearLabel() }}の開始時点へ、前年の決算書から現金・銀行口座・売掛金・借入金などを引き継ぎます。詳しい設定は、Soler を始めたあとに行います。
                        </p>
                    </button>
                </div>

                @error('opening_context')
                    <p class="text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
        @endif

        @if ($step === 4)
            <div class="space-y-8">
                <div class="space-y-2">
                    <h2 class="text-3xl font-semibold tracking-tight text-slate-900">これから必要になる設定を確認します</h2>
                </div>

                <div class="rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm leading-6 text-amber-900 shadow-sm">
                    <p class="font-semibold">ここで選んだ内容は、使い始めてから変更できます。</p>
                    <p class="mt-1">あとで修正できるので、今わかる範囲で選んで大丈夫です。</p>
                </div>

                <div class="space-y-5">
                    @foreach ($setupQuestions as $question)
                        <section class="rounded-2xl border border-slate-200 p-5">
                            <h3 class="text-base font-semibold text-slate-900">{{ $question['title'] }}</h3>
                            <p class="mt-2 text-sm leading-6 text-slate-600">{{ $question['description'] }}</p>

                            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                @foreach (['yes' => 'はい', 'no' => 'いいえ'] as $value => $label)
                                    <button type="button" wire:click="$set('{{ $question['field'] }}', '{{ $value }}')"
                                        class="rounded-xl border px-4 py-3 text-sm font-medium transition {{ data_get($this, $question['field']) === $value ? 'border-blue-600 bg-blue-50 text-blue-700 ring-2 ring-blue-200' : 'border-slate-200 text-slate-700 hover:border-slate-300' }}">
                                        {{ $label }}
                                    </button>
                                @endforeach
                            </div>

                            @error($question['field'])
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </section>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($step === 5)
            <div class="space-y-6">
                <div class="space-y-2">
                    <h2 class="text-3xl font-semibold tracking-tight text-slate-900">{{ $year }} 年の消費税の申告は必要ですか？</h2>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <button type="button" wire:click="$set('is_taxable', true)"
                        class="rounded-2xl border px-6 py-6 text-left transition {{ $is_taxable ? 'border-blue-600 bg-blue-50 ring-2 ring-blue-200' : 'border-slate-200 hover:border-slate-300' }}">
                        <p class="text-lg font-semibold text-slate-900">必要</p>
                        <p class="mt-3 text-sm leading-6 text-slate-600">課税事業者として記録します。</p>
                    </button>

                    <button type="button" wire:click="$set('is_taxable', false)"
                        class="rounded-2xl border px-6 py-6 text-left transition {{ ! $is_taxable ? 'border-blue-600 bg-blue-50 ring-2 ring-blue-200' : 'border-slate-200 hover:border-slate-300' }}">
                        <p class="text-lg font-semibold text-slate-900">不要</p>
                        <p class="mt-3 text-sm leading-6 text-slate-600">免税事業者として記録します。</p>
                    </button>
                </div>

                @error('is_taxable')
                    <p class="text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
        @endif

        @if ($step === 6)
            <div class="space-y-8">
                <div class="space-y-2">
                    <h2 class="text-3xl font-semibold tracking-tight text-slate-900">Soler を始める準備ができました</h2>
                    <p class="text-sm leading-6 text-slate-600">
                        ここまでの内容をもとに、Soler を使い始める準備をします。詳しい設定が必要なものは、Dashboard に表示されます。
                    </p>
                </div>

                <dl class="grid gap-4 rounded-2xl bg-slate-50 p-6 sm:grid-cols-2">
                    <div>
                        <dt class="text-sm font-medium text-slate-500">事業名</dt>
                        <dd class="mt-1 text-base font-semibold text-slate-900">{{ $name }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-slate-500">記録を始める年</dt>
                        <dd class="mt-1 text-base font-semibold text-slate-900">{{ $year }} 年</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-slate-500">開始状態</dt>
                        <dd class="mt-1 text-base font-semibold text-slate-900">
                            {{ $opening_context === 'carry_forward' ? '前年以前から事業を続けている' : 'この年に新しく事業を始めた' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-slate-500">消費税</dt>
                        <dd class="mt-1 text-base font-semibold text-slate-900">{{ $this->consumptionTaxStatusLabel() }}</dd>
                    </div>
                </dl>

                <div class="rounded-2xl border border-slate-200 p-6">
                    <h3 class="text-base font-semibold text-slate-900">Dashboard に表示される開始準備</h3>
                    <ul class="mt-4 space-y-3 text-sm text-slate-700">
                        @foreach ($setupQuestions as $question)
                            @php($answer = data_get($this, $question['field']))
                            <li class="flex items-start justify-between gap-4 border-b border-slate-100 pb-3 last:border-b-0 last:pb-0">
                                <span class="flex-1">{{ $question['title'] }}</span>
                                <span class="shrink-0 font-semibold text-slate-900">{{ $this->answerLabel($answer) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        <div class="mt-10 flex justify-between">
            @if ($step > 1)
                <button wire:click="$set('step', {{ $step - 1 }})" class="rounded-xl bg-slate-200 px-6 py-3 text-slate-800">
                    戻る
                </button>
            @else
                <div></div>
            @endif

            @if ($step < 6)
                <button wire:click="next" class="rounded-xl bg-blue-600 px-6 py-3 text-white">
                    次へ
                </button>
            @else
                <button wire:click="submit" class="rounded-xl bg-emerald-600 px-6 py-3 text-white">
                    Soler を始める
                </button>
            @endif
        </div>
    </div>
</div>
