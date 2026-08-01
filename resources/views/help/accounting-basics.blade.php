<x-app-layout>
    <div class="py-12">
        <div class="mx-auto w-full max-w-5xl px-4 sm:px-6 lg:px-8">
            <div class="mb-8 space-y-4">
                <p class="text-sm font-semibold uppercase tracking-[0.22em] text-blue-700">Help</p>
                <h1 class="text-4xl font-semibold tracking-tight text-slate-900">個人事業主としての会計の説明</h1>
                <p class="text-base leading-7 text-slate-600">
                    初期セットアップの前に読んだ内容を、あとからいつでも確認できます。
                </p>
            </div>

            @include('setup.partials.accounting-guide', ['mode' => 'help'])
        </div>
    </div>
</x-app-layout>
