<div>
    <x-ui.card>
        <x-ui.card-header>
            {{ __('transactions.journal_form.edit_title') }}
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

            {{-- 日付 / 摘要 / 取引先 --}}
            <div class="flex flex-wrap items-end gap-3">
                <div class="w-20">
                    <label class="block text-sm font-semibold text-content mb-1">
                        {{ __('transactions.journal_form.fields.date') }}
                    </label>
                    <input type="text" wire:model.defer="date_input" maxlength="4" size="4" inputmode="numeric"
                        pattern="\d{3,4}" autocomplete="off"
                        placeholder="{{ __('transactions.journal_form.placeholders.date') }}"
                        class="block w-full px-2 py-2 text-base text-center tabular-nums bg-surface text-content border border-line rounded-control focus:outline-none focus:ring-2 focus:ring-focus">
                    @error('date_input')
                        <div class="text-xs text-status-danger-fg mt-1">{{ $message }}</div>
                    @enderror
                </div>

                <div class="flex-1 min-w-[12rem]">
                    <label class="block text-sm font-semibold text-content mb-1">
                        {{ __('transactions.journal_form.fields.description') }}
                    </label>
                    <input type="text" wire:model.defer="description"
                        placeholder="{{ __('transactions.journal_form.placeholders.description') }}"
                        class="block w-full px-3 py-2 text-sm bg-surface text-content border border-line rounded-control focus:outline-none focus:ring-2 focus:ring-focus">
                    @error('description')
                        <div class="text-xs text-status-danger-fg mt-1">{{ $message }}</div>
                    @enderror
                </div>

                <div class="flex-1 min-w-[12rem]">
                    <label class="block text-sm font-semibold text-content mb-1">
                        {{ __('transactions.journal_form.fields.counterparty_name') }}
                    </label>
                    <input type="text" wire:model.defer="counterparty_name"
                        placeholder="{{ __('transactions.journal_form.placeholders.counterparty_name') }}"
                        class="block w-full px-3 py-2 text-sm bg-surface text-content border border-line rounded-control focus:outline-none focus:ring-2 focus:ring-focus">
                    @error('counterparty_name')
                        <div class="text-xs text-status-danger-fg mt-1">{{ $message }}</div>
                    @enderror
                </div>
            </div>

            {{-- 借方 / 貸方 --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @foreach ([\App\Models\JournalEntry::TYPE_DEBIT, \App\Models\JournalEntry::TYPE_CREDIT] as $side)
                    <div class="space-y-2">
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-content">
                                {{ __('transactions.journal_form.sides.' . $side) }}
                            </h3>
                        </div>

                        @foreach ($entries as $index => $entry)
                            @if ($entry['type'] === $side)
                                <div wire:key="edit-entry-{{ $side }}-{{ $index }}"
                                    class="p-2 bg-surface border border-line rounded-card space-y-1">
                                    <div class="flex flex-wrap items-end gap-2">
                                        {{-- 補助科目 --}}
                                        <div class="w-40 min-w-0">
                                            <label class="block text-xs font-semibold text-content-muted mb-1">
                                                {{ __('transactions.journal_form.fields.sub_account') }}
                                            </label>
                                            <select wire:model.defer="entries.{{ $index }}.sub_account_id"
                                                class="block w-full px-1.5 py-1.5 text-xs bg-surface text-content border border-line rounded-control focus:outline-none focus:ring-2 focus:ring-focus">
                                                <option value="">
                                                    {{ __('transactions.journal_form.placeholders.sub_account') }}
                                                </option>
                                                @foreach (\App\Models\Account::TYPES as $type)
                                                    @php($accountsOfType = $subAccountsByType[$type] ?? collect())
                                                    @if ($accountsOfType->isNotEmpty())
                                                        <optgroup
                                                            label="{{ __('transactions.journal_form.account_type_labels.' . $type) }}">
                                                            @foreach ($accountsOfType as $account)
                                                                @foreach ($account->subAccounts as $subAccount)
                                                                    <option value="{{ $subAccount->id }}">
                                                                        {{ $account->name }} / {{ $subAccount->displayName() }}
                                                                    </option>
                                                                @endforeach
                                                            @endforeach
                                                        </optgroup>
                                                    @endif
                                                @endforeach
                                            </select>
                                        </div>

                                        {{-- 金額 --}}
                                        <div class="w-30">
                                            <label class="block text-xs font-semibold text-content-muted mb-1">
                                                {{ __('transactions.journal_form.fields.gross_amount') }}
                                            </label>
                                            <input type="text"
                                                wire:model.live.debounce.150ms="entries.{{ $index }}.gross_amount"
                                                inputmode="numeric" pattern="\d*" autocomplete="off" maxlength="9"
                                                class="block w-full px-2 py-1.5 text-sm text-right tabular-nums bg-surface text-content border border-line rounded-control focus:outline-none focus:ring-2 focus:ring-focus">
                                        </div>

                                        {{-- 消費税区分 --}}
                                        <div class="w-28">
                                            <label class="block text-xs font-semibold text-content-muted mb-1">
                                                {{ __('transactions.journal_form.fields.tax_type') }}
                                            </label>
                                            <select wire:model.defer="entries.{{ $index }}.tax_type"
                                                class="block w-full px-1.5 py-1.5 text-xs bg-surface text-content border border-line rounded-control focus:outline-none focus:ring-2 focus:ring-focus">
                                                @foreach (\App\Models\JournalEntry::USER_SELECTABLE_TAX_TYPES as $taxType)
                                                    <option value="{{ $taxType }}">
                                                        {{ __('transactions.journal_form.tax_type_labels.' . $taxType) }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>

                                        {{-- 削除ボタン --}}
                                        <button type="button" wire:click="removeEntry({{ $index }})"
                                            class="shrink-0 px-2 py-1.5 text-xs text-status-danger-fg hover:underline">
                                            {{ __('transactions.journal_form.actions.remove_entry') }}
                                        </button>
                                    </div>

                                    @error('entries.' . $index . '.sub_account_id')
                                        <div class="text-xs text-status-danger-fg">{{ $message }}</div>
                                    @enderror
                                    @error('entries.' . $index . '.gross_amount')
                                        <div class="text-xs text-status-danger-fg">{{ $message }}</div>
                                    @enderror
                                    @error('entries.' . $index . '.tax_type')
                                        <div class="text-xs text-status-danger-fg">{{ $message }}</div>
                                    @enderror
                                </div>
                            @endif
                        @endforeach

                        <button type="button"
                            wire:click="{{ $side === \App\Models\JournalEntry::TYPE_DEBIT ? 'addDebit' : 'addCredit' }}"
                            class="w-full px-3 py-1.5 text-sm font-medium rounded-control border border-line bg-surface text-content hover:bg-surface-muted transition">
                            +
                            {{ __('transactions.journal_form.actions.' . ($side === \App\Models\JournalEntry::TYPE_DEBIT ? 'add_debit' : 'add_credit')) }}
                        </button>
                    </div>
                @endforeach
            </div>

            {{-- 合計 --}}
            <div class="flex flex-wrap gap-4 justify-end text-sm">
                <div class="text-content-muted">
                    {{ __('transactions.journal_form.summary.debit_total') }}:
                    <span class="font-mono tabular-nums">{{ number_format($this->debitTotal()) }}円</span>
                </div>
                <div class="text-content-muted">
                    {{ __('transactions.journal_form.summary.credit_total') }}:
                    <span class="font-mono tabular-nums">{{ number_format($this->creditTotal()) }}円</span>
                </div>
                @if (!$this->isBalanced())
                    <div class="text-status-danger-fg font-semibold">
                        {{ __('transactions.journal_form.summary.unbalanced') }}
                    </div>
                @endif
            </div>

            @error('entries')
                <div class="text-xs text-status-danger-fg">{{ $message }}</div>
            @enderror

            {{-- 修正理由 (必須) --}}
            <div>
                <label class="block text-sm font-semibold text-content mb-1">
                    {{ __('transactions.journal_form.fields.revision_reason') }}
                    <span class="text-status-danger-fg">*</span>
                </label>
                <input type="text" wire:model.defer="revision_reason" maxlength="255"
                    placeholder="{{ __('transactions.journal_form.placeholders.revision_reason') }}"
                    class="block w-full px-3 py-2 text-sm bg-surface text-content border border-line rounded-control focus:outline-none focus:ring-2 focus:ring-focus">
                @error('revision_reason')
                    <div class="text-xs text-status-danger-fg mt-1">{{ $message }}</div>
                @enderror
            </div>

            <div class="flex justify-end pt-1 gap-2">
                <x-ui.button variant="secondary" type="button" wire:click="cancel">
                    {{ __('transactions.journal_form.actions.cancel') }}
                </x-ui.button>
                <x-ui.button variant="confirm" type="button" wire:click="submit" :disabled="!$this->isBalanced()"
                    class="block shrink-0 min-w-[11rem]">
                    {{ __('transactions.journal_form.actions.submit_revise') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.card>
</div>
