<div>
    <x-ui.card>
        <x-ui.card-header>
            {{ __('transactions.transfer_form.edit.title') }}
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

        <div class="flex flex-wrap items-end gap-3">
            <div class="w-20">
                <label class="block text-sm font-semibold text-content mb-1">
                    {{ __('transactions.transfer_form.fields.date') }}
                </label>
                <input type="text" wire:model.defer="date_input" maxlength="4" size="4"
                    inputmode="numeric" pattern="\d{3,4}" autocomplete="off"
                    placeholder="{{ __('transactions.transfer_form.placeholders.date') }}"
                    class="block w-full px-2 py-2 text-base text-center tabular-nums bg-surface text-content border border-line rounded-control focus:outline-none focus:ring-2 focus:ring-focus">
            </div>
            <div class="flex-1 min-w-[8rem]">
                <label class="block text-sm font-semibold text-content mb-1">
                    {{ __('transactions.transfer_form.fields.amount') }}
                </label>
                <input type="text" wire:model.live.debounce.150ms="amount" inputmode="numeric" pattern="\d*"
                    autocomplete="off"
                    class="block w-full px-3 py-2 text-base text-right tabular-nums bg-surface text-content border border-line rounded-control focus:outline-none focus:ring-2 focus:ring-focus">
            </div>
        </div>
        @error('date_input')
            <div class="text-xs text-status-danger-fg -mt-2">{{ $message }}</div>
        @enderror
        @error('amount')
            <div class="text-xs text-status-danger-fg -mt-2">{{ $message }}</div>
        @enderror

        <div class="space-y-2">
            <label class="block text-sm font-semibold text-content">
                {{ __('transactions.transfer_form.fields.from_account') }}
            </label>
            <div class="flex flex-wrap gap-2">
                @foreach ($this->accountOptions as $option)
                    <button type="button"
                        wire:click="$set('from_sub_account_id', {{ $option['sub_account_id'] }})"
                        @class([
                            'px-3 py-1.5 text-sm font-medium rounded-control border transition',
                            'bg-action-primary text-action-primary-fg border-transparent font-semibold' =>
                                $from_sub_account_id === $option['sub_account_id'],
                            'bg-surface text-content border-line hover:bg-surface-muted' =>
                                $from_sub_account_id !== $option['sub_account_id'],
                        ])>
                        {{ $option['label'] }}
                    </button>
                @endforeach
            </div>
            @error('from_sub_account_id')
                <div class="text-xs text-status-danger-fg">{{ $message }}</div>
            @enderror
        </div>

        <div class="space-y-2">
            <label class="block text-sm font-semibold text-content">
                {{ __('transactions.transfer_form.fields.to_account') }}
            </label>
            <div class="flex flex-wrap gap-2">
                @foreach ($this->accountOptions as $option)
                    <button type="button"
                        wire:click="$set('to_sub_account_id', {{ $option['sub_account_id'] }})"
                        @class([
                            'px-3 py-1.5 text-sm font-medium rounded-control border transition',
                            'bg-action-primary text-action-primary-fg border-transparent font-semibold' =>
                                $to_sub_account_id === $option['sub_account_id'],
                            'bg-surface text-content border-line hover:bg-surface-muted' =>
                                $to_sub_account_id !== $option['sub_account_id'],
                        ])>
                        {{ $option['label'] }}
                    </button>
                @endforeach
            </div>
            @error('to_sub_account_id')
                <div class="text-xs text-status-danger-fg">{{ $message }}</div>
            @enderror
        </div>

        <div>
            <label class="block text-sm font-semibold text-content mb-1">
                {{ __('transactions.transfer_form.fields.note') }}
            </label>
            <input type="text" wire:model.defer="note"
                placeholder="{{ __('transactions.transfer_form.placeholders.note') }}"
                class="block w-full px-3 py-2 text-sm bg-surface text-content border border-line rounded-control focus:outline-none focus:ring-2 focus:ring-focus">
            @error('note')
                <div class="text-xs text-status-danger-fg mt-1">{{ $message }}</div>
            @enderror
        </div>

        <div class="flex justify-end gap-2 pt-1">
            <x-ui.button variant="secondary" type="button" wire:click="cancel">
                {{ __('transactions.transfer_form.actions.cancel') }}
            </x-ui.button>
            <x-ui.button variant="confirm" type="button" wire:click="submit"
                :disabled="$this->amountInputInvalid()" class="block shrink-0 min-w-[11rem]">
                <span class="{{ $this->amountDisplay() !== '' ? 'font-mono text-base tabular-nums' : '' }}">{{ $this->amountSubmitLabel() }}</span>
            </x-ui.button>
        </div>
        </div>
    </x-ui.card>
</div>
