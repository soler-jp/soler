@extends('layouts.setup')

@section('title', '初期セットアップ')

@section('content')
    <div x-data="{ stage: 'welcome' }"
        @onboarding-guide-completed.window="stage = 'setup'; window.scrollTo({ top: 0, behavior: 'smooth' })"
        class="mx-auto w-full max-w-5xl px-4 py-10 sm:px-6 lg:px-8">
        <div x-show="stage === 'welcome'" class="space-y-8">
            <div
                class="space-y-8 overflow-hidden rounded-[2rem] border border-slate-200 bg-[radial-gradient(circle_at_top_left,_rgba(14,165,233,0.14),_transparent_30%),radial-gradient(circle_at_top_right,_rgba(168,85,247,0.12),_transparent_32%),linear-gradient(180deg,_#fffdf8_0%,_#ffffff_55%,_#f8fafc_100%)] p-7 shadow-sm sm:p-10">
                <div class="space-y-6">
                    <div class="space-y-3">
                        <p class="text-sm font-semibold uppercase tracking-[0.22em] text-slate-500">Soler Onboarding</p>
                        <h1 class="text-4xl font-semibold tracking-tight text-slate-900 sm:text-5xl">
                            ようこそ、Solerへ。
                        </h1>
                        <p class="text-2xl leading-9 text-slate-700">
                            あなたの会計を、Solerが支えます。
                        </p>
                    </div>
                    <div class="space-y-3 text-base leading-8 text-slate-600">
                        <p>Solerを選んでくれてありがとうございます。</p>
                        <p>「会計は難しい」と感じている人でも、自分のお金を把握しながら事業を続けられて、確定申告の前に焦らずに済む。そんな会計ソフトを目指しています。</p>
                        <p>以下の3ステップで始めていきましょう。</p>
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-3">
                    <section class="rounded-2xl border border-sky-200/80 bg-white/80 p-5 backdrop-blur-sm">
                        <p class="text-sm font-semibold tracking-[0.04em] text-sky-700">1. 会計の基本</p>
                        <p class="mt-3 text-sm leading-6 text-slate-600">
                            なぜ記録するのか、何を分けて考えるのか。会計の基本を、短く確認します。
                        </p>
                    </section>

                    <section class="rounded-2xl border border-emerald-200/80 bg-white/80 p-5 backdrop-blur-sm">
                        <p class="text-sm font-semibold tracking-[0.04em] text-emerald-700">2. 初期設定</p>
                        <p class="mt-3 text-sm leading-6 text-slate-600">
                            あなたの事業のことを Soler に教えてください。使い始めるための最低限だけを、順に案内します。
                        </p>
                    </section>

                    <section class="rounded-2xl border border-violet-200/80 bg-white/80 p-5 backdrop-blur-sm">
                        <p class="text-sm font-semibold tracking-[0.04em] text-violet-700">3. 利用開始</p>
                        <p class="mt-3 text-sm leading-6 text-slate-600">
                            初期設定が終わったら、そのまま使い始められます。あとから必要になる設定は、Dashboard で順に案内します。
                        </p>
                    </section>
                </div>

                <div class="flex justify-end border-t border-slate-200/80 pt-6">
                    <button type="button"
                        @click="stage = 'guide'; window.scrollTo({ top: 0, behavior: 'smooth' })"
                        class="rounded-xl bg-slate-900 px-6 py-3 text-white shadow-sm transition hover:bg-slate-800">
                        会計の基本へ
                    </button>
                </div>
            </div>
        </div>

        <div x-show="stage === 'guide'" style="display: none;" class="space-y-8">
            <div class="space-y-4">
                <p class="text-sm font-semibold uppercase tracking-[0.22em] text-blue-700">Soler Onboarding</p>
                <h1 class="text-4xl font-semibold tracking-tight text-slate-900">まずは、会計の基本を確認します</h1>
            </div>

            @include('setup.partials.accounting-guide', ['mode' => 'initialize'])
        </div>

        <div x-show="stage === 'setup'" style="display: none;" class="space-y-10">
            <div class="mb-10 space-y-4">
                <p class="text-sm font-semibold uppercase tracking-[0.22em] text-blue-700">Soler Setup Wizard</p>
                <h1 class="text-4xl font-semibold tracking-tight text-slate-900">初期セットアップ</h1>
                <p class="text-base leading-7 text-slate-600">
                    まずは、Soler を始めるための基本設定だけを行います。詳しい設定が必要なものは、あとで Dashboard から順番に進められます。
                </p>
            </div>

            <livewire:setup-wizard />
        </div>
    </div>
@endsection
