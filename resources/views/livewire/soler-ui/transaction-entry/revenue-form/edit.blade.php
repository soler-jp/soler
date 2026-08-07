<div>
    <x-ui.card variant="revenue">
        <x-ui.card-header variant="revenue">
            {{ __('transactions.revenue_form.edit.title') }}
        </x-ui.card-header>

        <div class="p-4 space-y-4">

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
                    {{ __('transactions.revenue_form.fields.date') }}
                </label>
                <input type="text" wire:model.defer="date_input" maxlength="4" size="4"
                    inputmode="numeric" pattern="\d{3,4}" autocomplete="off"
                    placeholder="{{ __('transactions.revenue_form.placeholders.date') }}"
                    class="block w-full px-2 py-2 text-base text-center tabular-nums bg-surface text-content border border-line rounded-control focus:outline-none focus:ring-2 focus:ring-focus">
            </div>
            <div class="flex-1 min-w-[8rem]">
                <label class="block text-sm font-semibold text-content mb-1">
                    {{ __('transactions.revenue_form.fields.amount') }}
                </label>
                <input type="text" wire:model.live.debounce.150ms="amount" inputmode="numeric" pattern="\d*"
                    autocomplete="off"
                    class="block w-full px-3 py-2 text-base text-right tabular-nums bg-surface text-content border border-line rounded-control focus:outline-none focus:ring-2 focus:ring-focus">
            </div>
            @if ($isTaxable)
                <div>
                    <label class="block text-sm font-semibold text-content mb-1">
                        {{ __('transactions.revenue_form.fields.tax_option') }}
                    </label>
                    <div
                        class="inline-flex rounded-control border border-line overflow-hidden bg-surface shadow-sm">
                        @foreach (\App\Livewire\SolerUi\TransactionEntry\RevenueForm\Standard::TAX_OPTIONS as $option)
                            <button type="button" wire:click="$set('tax_option', '{{ $option }}')"
                                @class([
                                    'px-3 py-2 text-sm font-medium transition focus:outline-none focus:ring-2 focus:ring-focus focus:z-10',
                                    'bg-action-primary text-action-primary-fg font-semibold' => $tax_option === $option,
                                    'text-content hover:bg-surface-muted' => $tax_option !== $option,
                                    'border-l border-line' => ! $loop->first,
                                ])>
                                {{ __('transactions.revenue_form.tax_options.' . $option) }}
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            <div>
                <label class="block text-sm font-semibold text-content mb-1">
                    {{ __('transactions.revenue_form.sections.receipt_method') }}
                </label>
                <div class="flex flex-wrap items-center gap-2">
                    @foreach ($receiptStandardSubAccounts as $subAccount)
                        <button type="button"
                            wire:click="$set('receipt_sub_account_id', {{ $subAccount->id }})"
                            @class([
                                'px-3 py-1.5 text-sm font-medium rounded-control border transition',
                                'bg-action-primary text-action-primary-fg border-transparent font-semibold' =>
                                    $receipt_sub_account_id === $subAccount->id,
                                'bg-surface text-content border-line hover:bg-surface-muted' =>
                                    $receipt_sub_account_id !== $subAccount->id,
                            ])>
                            {{ $subAccount->displayName() }}
                        </button>
                    @endforeach

                    @if (! empty($receiptOwnerDrawSubAccounts))
                        <span class="text-content-muted text-base select-none px-1" aria-hidden="true">|</span>

                        @foreach ($receiptOwnerDrawSubAccounts as $subAccount)
                            <button type="button"
                                wire:click="$set('receipt_sub_account_id', {{ $subAccount->id }})"
                                @class([
                                    'px-3 py-1.5 text-sm font-medium rounded-control border transition',
                                    'bg-action-primary text-action-primary-fg border-transparent font-semibold' =>
                                        $receipt_sub_account_id === $subAccount->id,
                                    'bg-surface text-content border-line hover:bg-surface-muted' =>
                                        $receipt_sub_account_id !== $subAccount->id,
                                ])>
                                {{ $subAccount->displayName() }}
                            </button>
                        @endforeach
                    @endif

                    @if (! empty($receiptSpecialSubAccounts))
                        <span class="text-content-muted text-base select-none px-1" aria-hidden="true">|</span>

                        @foreach ($receiptSpecialSubAccounts as $subAccount)
                            <button type="button"
                                wire:click="$set('receipt_sub_account_id', {{ $subAccount->id }})"
                                @class([
                                    'px-3 py-1.5 text-sm font-medium rounded-control border transition',
                                    'bg-action-primary text-action-primary-fg border-transparent font-semibold' =>
                                        $receipt_sub_account_id === $subAccount->id,
                                    'bg-surface text-content border-line hover:bg-surface-muted' =>
                                        $receipt_sub_account_id !== $subAccount->id,
                                ])>
                                <span class="italic">{{ $subAccount->displayName() }}</span>
                            </button>
                        @endforeach
                    @endif
                </div>
            </div>
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
        @error('receipt_sub_account_id')
            <div class="text-xs text-status-danger-fg -mt-2">{{ $message }}</div>
        @enderror

        {{-- 何の売上か / 取引先 --}}
        <div class="flex flex-wrap gap-3">
            <div class="flex-1 min-w-[12rem]">
                <label class="block text-sm font-semibold text-content mb-1">
                    {{ __('transactions.revenue_form.fields.note') }}
                </label>
                <input type="text" wire:model.defer="note"
                    placeholder="{{ __('transactions.revenue_form.placeholders.note') }}"
                    class="block w-full px-3 py-2 text-sm bg-surface text-content border border-line rounded-control focus:outline-none focus:ring-2 focus:ring-focus">
                @error('note')
                    <div class="text-xs text-status-danger-fg mt-1">{{ $message }}</div>
                @enderror
            </div>

            <div class="flex-1 min-w-[12rem]">
                <label class="block text-sm font-semibold text-content mb-1">
                    {{ __('transactions.revenue_form.fields.counterparty_name') }}
                </label>
                <input type="text" wire:model.defer="counterparty_name"
                    placeholder="{{ __('transactions.revenue_form.placeholders.counterparty_name') }}"
                    class="block w-full px-3 py-2 text-sm bg-surface text-content border border-line rounded-control focus:outline-none focus:ring-2 focus:ring-focus">
                @error('counterparty_name')
                    <div class="text-xs text-status-danger-fg mt-1">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <div class="flex justify-end gap-2 pt-1">
            <x-ui.button variant="secondary" type="button" wire:click="cancel">
                {{ __('transactions.revenue_form.actions.cancel') }}
            </x-ui.button>
            <x-ui.button variant="confirm" type="button" wire:click="submit"
                :disabled="$this->amountInputInvalid()" class="block shrink-0 min-w-[11rem]">
                <span class="{{ $this->amountDisplay() !== '' ? 'font-mono text-base tabular-nums' : '' }}">{{ $this->amountSubmitLabel() }}</span>
            </x-ui.button>
        </div>
        </div>
    </x-ui.card>
</div>
