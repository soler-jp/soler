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
                <input type="text" wire:model.defer="amount" inputmode="numeric" pattern="\d*"
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
        </div>
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
        <div class="space-y-2">
            <label class="block text-sm font-semibold text-content">
                {{ __('transactions.expense_form.sections.expense_type') }}
            </label>

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

        {{-- 支払方法 --}}
        <div class="space-y-2">
            <label class="block text-sm font-semibold text-content">
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
            @error('credit_sub_account_id')
                <div class="text-xs text-status-danger-fg">{{ $message }}</div>
            @enderror
        </div>

        {{-- 何に使ったか --}}
        <div>
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

        {{-- 支払い先 --}}
        <div>
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

        <div class="pt-1">
            <x-ui.button variant="confirm" type="button" wire:click="submit" class="w-full">
                {{ __('transactions.expense_form.actions.submit') }}
            </x-ui.button>
        </div>
        </div>
    </x-ui.card>
</div>
