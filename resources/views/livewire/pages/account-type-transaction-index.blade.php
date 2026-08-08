<div class="py-8">
    @php($palette = $this->palette())

    <div class="mx-auto space-y-6 px-4 sm:px-6 lg:px-8">
        @if ($kind === 'revenue')
            <livewire:soler-ui.transaction-entry.revenue-form.standard />
        @elseif ($kind === 'expense')
            <livewire:soler-ui.transaction-entry.expense-form.standard />
        @elseif ($kind === 'purchase')
            <livewire:soler-ui.transaction-entry.purchase-form.standard />
        @elseif ($kind === \App\Livewire\Pages\AccountTypeTransactionIndex::KIND_OTHER)
            <livewire:soler-ui.transaction-entry.transfer-form.standard />
        @endif

        <section class="space-y-4">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="space-y-1">
                    <h1 class="text-2xl font-semibold text-content">{{ $title }}</h1>
                    <p class="text-sm leading-6 text-content-muted">{{ $description }}</p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    @if ($groupByMonth)
                        <div class="rounded-card border border-line bg-surface px-4 py-2 text-sm text-content">
                            <span class="font-semibold text-content-muted">{{ __('transactions.index.summary.period') }}</span>
                            <span class="ml-2 font-semibold">{{ $this->activePeriodLabel() }}</span>
                        </div>
                    @endif

                    <div class="rounded-card border border-line bg-surface px-4 py-2 text-sm text-content">
                        <span class="font-semibold text-content-muted">{{ __('transactions.index.summary.count') }}</span>
                        <span class="ml-2 font-semibold">{{ $this->visibleTransactionCount() }}</span>
                    </div>

                    <div class="rounded-card border border-line bg-surface px-4 py-2 text-sm text-content">
                        <span class="font-semibold text-content-muted">{{ __('transactions.index.summary.amount') }}</span>
                        <span class="ml-2 font-semibold {{ $palette['amount'] }}">{{ number_format($this->visibleTotalAmount()) }}</span>
                    </div>
                </div>
            </div>

            @if (! $groupByMonth)
                <div class="space-y-3 rounded-card border border-line bg-surface-muted p-4">
                    <div class="space-y-3 rounded-card border border-line bg-surface p-3 sm:p-4">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-xs font-medium tracking-wide text-content-muted">表示する経費の種類</p>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" wire:click="selectAllAccountNames"
                                    class="rounded-control border border-line bg-surface px-2.5 py-1 text-xs font-medium text-content transition hover:bg-surface">
                                    全選択
                                </button>
                                <button type="button" wire:click="clearAccountNames"
                                    class="rounded-control border border-line bg-surface px-2.5 py-1 text-xs font-medium text-content transition hover:bg-surface">
                                    全解除
                                </button>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            @foreach ($availableAccountNames as $accountName)
                                <label wire:key="expense-type-{{ md5($accountName) }}" class="cursor-pointer">
                                    <input type="checkbox" value="{{ $accountName }}" wire:model.live="accountNames"
                                        class="sr-only">
                                    <span @class([
                                        'inline-flex items-center gap-2 rounded-control border px-3 py-1.5 text-xs font-medium transition-colors',
                                        'border-transparent bg-action-primary text-action-primary-fg' => in_array($accountName, $accountNames, true),
                                        'border-line bg-surface text-content hover:bg-surface-muted' => ! in_array($accountName, $accountNames, true),
                                    ])>
                                        <span>{{ $accountName }}</span>
                                        <span @class([
                                            'rounded-full px-1.5 py-0.5 text-[10px] font-semibold',
                                            'bg-surface text-content' => in_array($accountName, $accountNames, true),
                                            'bg-surface-muted text-content-muted' => ! in_array($accountName, $accountNames, true),
                                        ])>{{ $availableAccountCounts[$accountName] ?? 0 }}件</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>
            @else
                <div class="space-y-0">
                    <div class="overflow-x-auto">
                        <x-ui.tab-list variant="connected">
                            @foreach ($this->monthTabs() as $month)
                                <x-ui.tab
                                    variant="connected"
                                    wire:click="selectPeriod('{{ $month['year_month'] }}')"
                                    :active="$selectedPeriod === $month['year_month']"
                                    :count="$month['transaction_count']"
                                    :active-class="$palette['tabActive']"
                                    :inactive-class="$palette['tabInactive']"
                                    active-count-class="{{ $palette['tabCountActive'] }}"
                                    inactive-count-class="{{ $palette['tabCountInactive'] }}"
                                >
                                    {{ $month['label'] }}
                                </x-ui.tab>
                            @endforeach

                            <x-ui.tab
                                variant="connected"
                                wire:click="selectPeriod('{{ \App\Livewire\Pages\AccountTypeTransactionIndex::YEARLY_PERIOD }}')"
                                :active="$selectedPeriod === \App\Livewire\Pages\AccountTypeTransactionIndex::YEARLY_PERIOD"
                                :count="count($transactions)"
                                :active-class="$palette['tabActive']"
                                :inactive-class="$palette['tabInactive']"
                                active-count-class="{{ $palette['tabCountActive'] }}"
                                inactive-count-class="{{ $palette['tabCountInactive'] }}"
                            >
                                {{ __('transactions.index.tabs.yearly') }}
                            </x-ui.tab>
                        </x-ui.tab-list>
                    </div>

                    <div class="rounded-b-card border-x border-b border-line bg-surface p-4">
                        <x-transactions.table
                            :transactions="$this->visibleTransactions()"
                            :account-type="$accountType"
                            :show-tax-type-column="$showTaxTypeColumn"
                            table-wrap-class="border-line rounded-card"
                            :table-head-class="$palette['tableHead']"
                            :empty-state-colspan="$this->emptyStateColspan()"
                            :empty-message="$this->periodEmptyMessage()"
                            key-prefix="index-transaction"
                            delete-action="deleteTransaction"
                            :edit-action="$this->editAction()"
                            :edit-livewire-component="$this->editLivewireComponent()"
                            :editing-transaction-id="$editingTransactionId"
                            :expense-debit-header="$this->debitHeader()"
                            :expense-credit-header="$this->creditHeader()"
                        />
                    </div>
                </div>
            @endif

            @if (! $groupByMonth)
                <x-transactions.table
                    :transactions="$this->visibleTransactions()"
                    :account-type="$accountType"
                    :show-tax-type-column="$showTaxTypeColumn"
                    :table-wrap-class="$palette['tableWrap']"
                    :table-head-class="$palette['tableHead']"
                    :empty-state-colspan="$this->emptyStateColspan()"
                    empty-message="表示する経費の種類を選ぶと、対象取引がここに表示されます。"
                    key-prefix="index-transaction"
                    delete-action="deleteTransaction"
                    :edit-action="$this->editAction()"
                    :edit-livewire-component="$this->editLivewireComponent()"
                    :editing-transaction-id="$editingTransactionId"
                    class="rounded-card"
                />
            @endif
        </section>
    </div>
</div>
