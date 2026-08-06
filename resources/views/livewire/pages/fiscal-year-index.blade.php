<div class="py-8">
    <div class="mx-auto flex max-w-6xl flex-col gap-6 px-4 sm:px-6 lg:px-8">
        <section class="overflow-hidden border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-col gap-3 border-b border-slate-200 bg-slate-50 px-6 py-5 sm:flex-row sm:items-end sm:justify-between">
                <div class="space-y-2">
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Fiscal Years</p>
                    <div class="space-y-1">
                        <h1 class="text-2xl font-semibold text-slate-900">年度管理</h1>
                        <p class="text-sm leading-6 text-slate-600">
                            表示年度の切り替え、年度の締め、繰越内容の確認と翌年度作成をここで行えます。
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="border border-slate-200 bg-white px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">表示中</p>
                        <p class="mt-1 text-lg font-semibold text-slate-900">{{ $currentFiscalYearLabel }}</p>
                    </div>
                    <div class="border border-slate-200 bg-white px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">繰越作成</p>
                        <p class="mt-1 text-sm leading-6 text-slate-700">
                            繰越仕訳を確認し、翌年度の税設定を決めてから作成します。
                        </p>
                    </div>
                </div>
            </div>

            @if ($noticeMessage)
                <div class="border-b border-emerald-200 bg-emerald-50 px-6 py-4 text-sm text-emerald-800">
                    {{ $noticeMessage }}
                </div>
            @endif

            @if ($errorMessage)
                <div class="border-b border-rose-200 bg-rose-50 px-6 py-4 text-sm text-rose-800">
                    {{ $errorMessage }}
                </div>
            @endif

            <div class="overflow-x-auto">
                <table class="min-w-full table-fixed">
                    <thead class="border-b border-slate-200 bg-white text-left">
                        <tr class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">
                            <th class="px-6 py-4">年度</th>
                            <th class="px-4 py-4">状態</th>
                            <th class="px-4 py-4">税設定</th>
                            <th class="px-6 py-4 text-right">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        @forelse ($fiscalYears as $fiscalYear)
                            <tr wire:key="fiscal-year-{{ $fiscalYear['id'] }}" class="align-middle">
                                <td class="px-6 py-4">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-lg font-semibold text-slate-900">{{ $fiscalYear['year'] }}年度</span>

                                        @if ($currentFiscalYearId === $fiscalYear['id'])
                                            <span class="inline-flex items-center border border-emerald-300 bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">
                                                表示中
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <span @class([
                                        'inline-flex items-center border px-2.5 py-1 text-xs font-semibold',
                                        'border-slate-300 bg-slate-100 text-slate-700' => $fiscalYear['is_closed'],
                                        'border-sky-200 bg-sky-50 text-sky-700' => ! $fiscalYear['is_closed'],
                                    ])>
                                        {{ $fiscalYear['is_closed'] ? '締め済み' : '進行中' }}
                                    </span>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="flex flex-wrap gap-2">
                                        <span @class([
                                            'inline-flex items-center border px-2.5 py-1 text-xs font-semibold',
                                            'border-amber-200 bg-amber-50 text-amber-700' => $fiscalYear['is_taxable'],
                                            'border-slate-200 bg-white text-slate-600' => ! $fiscalYear['is_taxable'],
                                        ])>
                                            {{ $fiscalYear['is_taxable'] ? '課税' : '免税' }}
                                        </span>

                                        <span class="inline-flex items-center border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-600">
                                            {{ $fiscalYear['is_tax_exclusive'] ? '税抜経理' : '税込経理' }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    @php
                                        $isCurrent = $currentFiscalYearId === $fiscalYear['id'];
                                    @endphp
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <button
                                            type="button"
                                            wire:click="switchFiscalYear({{ $fiscalYear['id'] }})"
                                            @disabled($isCurrent)
                                            class="inline-flex items-center justify-center border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:border-slate-200 disabled:text-slate-400"
                                        >
                                            {{ $isCurrent ? '表示中です' : 'この年度を見る' }}
                                        </button>

                                        @if ($isCurrent && $fiscalYear['can_close'])
                                            <a
                                                href="{{ route('fiscal-year-closing') }}"
                                                wire:navigate
                                                class="inline-flex items-center justify-center border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-800 transition hover:bg-amber-100"
                                            >
                                                1年のまとめをする
                                            </a>
                                            <button
                                                type="button"
                                                wire:click="openCloseConfirm({{ $fiscalYear['id'] }})"
                                                class="inline-flex items-center justify-center border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-800 transition hover:bg-amber-100"
                                            >
                                                この年度を締める
                                            </button>
                                        @elseif ($fiscalYear['can_create_rollover'])
                                            <button
                                                type="button"
                                                wire:click="openRolloverConfirm({{ $fiscalYear['id'] }})"
                                                class="inline-flex items-center justify-center border border-emerald-300 bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700"
                                            >
                                                {{ $fiscalYear['next_year'] }}年度の繰越内容を確認
                                            </button>
                                        @elseif ($fiscalYear['is_closed'])
                                            <span class="inline-flex items-center border border-slate-200 bg-slate-50 px-4 py-2 text-sm text-slate-500">
                                                翌年度は作成済み
                                            </span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-12 text-center text-sm text-slate-500">
                                    会計年度がまだありません。初期設定から年度を作成してください。
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div wire:show="showCloseConfirmModal" x-transition.duration.200ms class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-0">
        <div class="fixed inset-0 bg-slate-900/50" wire:click="closeConfirmModal"></div>

        <div class="relative mx-auto mb-6 w-full max-w-2xl overflow-hidden border border-slate-200 bg-white shadow-xl">
            <div class="border-b border-slate-200 px-6 py-4">
                <h2 class="text-lg font-semibold text-slate-900">年度を締める前の確認</h2>
                <p class="mt-1 text-sm text-slate-600">締め前チェックの結果を確認してから実行します。</p>
            </div>

            <div class="space-y-5 px-6 py-5">
                <div>
                    <p class="text-sm font-semibold text-slate-900">チェック結果</p>
                    @if ($closeValidation['closable'])
                        <p class="mt-2 border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                            この年度は締められます。
                        </p>
                    @else
                        <div class="mt-2 space-y-2">
                            @foreach ($closeErrorMessages as $message)
                                <p class="border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ $message }}</p>
                            @endforeach
                        </div>
                    @endif
                </div>

                @if ($closeWarningMessages !== [])
                    <div>
                        <p class="text-sm font-semibold text-slate-900">注意</p>
                        <div class="mt-2 space-y-2">
                            @foreach ($closeWarningMessages as $message)
                                <p class="border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ $message }}</p>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <div class="flex flex-col-reverse gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4 sm:flex-row sm:justify-end">
                <button type="button" wire:click="closeConfirmModal" class="inline-flex items-center justify-center border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
                    キャンセル
                </button>
                <button
                    type="button"
                    wire:click="confirmCloseFiscalYear"
                    @disabled(! $closeValidation['closable'])
                    class="inline-flex items-center justify-center border border-amber-300 bg-amber-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-700 disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-300"
                >
                    <span wire:loading.remove wire:target="confirmCloseFiscalYear">この内容で締める</span>
                    <span wire:loading wire:target="confirmCloseFiscalYear">締めています...</span>
                </button>
            </div>
        </div>
    </div>

    <div wire:show="showRolloverConfirmModal" x-transition.duration.200ms class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-0">
        <div class="fixed inset-0 bg-slate-900/50" wire:click="closeRolloverConfirmModal"></div>

        <div class="relative mx-auto mb-6 w-full max-w-4xl overflow-hidden border border-slate-200 bg-white shadow-xl">
            <div class="border-b border-slate-200 px-6 py-4">
                <h2 class="text-lg font-semibold text-slate-900">繰越データの確認</h2>
                <p class="mt-1 text-sm text-slate-600">翌年度へ作る期首仕訳と、翌年度の税設定を確認してから実行します。</p>
            </div>

            <div class="grid gap-6 px-6 py-5 lg:grid-cols-[minmax(0,1.4fr)_minmax(20rem,0.8fr)]">
                <div class="space-y-5">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="border border-slate-200 bg-slate-50 px-4 py-3">
                            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">作成年度</p>
                            <p class="mt-1 text-lg font-semibold text-slate-900">{{ $rolloverPreview['next_year'] ? $rolloverPreview['next_year'].'年度' : '-' }}</p>
                        </div>
                        <div class="border border-slate-200 bg-slate-50 px-4 py-3">
                            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">当期利益</p>
                            <p class="mt-1 text-lg font-semibold text-slate-900">{{ number_format($rolloverPreview['current_profit']) }}円</p>
                        </div>
                        <div class="border border-slate-200 bg-slate-50 px-4 py-3">
                            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">期首仕訳件数</p>
                            <p class="mt-1 text-lg font-semibold text-slate-900">{{ count($rolloverPreview['opening_entries']) + ($rolloverPreview['capital_entry'] ? 1 : 0) }}件</p>
                        </div>
                    </div>

                    <div class="overflow-hidden border border-slate-200">
                        <div class="border-b border-slate-200 bg-slate-50 px-4 py-3">
                            <h3 class="text-sm font-semibold text-slate-900">翌年度の期首仕訳プレビュー</h3>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full table-auto text-sm">
                                <thead class="bg-white text-slate-500">
                                    <tr>
                                        <th class="px-4 py-3 text-left font-semibold">区分</th>
                                        <th class="px-4 py-3 text-left font-semibold">勘定科目</th>
                                        <th class="px-4 py-3 text-left font-semibold">補助科目</th>
                                        <th class="px-4 py-3 text-right font-semibold">金額</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200">
                                    @foreach ($rolloverPreview['opening_entries'] as $entry)
                                        <tr>
                                            <td class="px-4 py-3">
                                                <span @class([
                                                    'inline-flex items-center border px-2 py-1 text-xs font-semibold',
                                                    'border-sky-200 bg-sky-50 text-sky-700' => $entry['type'] === 'debit',
                                                    'border-amber-200 bg-amber-50 text-amber-700' => $entry['type'] === 'credit',
                                                ])>
                                                    {{ $entry['type'] === 'debit' ? '借方' : '貸方' }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 text-slate-900">{{ $entry['account_name'] }}</td>
                                            <td class="px-4 py-3 text-slate-600">{{ $entry['sub_account_name'] }}</td>
                                            <td class="px-4 py-3 text-right font-semibold text-slate-900">{{ number_format($entry['amount']) }}円</td>
                                        </tr>
                                    @endforeach

                                    @if ($rolloverPreview['capital_entry'])
                                        <tr class="bg-slate-50/70">
                                            <td class="px-4 py-3">
                                                <span @class([
                                                    'inline-flex items-center border px-2 py-1 text-xs font-semibold',
                                                    'border-sky-200 bg-sky-50 text-sky-700' => $rolloverPreview['capital_entry']['type'] === 'debit',
                                                    'border-amber-200 bg-amber-50 text-amber-700' => $rolloverPreview['capital_entry']['type'] === 'credit',
                                                ])>
                                                    {{ $rolloverPreview['capital_entry']['type'] === 'debit' ? '借方' : '貸方' }}
                                                </span>
                                            </td>
                                            <td class="px-4 py-3 font-semibold text-slate-900">{{ $rolloverPreview['capital_entry']['account_name'] }}</td>
                                            <td class="px-4 py-3 text-slate-600">{{ $rolloverPreview['capital_entry']['sub_account_name'] }}</td>
                                            <td class="px-4 py-3 text-right font-semibold text-slate-900">{{ number_format($rolloverPreview['capital_entry']['amount']) }}円</td>
                                        </tr>
                                    @endif
                                </tbody>
                                <tfoot class="border-t border-slate-200 bg-slate-50 text-sm">
                                    <tr>
                                        <td colspan="3" class="px-4 py-3 text-right font-semibold text-slate-600">借方合計</td>
                                        <td class="px-4 py-3 text-right font-semibold text-slate-900">{{ number_format($rolloverDebitTotal) }}円</td>
                                    </tr>
                                    <tr>
                                        <td colspan="3" class="px-4 py-3 text-right font-semibold text-slate-600">貸方合計</td>
                                        <td class="px-4 py-3 text-right font-semibold text-slate-900">{{ number_format($rolloverCreditTotal) }}円</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="space-y-5">
                    <section class="border border-slate-200 bg-slate-50 px-5 py-4">
                        <h3 class="text-sm font-semibold text-slate-900">翌年度の設定</h3>

                        <div class="mt-4">
                            <div>
                                <p class="text-sm font-medium text-slate-700">課税区分</p>
                                <div class="mt-2 grid gap-2">
                                    <label @class([
                                        'flex cursor-pointer items-start gap-3 border px-3 py-3 transition',
                                        'border-slate-200 bg-white hover:border-slate-300' => $nextYearIsTaxable !== false,
                                        'border-blue-600 bg-blue-600 text-white' => $nextYearIsTaxable === false,
                                    ])>
                                        <input type="radio" wire:model.live="nextYearIsTaxable" value="0" class="sr-only">
                                        <span>
                                            <span @class([
                                                'block text-sm font-semibold',
                                                'text-slate-900' => $nextYearIsTaxable !== false,
                                                'text-white' => $nextYearIsTaxable === false,
                                            ])>免税業者</span>
                                            <span @class([
                                                'block text-xs',
                                                'text-slate-500' => $nextYearIsTaxable !== false,
                                                'text-slate-200' => $nextYearIsTaxable === false,
                                            ])>翌年度を免税業者として作成します。</span>
                                        </span>
                                    </label>
                                    <label @class([
                                        'flex cursor-pointer items-start gap-3 border px-3 py-3 transition',
                                        'border-slate-200 bg-white hover:border-slate-300' => $nextYearIsTaxable !== true,
                                        'border-blue-600 bg-blue-600 text-white' => $nextYearIsTaxable === true,
                                    ])>
                                        <input type="radio" wire:model.live="nextYearIsTaxable" value="1" class="sr-only">
                                        <span>
                                            <span @class([
                                                'block text-sm font-semibold',
                                                'text-slate-900' => $nextYearIsTaxable !== true,
                                                'text-white' => $nextYearIsTaxable === true,
                                            ])>課税業者</span>
                                            <span @class([
                                                'block text-xs',
                                                'text-slate-500' => $nextYearIsTaxable !== true,
                                                'text-slate-200' => $nextYearIsTaxable === true,
                                            ])>翌年度を課税業者として作成します。</span>
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </section>
                </div>
            </div>

            <div class="flex flex-col-reverse gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4 sm:flex-row sm:justify-end">
                <button type="button" wire:click="closeRolloverConfirmModal" class="inline-flex items-center justify-center border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
                    キャンセル
                </button>
                <button
                    type="button"
                    wire:click="confirmCreateNextFiscalYearFromRollover"
                    class="inline-flex items-center justify-center border border-emerald-300 bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700"
                >
                    <span wire:loading.remove wire:target="confirmCreateNextFiscalYearFromRollover">この内容で翌年度を作成</span>
                    <span wire:loading wire:target="confirmCreateNextFiscalYearFromRollover">作成しています...</span>
                </button>
            </div>
        </div>
    </div>
</div>
