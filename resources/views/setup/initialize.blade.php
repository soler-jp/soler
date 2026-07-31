@extends('layouts.setup')

@section('title', '初期セットアップ')

@section('content')
    <div class="mx-auto w-full max-w-5xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-10 space-y-4">
            <p class="text-sm font-semibold uppercase tracking-[0.22em] text-blue-700">Soler Setup Wizard</p>
            <h1 class="text-4xl font-semibold tracking-tight text-slate-900">初期セットアップ</h1>
            <p class="text-base leading-7 text-slate-600">
                まずは、Soler を始めるための基本設定だけを行います。詳しい設定が必要なものは、あとで Dashboard から順番に進められます。
            </p>
        </div>

        <livewire:setup-wizard />
    </div>
@endsection
