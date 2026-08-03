@php
    // 色分けルール:
    //   sky     = 売上 (お金の種類、再登場する用語)
    //   rose    = 経費
    //   amber   = 仕入
    //   emerald = 具体的な経費項目 (スマホ代、家賃など)
    //   violet  = 会計の専門用語 (勘定科目)
    //   indigo  = お金の置き場所 (現金、銀行口座、クレジットカードなど)
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
            'title' => '経費は、「何のために使ったか」で決まります',
            'body' => [
                '経費は、仕事のために必要だった支払いです。',
                '同じ支払いでも、何のために使ったかによって、<span class="rounded-sm bg-rose-100 px-1 py-0.5 font-semibold text-rose-900">経費</span>になるかどうかが変わります。',
                'たとえば、同じカフェ代でも、仕事相手との打ち合わせなら経費、自分の昼食なら経費ではありません。',
                '高速道路で県外へ行った場合も、行った先で仕事をすればその高速代は経費ですが、仕事をしていなければ経費にはなりません。',
                '判断の軸は、いつも同じです。仕事のために使ったかどうかです。',
                '支払いの中には、仕事のために使った部分と、生活のために使った部分が混ざっているものもあります。たとえば自宅の家賃やスマートフォン代です。こうしたものは、仕事で使った分だけを経費と考えます。決まった割合はなく、実際の使い方に合わせて分けます。',
                '仕事のために必要だったものは、経費として計上できます。そうでないものは、計上できません。慣れないうちは悩むかもしれませんが、だんだんわかってきます。後から修正もできます。',
                '大切なのは、経費かそうでないかの「正解」を覚えることではありません。なぜそう判断したのか、その理由を自分で説明できるようにしておくことです。',
            ],
        ],
        [
            'title' => '経費は、まとめずに分けて記録します',
            'body' => [
                '経費は、<span class="rounded-sm bg-rose-100 px-1 py-0.5 font-semibold text-rose-900">経費</span>とひとまとめにするのではなく、何に使ったお金なのかがわかるように、種類ごとに分けて記録します。',
                'こうしておくと、あとから「何にいくら使ったのか」を見返せます。<span class="rounded-sm bg-emerald-100 px-1 py-0.5 font-semibold text-emerald-900">移動にかかったお金</span>、<span class="rounded-sm bg-emerald-100 px-1 py-0.5 font-semibold text-emerald-900">広告にかかったお金</span>、といった内訳が見えるようになります。',
                'この「種類」には、会計で使う決まった呼び名があります。これを<span class="rounded-sm bg-violet-100 px-1 py-0.5 font-semibold text-violet-900">勘定科目</span>と呼びます。たとえば、<span class="rounded-sm bg-emerald-100 px-1 py-0.5 font-semibold text-emerald-900">移動に使ったお金</span>は<span class="rounded-sm bg-violet-100 px-1 py-0.5 font-semibold text-violet-900">旅費交通費</span>、<span class="rounded-sm bg-emerald-100 px-1 py-0.5 font-semibold text-emerald-900">広告に使ったお金</span>は<span class="rounded-sm bg-violet-100 px-1 py-0.5 font-semibold text-violet-900">広告宣伝費</span>、<span class="rounded-sm bg-emerald-100 px-1 py-0.5 font-semibold text-emerald-900">仕事の勉強</span>に使ったお金は<span class="rounded-sm bg-violet-100 px-1 py-0.5 font-semibold text-violet-900">研修費</span>といった具合です。',
                '同じ経費でも、人によって選ぶ科目が違うことがあります。たとえば、仕事で使うペンやノートは、<span class="rounded-sm bg-violet-100 px-1 py-0.5 font-semibold text-violet-900">消耗品費</span>とする人もいれば<span class="rounded-sm bg-violet-100 px-1 py-0.5 font-semibold text-violet-900">事務用品費</span>とする人もいます。取引先との打ち合わせで払ったカフェ代も、<span class="rounded-sm bg-violet-100 px-1 py-0.5 font-semibold text-violet-900">会議費</span>と考える人もいれば<span class="rounded-sm bg-violet-100 px-1 py-0.5 font-semibold text-violet-900">接待交際費</span>とする人もいます。',
                '分け方に、たった一つの正解があるわけではありません。あまり気負わず、迷ったら近いものを選んでおけば十分です。',
            ],
        ],
        [
            'title' => '「どこから払ったか」「どこに入ったか」も記録します',
            'body' => [
                'お金の記録では、金額だけでなく<span class="rounded-sm bg-indigo-100 px-1 py-0.5 font-semibold text-indigo-900">お金の置き場所</span>も一緒に記録します。',
                '<span class="rounded-sm bg-rose-100 px-1 py-0.5 font-semibold text-rose-900">経費</span>や<span class="rounded-sm bg-amber-100 px-1 py-0.5 font-semibold text-amber-900">仕入</span>は、どこから払ったかを記録します。<span class="rounded-sm bg-indigo-100 px-1 py-0.5 font-semibold text-indigo-900">レジのお金で</span>、<span class="rounded-sm bg-indigo-100 px-1 py-0.5 font-semibold text-indigo-900">事業専用の銀行口座から</span>、<span class="rounded-sm bg-indigo-100 px-1 py-0.5 font-semibold text-indigo-900">個人のお金で立て替え</span>、などです。',
                '<span class="rounded-sm bg-sky-100 px-1 py-0.5 font-semibold text-sky-900">売上</span>も同じで、どこに入金されたかを記録します。<span class="rounded-sm bg-indigo-100 px-1 py-0.5 font-semibold text-indigo-900">レジに入金</span>、<span class="rounded-sm bg-indigo-100 px-1 py-0.5 font-semibold text-indigo-900">事業専用の銀行口座に振込</span>、<span class="rounded-sm bg-indigo-100 px-1 py-0.5 font-semibold text-indigo-900">財布に入れた</span>、などです。',
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
            <x-ui.button-back type="button"
                @click="guidePage = Math.max(1, guidePage - 1)"
                ::disabled="guidePage === 1" />

            <div class="flex items-center gap-3">
                <x-ui.button-next type="button"
                    x-show="guidePage < {{ count($pages) }}"
                    @click="guidePage = Math.min({{ count($pages) }}, guidePage + 1)" />

                @if (($mode ?? 'initialize') === 'initialize')
                    <x-ui.button-submit type="button"
                        x-show="guidePage === {{ count($pages) }}"
                        @click="$dispatch('onboarding-guide-completed')">
                        初期設定へ進む
                    </x-ui.button-submit>
                @else
                    <x-ui.button-back href="{{ route('initialize') }}"
                        x-show="guidePage === {{ count($pages) }}">
                        初期設定に戻る
                    </x-ui.button-back>
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
