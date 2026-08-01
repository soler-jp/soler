@php
    // 色分けルール:
    //   sky     = 売上 (お金の種類、再登場する用語)
    //   rose    = 経費
    //   amber   = 仕入
    //   emerald = 具体的な経費項目 (スマホ代、家賃など)
    //   violet  = 会計の専門用語 (勘定科目)
    $pages = [
        [
            'title' => '個人事業主は、1年分の事業をまとめます',
            'body' => [
                '個人事業主になると、1年分の事業の結果をまとめる必要があります。',
                '1年間に事業で<span class="rounded-sm bg-sky-100 px-1 py-0.5 font-semibold text-sky-900">どれだけのお金が入り</span>、<span class="rounded-sm bg-rose-100 px-1 py-0.5 font-semibold text-rose-900">どれだけ使ったか</span>を集計すると、利益がわかります。この集計結果をまとめたものが決算書で、この集計作業を「決算」といいます。',
                '日々のお金の動きを記録しておくことで、この決算がスムーズに進みます。',
            ],
        ],
        [
            'title' => '事業のお金は、まず3つに分けます',
            'body' => [
                '事業のお金の動きは、まず「<span class="rounded-sm bg-sky-100 px-1 py-0.5 font-semibold text-sky-900">売上</span>」「<span class="rounded-sm bg-rose-100 px-1 py-0.5 font-semibold text-rose-900">経費</span>」「<span class="rounded-sm bg-amber-100 px-1 py-0.5 font-semibold text-amber-900">仕入</span>」の3つに分けて考えます。',
                '<span class="rounded-sm bg-sky-100 px-1 py-0.5 font-semibold text-sky-900">売上</span>は、事業に入ってきたお金です。<span class="ml-2 text-sm text-slate-500">(仕事をして受け取ったお金、雑貨店ならお客さんから受け取ったお金 など)</span>',
                '<span class="rounded-sm bg-rose-100 px-1 py-0.5 font-semibold text-rose-900">経費</span>は、売上を上げるために使ったお金です。<span class="ml-2 text-sm text-slate-500">(月々の携帯電話代、交通費、家賃 など)</span>',
                '<span class="rounded-sm bg-amber-100 px-1 py-0.5 font-semibold text-amber-900">仕入</span>は、売るために購入した商品や材料です。<span class="ml-2 text-sm text-slate-500">(雑貨店なら仕入れた商品、飲食店なら食材 など)</span>',
                'まずは、記録するお金がどれに近いかを考えられれば十分です。',
            ],
        ],
        [
            'title' => '経費は、種類ごとに分ける必要があります',
            'body' => [
                '<span class="rounded-sm bg-rose-100 px-1 py-0.5 font-semibold text-rose-900">経費</span>は、全部まとめて経費として記録するのではなく、何に使ったお金なのかを種類ごとに分けます。',
                'たとえば、<span class="rounded-sm bg-emerald-100 px-1 py-0.5 font-semibold text-emerald-900">携帯電話代</span>、<span class="rounded-sm bg-emerald-100 px-1 py-0.5 font-semibold text-emerald-900">交通費</span>、<span class="rounded-sm bg-emerald-100 px-1 py-0.5 font-semibold text-emerald-900">仕事道具</span>、<span class="rounded-sm bg-emerald-100 px-1 py-0.5 font-semibold text-emerald-900">広告費</span>などです。',
                '会計では、この分類の名前を<span class="rounded-sm bg-violet-100 px-1 py-0.5 font-semibold text-violet-900">勘定科目</span>と呼びます。',
                'なお、自宅を事務所として使っている場合、「<span class="rounded-sm bg-emerald-100 px-1 py-0.5 font-semibold text-emerald-900">自宅の家賃</span>」や「<span class="rounded-sm bg-emerald-100 px-1 py-0.5 font-semibold text-emerald-900">自宅の電気代</span>」のように、仕事と私生活で共有する支払いが出てきます。こうしたものは、仕事で使った分だけを経費として記録します。',
            ],
        ],
        [
            'title' => '次は、Solerを始める準備をします',
            'body' => [
                'ここまでで、個人事業主の経理の基本を確認しました。次は、Soler を使い始めるための初期設定を行います。',
            ],
        ],
    ];
@endphp

<div x-data="{ guidePage: 1 }" class="rounded-[2rem] border border-slate-200 bg-white p-6 shadow-sm sm:p-10">
    <div class="space-y-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="rounded-full bg-slate-100 px-4 py-2 text-sm font-medium text-slate-600">
                <span x-text="`${guidePage} / {{ count($pages) }}`"></span>
            </div>
        </div>

        @foreach ($pages as $pageIndex => $page)
            <section x-show="guidePage === {{ $pageIndex + 1 }}" class="space-y-6">
                <div class="space-y-3">
                    <h2 class="text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">{{ $page['title'] }}</h2>
                </div>

                <div class="space-y-4 text-base leading-8 text-slate-700">
                    @foreach ($page['body'] as $paragraph)
                        <p>{!! $paragraph !!}</p>
                    @endforeach
                </div>
            </section>
        @endforeach

        <div class="flex items-center justify-between gap-4 border-t border-slate-200 pt-6">
            <button type="button"
                @click="guidePage = Math.max(1, guidePage - 1)"
                :disabled="guidePage === 1"
                class="rounded-xl border border-slate-300 px-6 py-3 text-slate-800 transition disabled:cursor-not-allowed disabled:opacity-40">
                戻る
            </button>

            <div class="flex items-center gap-3">
                <button type="button"
                    x-show="guidePage < {{ count($pages) }}"
                    @click="guidePage = Math.min({{ count($pages) }}, guidePage + 1)"
                    class="rounded-xl bg-blue-600 px-6 py-3 text-white">
                    次へ
                </button>

                @if (($mode ?? 'initialize') === 'initialize')
                    <button type="button"
                        x-show="guidePage === {{ count($pages) }}"
                        @click="$dispatch('onboarding-guide-completed')"
                        class="rounded-xl bg-emerald-600 px-6 py-3 text-white">
                        初期設定へ進む
                    </button>
                @else
                    <a href="{{ route('initialize') }}"
                        x-show="guidePage === {{ count($pages) }}"
                        class="rounded-xl bg-blue-600 px-6 py-3 text-white">
                        初期設定に戻る
                    </a>
                @endif
            </div>
        </div>

        @if (($mode ?? 'initialize') === 'initialize')
            <div class="pt-3 text-right">
                <p class="text-sm leading-6 text-slate-500">
                    会計の基本は、あとから<a href="{{ route('help.accounting-basics') }}"
                        class="font-medium underline underline-offset-2">Help</a>でいつでも見直せます。
                </p>
            </div>
        @endif
    </div>
</div>
