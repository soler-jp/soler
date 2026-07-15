<x-app-layout>
    <div class="py-12">
        <div class="mx-auto flex max-w-5xl flex-col gap-6 px-4 sm:px-6 lg:px-8">
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 bg-slate-50 px-6 py-5">
                    <p class="text-sm font-semibold text-slate-900">青色申告決算書PDF</p>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        {{ $fiscalYear->year }}年度の青色申告決算書を、そのまま提出用PDFとして出力します。
                        既に保存済みの決算書入力と帳簿集計が反映されます。
                    </p>
                </div>

                <div class="grid gap-4 px-6 py-5 md:grid-cols-3">
                    <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">対象年度</p>
                        <p class="mt-2 text-lg font-semibold text-slate-900">{{ $fiscalYear->year }}年度</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">様式</p>
                        <p class="mt-2 text-lg font-semibold text-slate-900">{{ $templateLabel ?? '未対応' }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">出力</p>
                        <p class="mt-2 text-sm font-medium text-slate-900">4ページPDFをブラウザ表示</p>
                    </div>
                </div>
            </section>

            @if ($templateError)
                <section class="rounded-2xl border border-rose-200 bg-rose-50 px-6 py-5 text-sm text-rose-800">
                    {{ $templateError }}
                </section>
            @else
                <form method="POST" action="{{ route('blue-return-statement.pdf.download') }}"
                    class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                    @csrf

                    @if ($errors->has('fiscal_year'))
                        <div class="border-b border-rose-200 bg-rose-50 px-6 py-4 text-sm text-rose-800">
                            {{ $errors->first('fiscal_year') }}
                        </div>
                    @endif

                    <div class="border-b border-slate-200 px-6 py-5">
                        <h1 class="text-base font-semibold text-slate-900">出力設定</h1>
                        <p class="mt-2 text-sm leading-6 text-slate-600">
                            控除額とヘッダー欄だけ入力すれば出力できます。未入力のヘッダー項目は空欄のまま印字されます。
                        </p>
                    </div>

                    <div class="grid gap-6 px-6 py-6 lg:grid-cols-2">
                        <section class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-slate-50 px-5 py-5">
                            <div>
                                <x-input-label for="blue_return_deduction" value="青色申告特別控除額" />
                                <x-text-input id="blue_return_deduction" name="blue_return_deduction" type="number"
                                    min="0" step="1" class="mt-1 block w-full"
                                    :value="old('blue_return_deduction', $defaultValues['blue_return_deduction'])" required />
                                <x-input-error :messages="$errors->get('blue_return_deduction')" class="mt-2" />
                            </div>

                            <div>
                                <x-input-label for="filing_number" value="整理番号" />
                                <x-text-input id="filing_number" name="filing_number" type="text" class="mt-1 block w-full"
                                    :value="old('filing_number')" />
                                <x-input-error :messages="$errors->get('filing_number')" class="mt-2" />
                            </div>

                            <div>
                                <x-input-label for="business_type" value="業種名" />
                                <x-text-input id="business_type" name="business_type" type="text" class="mt-1 block w-full"
                                    :value="old('business_type')" />
                                <x-input-error :messages="$errors->get('business_type')" class="mt-2" />
                            </div>

                            <div>
                                <x-input-label for="trade_name" value="屋号" />
                                <x-text-input id="trade_name" name="trade_name" type="text" class="mt-1 block w-full"
                                    :value="old('trade_name')" />
                                <x-input-error :messages="$errors->get('trade_name')" class="mt-2" />
                            </div>

                            <div>
                                <x-input-label for="association_name" value="加入団体名" />
                                <x-text-input id="association_name" name="association_name" type="text" class="mt-1 block w-full"
                                    :value="old('association_name')" />
                                <x-input-error :messages="$errors->get('association_name')" class="mt-2" />
                            </div>
                        </section>

                        <section class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-slate-50 px-5 py-5">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <x-input-label for="name_kana" value="氏名フリガナ" />
                                    <x-text-input id="name_kana" name="name_kana" type="text" class="mt-1 block w-full"
                                        :value="old('name_kana')" />
                                    <x-input-error :messages="$errors->get('name_kana')" class="mt-2" />
                                </div>

                                <div>
                                    <x-input-label for="name" value="氏名" />
                                    <x-text-input id="name" name="name" type="text" class="mt-1 block w-full"
                                        :value="old('name', $defaultValues['name'])" />
                                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                                </div>
                            </div>

                            <div>
                                <x-input-label for="address" value="住所" />
                                <x-text-input id="address" name="address" type="text" class="mt-1 block w-full"
                                    :value="old('address')" />
                                <x-input-error :messages="$errors->get('address')" class="mt-2" />
                            </div>

                            <div>
                                <x-input-label for="business_address" value="事業所所在地" />
                                <x-text-input id="business_address" name="business_address" type="text"
                                    class="mt-1 block w-full" :value="old('business_address')" />
                                <x-input-error :messages="$errors->get('business_address')" class="mt-2" />
                            </div>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <x-input-label for="home_phone_number" value="自宅電話番号" />
                                    <x-text-input id="home_phone_number" name="home_phone_number" type="text"
                                        class="mt-1 block w-full" :value="old('home_phone_number')" />
                                    <x-input-error :messages="$errors->get('home_phone_number')" class="mt-2" />
                                </div>

                                <div>
                                    <x-input-label for="business_phone_number" value="事業所電話番号" />
                                    <x-text-input id="business_phone_number" name="business_phone_number" type="text"
                                        class="mt-1 block w-full" :value="old('business_phone_number')" />
                                    <x-input-error :messages="$errors->get('business_phone_number')" class="mt-2" />
                                </div>
                            </div>
                        </section>
                    </div>

                    <div class="border-t border-slate-200 px-6 py-6">
                        <h2 class="text-sm font-semibold text-slate-900">税理士記載欄</h2>

                        <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)]">
                            <div>
                                <x-input-label for="tax_accountant_office_address" value="税理士事務所所在地" />
                                <x-text-input id="tax_accountant_office_address" name="tax_accountant_office_address"
                                    type="text" class="mt-1 block w-full"
                                    :value="old('tax_accountant_office_address')" />
                                <x-input-error :messages="$errors->get('tax_accountant_office_address')" class="mt-2" />
                            </div>

                            <div>
                                <x-input-label for="tax_accountant_name" value="税理士氏名" />
                                <x-text-input id="tax_accountant_name" name="tax_accountant_name" type="text"
                                    class="mt-1 block w-full" :value="old('tax_accountant_name')" />
                                <x-input-error :messages="$errors->get('tax_accountant_name')" class="mt-2" />
                            </div>

                            <div>
                                <x-input-label for="tax_accountant_phone_number" value="税理士電話番号" />
                                <x-text-input id="tax_accountant_phone_number" name="tax_accountant_phone_number"
                                    type="text" class="mt-1 block w-full"
                                    :value="old('tax_accountant_phone_number')" />
                                <x-input-error :messages="$errors->get('tax_accountant_phone_number')" class="mt-2" />
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between gap-4 border-t border-slate-200 bg-slate-50 px-6 py-5">
                        <p class="text-sm text-slate-600">出力後はブラウザ上でPDFを確認し、そのまま保存・印刷できます。</p>
                        <x-primary-button>PDFを出力</x-primary-button>
                    </div>
                </form>
            @endif
        </div>
    </div>
</x-app-layout>
