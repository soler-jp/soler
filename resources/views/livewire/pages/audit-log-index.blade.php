<div class="py-8">
    <div class="mx-auto space-y-6 px-4 sm:px-6 lg:px-8">
        <x-ui.card>
            <x-ui.card-body class="space-y-6 p-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div class="space-y-2">
                        <div>
                            <h1 class="text-2xl font-semibold text-content">{{ __('audit_logs.title') }}</h1>
                            <p class="text-sm leading-6 text-content-muted">{{ __('audit_logs.description') }}</p>
                        </div>

                        <div class="inline-flex items-center gap-2 rounded-card border border-line bg-surface px-3 py-2 text-sm text-content">
                            <span class="font-semibold text-content-muted">{{ __('audit_logs.showing_fiscal_year') }}</span>
                            <span class="font-semibold">{{ $this->fiscalYear->year }}年度</span>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <div class="rounded-card border border-line bg-surface px-4 py-2 text-sm text-content">
                            <span class="font-semibold text-content-muted">{{ __('audit_logs.record_count') }}</span>
                            <span class="ml-2 font-semibold">{{ $this->logs->total() }}</span>
                        </div>

                        <label class="flex items-center gap-2 text-sm text-content-muted">
                            <span>{{ __('audit_logs.per_page') }}</span>
                            <select wire:model.live="perPage"
                                class="rounded-control border border-line bg-surface px-3 py-2 text-sm text-content focus:outline-none focus:ring-2 focus:ring-focus">
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </label>
                    </div>
                </div>

                <div class="overflow-hidden rounded-card border border-line">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-line">
                            <thead class="bg-surface-muted">
                                <tr class="text-left text-xs font-semibold uppercase tracking-[0.14em] text-content-muted">
                                    <th class="px-4 py-3">{{ __('audit_logs.columns.recorded_at') }}</th>
                                    <th class="px-4 py-3">{{ __('audit_logs.columns.event') }}</th>
                                    <th class="px-4 py-3">{{ __('audit_logs.columns.actor') }}</th>
                                    <th class="px-4 py-3">{{ __('audit_logs.transaction.fields.voucher_number') }}</th>
                                    <th class="px-4 py-3">{{ __('audit_logs.transaction.fields.date') }}</th>
                                    <th class="px-4 py-3">{{ __('audit_logs.transaction.fields.description') }}</th>
                                    <th class="px-4 py-3 text-right">{{ __('audit_logs.transaction.fields.amount') }}</th>
                                    <th class="px-4 py-3">{{ __('audit_logs.columns.reason') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line bg-surface">
                                @forelse ($this->logs as $log)
                                    @php($sourceTransaction = $this->sourceTransactionRow($log))
                                    <tr wire:key="audit-log-{{ $log->id }}" class="align-top">
                                        <td class="whitespace-nowrap px-4 py-3 text-sm text-content-muted">
                                            {{ $log->recorded_at?->format('Y-m-d H:i:s') }}
                                        </td>
                                        <td class="px-4 py-3 text-sm font-semibold text-content">
                                            {{ $this->eventLabel($log) }}
                                        </td>
                                        <td class="px-4 py-3 text-sm text-content">
                                            {{ $this->actorLabel($log) }}
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-sm text-content">
                                            {{ $sourceTransaction['voucher_number'] ?? '-' }}
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-sm text-content">
                                            {{ $sourceTransaction['date'] ?? '-' }}
                                        </td>
                                        <td class="px-4 py-3 text-sm text-content">
                                            {{ $sourceTransaction['description'] ?? '-' }}
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-content">
                                            {{ $sourceTransaction['amount'] ?? '-' }}
                                        </td>
                                        <td class="px-4 py-3 text-sm text-content-muted">
                                            {{ $this->reasonLabel($log) }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="px-4 py-10 text-center text-sm text-content-muted">
                                            {{ __('audit_logs.empty') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="border-t border-line bg-surface px-4 py-3">
                        {{ $this->logs->links() }}
                    </div>
                </div>
            </x-ui.card-body>
        </x-ui.card>
    </div>
</div>
