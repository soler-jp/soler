<div>
    <x-ui.card variant="expense" collapsible>
        <x-ui.card-header toggle variant="expense">
            {{ __('transactions.expense_form.title') }}
        </x-ui.card-header>

        <div x-show="open" x-cloak class="p-4 space-y-4">

        @if (session()->has('message'))
            <div
                class="p-2 rounded-control bg-status-success text-status-success-fg border border-status-success-border text-sm">
                {{ session('message') }}
            </div>
        @endif

        @if (session()->has('error'))
            <div
                class="p-2 rounded-control bg-status-danger text-status-danger-fg border border-status-danger-border text-sm">
                {{ session('error') }}
            </div>
        @endif

        {{-- 日付 / 金額 / 消費税 --}}
        <div class="flex flex-wrap items-end gap-3">
            <div class="w-20">
                <label class="block text-sm font-semibold text-content mb-1">
                    {{ __('transactions.expense_form.fields.date') }}
                </label>
                <input type="text" wire:model.defer="date_input" maxlength="4" size="4"
                    inputmode="numeric" pattern="\d{3,4}" autocomplete="off"
                    placeholder="{{ __('transactions.expense_form.placeholders.date') }}"
                    class="block w-full px-2 py-2 text-base text-center tabular-nums bg-surface text-content border border-line rounded-control focus:outline-none focus:ring-2 focus:ring-focus">
            </div>
            <div class="flex-1 min-w-[8rem]">
                <label class="block text-sm font-semibold text-content mb-1">
                    {{ __('transactions.expense_form.fields.amount') }}
                </label>
                <input type="text" wire:model.live.debounce.150ms="amount" inputmode="numeric" pattern="\d*"
                    autocomplete="off"
                    class="block w-full px-3 py-2 text-base text-right tabular-nums bg-surface text-content border border-line rounded-control focus:outline-none focus:ring-2 focus:ring-focus">
            </div>
            @if ($isTaxable)
                <div>
                    <label class="block text-sm font-semibold text-content mb-1">
                        {{ __('transactions.expense_form.fields.tax_option') }}
                    </label>
                    <div
                        class="inline-flex rounded-control border border-line overflow-hidden bg-surface shadow-sm">
                        @foreach (\App\Livewire\SolerUi\TransactionEntry\ExpenseForm\Standard::TAX_OPTIONS as $option)
                            <button type="button" wire:click="$set('tax_option', '{{ $option }}')"
                                @class([
                                    'px-3 py-2 text-sm font-medium transition focus:outline-none focus:ring-2 focus:ring-focus focus:z-10',
                                    'bg-action-primary text-action-primary-fg font-semibold' => $tax_option === $option,
                                    'text-content hover:bg-surface-muted' => $tax_option !== $option,
                                    'border-l border-line' => ! $loop->first,
                                ])>
                                {{ __('transactions.expense_form.tax_options.' . $option) }}
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            <div>
                <label class="block text-sm font-semibold text-content mb-1">
                    {{ __('transactions.expense_form.sections.payment_method') }}
                </label>
                <div class="flex flex-wrap gap-2">
                    @foreach ($creditAccounts as $account)
                        @foreach ($account->subAccounts as $subAccount)
                            <button type="button"
                                wire:click="$set('credit_sub_account_id', {{ $subAccount->id }})"
                                @class([
                                    'px-3 py-1.5 text-sm font-medium rounded-control border transition',
                                    'bg-action-primary text-action-primary-fg border-transparent font-semibold' =>
                                        $credit_sub_account_id === $subAccount->id,
                                    'bg-surface text-content border-line hover:bg-surface-muted' =>
                                        $credit_sub_account_id !== $subAccount->id,
                                ])>
                                {{ $subAccount->displayName() }}
                            </button>
                        @endforeach
                    @endforeach
                </div>
            </div>
        </div>
        @error('credit_sub_account_id')
            <div class="text-xs text-status-danger-fg -mt-2">{{ $message }}</div>
        @enderror
        @error('date_input')
            <div class="text-xs text-status-danger-fg -mt-2">{{ $message }}</div>
        @enderror
        @error('amount')
            <div class="text-xs text-status-danger-fg -mt-2">{{ $message }}</div>
        @enderror
        @if ($isTaxable)
            @error('tax_option')
                <div class="text-xs text-status-danger-fg -mt-2">{{ $message }}</div>
            @enderror
        @endif

        {{-- 経費の種類 --}}
        <div class="space-y-2" x-data="{ pickerOpen: false }">
            <div class="flex items-center gap-1.5">
                <label class="block text-sm font-semibold text-content">
                    {{ __('transactions.expense_form.sections.expense_type') }}
                </label>
                <button type="button" @click="pickerOpen = true"
                    aria-label="{{ __('transactions.expense_form.picker.help_aria') }}"
                    class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-surface border border-line text-content shadow-sm hover:bg-surface-muted focus:outline-none focus:ring-2 focus:ring-focus">
                    <span class="text-xs font-bold leading-none" aria-hidden="true">?</span>
                </button>
            </div>

            {{-- 説明つき勘定科目ピッカー --}}
            <div x-show="pickerOpen" x-cloak
                class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40"
                @keydown.escape.window="pickerOpen = false"
                @click.self="pickerOpen = false">
                <div class="bg-surface text-content rounded-card shadow-card border border-line w-full max-w-3xl max-h-[85vh] flex flex-col">
                    <div class="flex items-start justify-between p-4 border-b border-line">
                        <div>
                            <h2 class="text-base font-semibold">
                                {{ __('transactions.expense_form.picker.title') }}
                            </h2>
                            <p class="text-xs text-content-muted mt-1">
                                {{ __('transactions.expense_form.picker.lead') }}
                            </p>
                        </div>
                        <button type="button" @click="pickerOpen = false"
                            aria-label="{{ __('transactions.expense_form.picker.close') }}"
                            class="text-content-muted hover:text-content focus:outline-none focus:ring-2 focus:ring-focus rounded-control p-1">
                            <x-ui.icon name="x-mark" class="w-5 h-5" />
                        </button>
                    </div>

                    <div class="overflow-y-auto flex-1">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 bg-surface border-b border-line">
                                <tr>
                                    <th class="text-left font-semibold px-3 py-2 w-32 whitespace-nowrap">
                                        {{ __('transactions.expense_form.picker.columns.name') }}
                                    </th>
                                    <th class="text-left font-semibold px-3 py-2">
                                        {{ __('transactions.expense_form.picker.columns.example') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($expenseAccountPickerRows as $row)
                                    <tr
                                        wire:key="picker-row-{{ $row['sub_account_id'] }}"
                                        wire:click="$set('debit_sub_account_id', {{ $row['sub_account_id'] }})"
                                        @click="pickerOpen = false"
                                        @class([
                                            'cursor-pointer border-b border-line transition',
                                            'bg-action-primary/10' => $debit_sub_account_id === $row['sub_account_id'],
                                            'hover:bg-surface-muted' => $debit_sub_account_id !== $row['sub_account_id'],
                                        ])>
                                        <td class="align-middle px-3 py-2 font-semibold whitespace-nowrap">
                                            {{ $row['sub_account_display_name'] }}
                                        </td>
                                        <td class="align-top px-3 py-2">
                                            @if (! empty($row['example']))
                                                <div class="whitespace-pre-line text-content">{{ $row['example'] }}</div>
                                            @else
                                                <div class="text-content-muted italic">{{ __('transactions.expense_form.picker.no_description') }}</div>
                                            @endif

                                            @if (! empty($row['caution']))
                                                <div class="mt-1 text-xs text-status-warning-fg"><span class="font-semibold">⚠ {{ __('transactions.expense_form.picker.caution_label') }}:</span> {{ $row['caution'] }}</div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="px-4 py-2 border-t border-line text-xs text-content-muted">
                        {{ __('transactions.expense_form.picker.source') }}
                        <a href="https://www.nta.go.jp/taxes/shiraberu/shinkoku/kojin_jigyo/kichou03.pdf"
                            target="_blank" rel="noopener noreferrer"
                            class="text-link hover:underline break-all">
                            https://www.nta.go.jp/taxes/shiraberu/shinkoku/kojin_jigyo/kichou03.pdf
                        </a>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                @foreach ($expenseSubAccountsStandard as $subAccount)
                    <button type="button"
                        wire:click="$set('debit_sub_account_id', {{ $subAccount->id }})"
                        @class([
                            'px-3 py-1.5 text-sm font-medium rounded-control border transition',
                            'bg-action-primary text-action-primary-fg border-transparent font-semibold' =>
                                $debit_sub_account_id === $subAccount->id,
                            'bg-surface text-content border-line hover:bg-surface-muted' =>
                                $debit_sub_account_id !== $subAccount->id,
                        ])>
                        {{ $subAccount->displayName() }}
                    </button>
                @endforeach

                @if ($showExpanded)
                    @foreach ($expenseSubAccountsExpanded as $subAccount)
                        <button type="button"
                            wire:click="$set('debit_sub_account_id', {{ $subAccount->id }})"
                            @class([
                                'px-3 py-1.5 text-sm font-medium rounded-control border transition',
                                'bg-action-primary text-action-primary-fg border-transparent font-semibold' =>
                                    $debit_sub_account_id === $subAccount->id,
                                'bg-surface text-content border-line hover:bg-surface-muted' =>
                                    $debit_sub_account_id !== $subAccount->id,
                            ])>
                            {{ $subAccount->displayName() }}
                        </button>
                    @endforeach
                @endif

                @if ($expenseSubAccountsUnclassified->isNotEmpty())
                    <span class="text-content-muted text-base select-none px-1" aria-hidden="true">|</span>

                    @foreach ($expenseSubAccountsUnclassified as $subAccount)
                        <button type="button"
                            wire:click="$set('debit_sub_account_id', {{ $subAccount->id }})"
                            title="種類が決まっていない支出。あとから正しい科目へ振り替えるのがおすすめです。"
                            @class([
                                'px-3 py-1.5 text-sm font-medium rounded-control border transition',
                                'bg-action-primary text-action-primary-fg border-transparent font-semibold' =>
                                    $debit_sub_account_id === $subAccount->id,
                                'bg-surface text-content border-line hover:bg-surface-muted' =>
                                    $debit_sub_account_id !== $subAccount->id,
                            ])>
                            <span class="italic">{{ $subAccount->displayName() }}</span>
                        </button>
                    @endforeach
                @endif

                @if ($expenseSubAccountsExpanded->isNotEmpty())
                    <button type="button" wire:click="toggleExpanded"
                        class="ml-1 text-sm text-link hover:underline">
                        {{ $showExpanded
                            ? __('transactions.expense_form.actions.show_less')
                            : __('transactions.expense_form.actions.show_more') }}
                    </button>
                @endif
            </div>

            @error('debit_sub_account_id')
                <div class="text-xs text-status-danger-fg">{{ $message }}</div>
            @enderror
        </div>

        {{-- 何に使ったか / 支払い先 --}}
        <div class="flex flex-wrap gap-3">
            <div class="flex-1 min-w-[12rem]">
                <label class="block text-sm font-semibold text-content mb-1">
                    {{ __('transactions.expense_form.fields.note') }}
                </label>
                <input type="text" wire:model.defer="note"
                    placeholder="{{ __('transactions.expense_form.placeholders.note') }}"
                    class="block w-full px-3 py-2 text-sm bg-surface text-content border border-line rounded-control focus:outline-none focus:ring-2 focus:ring-focus">
                @error('note')
                    <div class="text-xs text-status-danger-fg mt-1">{{ $message }}</div>
                @enderror
            </div>

            <div class="flex-1 min-w-[12rem]">
                <label class="block text-sm font-semibold text-content mb-1">
                    {{ __('transactions.expense_form.fields.counterparty_name') }}
                </label>
                <input type="text" wire:model.defer="counterparty_name"
                    placeholder="{{ __('transactions.expense_form.placeholders.counterparty_name') }}"
                    class="block w-full px-3 py-2 text-sm bg-surface text-content border border-line rounded-control focus:outline-none focus:ring-2 focus:ring-focus">
                @error('counterparty_name')
                    <div class="text-xs text-status-danger-fg mt-1">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <div class="flex justify-end pt-1">
            <x-ui.button variant="confirm" type="button" wire:click="submit"
                :disabled="$this->amountInputInvalid()" class="block shrink-0 min-w-[11rem]">
                <span class="{{ $this->amountDisplay() !== '' ? 'font-mono text-base tabular-nums' : '' }}">{{ $this->amountSubmitLabel() }}</span>
            </x-ui.button>
        </div>
        </div>
    </x-ui.card>
</div>
