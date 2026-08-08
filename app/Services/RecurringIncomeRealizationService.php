<?php

namespace App\Services;

use App\Concerns\AuthorizesBusinessUnitAccess;
use App\Models\JournalEntry;
use App\Models\RecurringTransactionPlan;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RecurringIncomeRealizationService
{
    use AuthorizesBusinessUnitAccess;

    public function __construct(
        private readonly PlannedTransactionConfirmer $plannedTransactionConfirmer,
        private readonly TransactionRegistrar $transactionRegistrar,
    ) {}

    public function realize(
        Transaction $plannedTransaction,
        array $input,
        User $actor,
    ): Collection {
        $this->authorizeBusinessUnitAccess($plannedTransaction, $actor, 'この予定取引を実現する権限がありません。');

        $plannedTransaction->loadMissing('recurringTransactionPlan.businessUnit', 'journalEntries');

        if (! $plannedTransaction->is_planned) {
            throw ValidationException::withMessages([
                'transaction' => ['予定取引のみ実現できます。'],
            ]);
        }

        $plan = $plannedTransaction->recurringTransactionPlan;

        if (! $plan instanceof RecurringTransactionPlan || $plan->type !== RecurringTransactionPlan::TYPE_INCOME) {
            throw ValidationException::withMessages([
                'transaction' => ['収入の予定取引のみ実現できます。'],
            ]);
        }

        $isTaxableFiscalYear = (bool) $plan->businessUnit->currentFiscalYear?->is_taxable;

        $validator = Validator::make($input, [
            'input_mode' => ['nullable', 'in:gross,net_tax'],
            'amount' => ['nullable', 'integer', 'min:1'],
            'net_amount' => ['nullable', 'integer', 'min:1'],
            'tax_amount' => ['nullable', 'integer', 'min:0'],
            'withholding_tax_amount' => ['nullable', 'integer', 'min:0'],
            'receipt_date' => ['required', 'date'],
            'receipt_sub_account_id' => ['required', $plan->businessUnit->subAccountExistsRule()],
            'tax_option' => ['nullable', 'in:8,10'],
        ], [], [
            'input_mode' => __('recurring_income_realizations.fields.input_mode'),
            'amount' => __('recurring_income_realizations.fields.amount'),
            'net_amount' => __('recurring_income_realizations.fields.net_amount'),
            'tax_amount' => __('recurring_income_realizations.fields.tax_amount'),
            'tax_option' => __('recurring_income_realizations.fields.tax_rate'),
            'withholding_tax_amount' => __('recurring_income_realizations.fields.withholding_tax_amount'),
            'receipt_date' => __('recurring_income_realizations.fields.receipt_date'),
            'receipt_sub_account_id' => __('recurring_income_realizations.fields.receipt_sub_account'),
        ]);

        $validator->sometimes('amount', ['required', 'integer', 'min:1'], fn ($payload): bool => ($payload->input_mode ?? 'gross') === 'gross');
        $validator->sometimes('net_amount', ['required', 'integer', 'min:1'], fn ($payload): bool => $payload->input_mode === 'net_tax');
        $validator->sometimes('tax_amount', ['required', 'integer', 'min:0'], fn ($payload): bool => $payload->input_mode === 'net_tax');
        $validator->sometimes('tax_option', ['required', 'in:8,10'], fn ($payload): bool => $isTaxableFiscalYear && ($payload->input_mode ?? 'gross') === 'gross');
        $validator->sometimes('tax_option', ['prohibited'], fn ($payload): bool => ($payload->input_mode ?? 'gross') === 'net_tax');
        $validator->sometimes('input_mode', ['in:gross'], fn (): bool => ! $isTaxableFiscalYear);

        $validated = $validator->validate();

        if (($validated['input_mode'] ?? 'gross') === 'net_tax' && $isTaxableFiscalYear) {
            $detectedTaxOption = self::detectTaxOptionFromNetTax(
                (int) ($validated['net_amount'] ?? 0),
                (int) ($validated['tax_amount'] ?? 0),
            );

            if ($detectedTaxOption === null) {
                throw ValidationException::withMessages([
                    'tax_amount' => [__('recurring_income_realizations.validation.net_tax_invalid_rate')],
                ]);
            }

            $validated['tax_option'] = $detectedTaxOption;
        }

        $receiptDate = Carbon::parse($validated['receipt_date']);
        $plannedDate = $plannedTransaction->date?->copy();

        if ($plannedDate === null) {
            throw ValidationException::withMessages([
                'transaction' => ['予定取引の日付が見つかりません。'],
            ]);
        }

        if (
            $receiptDate->year !== $plannedDate->year
            || $receiptDate->month !== $plannedDate->month
        ) {
            if ($receiptDate->lt($plannedDate)) {
                throw ValidationException::withMessages([
                    'receipt_date' => ['この実装では、別の月で予定日より前の受取日は実現できません。'],
                ]);
            }
        }

        $withholdingTaxAmount = (int) ($validated['withholding_tax_amount'] ?? 0);
        $expectedWithholdingTaxAmount = (int) ($plan->withholding_tax_amount ?? 0);

        if ($withholdingTaxAmount !== $expectedWithholdingTaxAmount) {
            throw ValidationException::withMessages([
                'withholding_tax_amount' => ['現時点では、源泉徴収税額は予定どおりの金額でのみ実現できます。'],
            ]);
        }

        $taxType = $this->resolveTaxType($plan, $validated['tax_option'] ?? null);
        $grossAmount = $this->resolveGrossAmount($validated);
        $creditEntryOverrides = $this->creditEntryOverrides($validated);

        if ($plan->interval === 'yearly') {
            $confirmed = $plan->confirmTransaction($plannedTransaction->id, [
                'date' => $receiptDate->toDateString(),
                'amount' => $grossAmount,
                'debit_sub_account_id' => (int) $validated['receipt_sub_account_id'],
                'tax_type' => $taxType,
                ...$creditEntryOverrides,
            ], $actor);

            if ($confirmed === null) {
                throw ValidationException::withMessages([
                    'transaction' => ['予定取引を実現できませんでした。'],
                ]);
            }

            return collect([$confirmed]);
        }

        if (
            $receiptDate->year !== $plannedDate->year
            || $receiptDate->month !== $plannedDate->month
        ) {
            return $this->realizeWithAccountsReceivable(
                $plannedTransaction,
                $plan,
                $validated,
                $receiptDate,
                $actor,
            );
        }

        $confirmed = $plan->confirmTransaction($plannedTransaction->id, [
            'date' => $receiptDate->toDateString(),
            'amount' => $grossAmount,
            'debit_sub_account_id' => (int) $validated['receipt_sub_account_id'],
            'tax_type' => $taxType,
            ...$creditEntryOverrides,
        ], $actor);

        if ($confirmed === null) {
            throw ValidationException::withMessages([
                'transaction' => ['予定取引を実現できませんでした。'],
            ]);
        }

        return collect([$confirmed]);
    }

    private function realizeWithAccountsReceivable(
        Transaction $plannedTransaction,
        RecurringTransactionPlan $plan,
        array $validated,
        Carbon $receiptDate,
        User $actor,
    ): Collection {
        return DB::transaction(function () use (
            $plannedTransaction,
            $plan,
            $validated,
            $receiptDate,
            $actor,
        ): Collection {
            $accountsReceivableSubAccount = $plan->businessUnit->getSubAccountByName('売掛金', '売掛金');

            if ($accountsReceivableSubAccount === null) {
                throw ValidationException::withMessages([
                    'receipt_sub_account_id' => ['売掛金の補助科目が見つかりません。'],
                ]);
            }

            $plannedGrossAmount = $this->resolveGrossAmount($validated);
            $withholdingTaxAmount = (int) ($validated['withholding_tax_amount'] ?? 0);
            $taxType = $this->resolveTaxType($plan, $validated['tax_option'] ?? null);
            $creditEntry = [
                'sub_account_id' => $plan->credit_sub_account_id,
                'type' => 'credit',
                'tax_type' => $taxType,
            ];

            if (($validated['input_mode'] ?? 'gross') === 'net_tax') {
                $creditEntry['net_amount'] = (int) $validated['net_amount'];
                $creditEntry['tax_amount'] = (int) $validated['tax_amount'];
            } else {
                $creditEntry['gross_amount'] = $plannedGrossAmount;
            }

            $confirmedSalesTransaction = $this->plannedTransactionConfirmer->confirm(
                $plannedTransaction,
                $actor,
                $this->transactionRegistrar->buildPlannedTransactionData($plannedTransaction),
                [
                    [
                        'sub_account_id' => $accountsReceivableSubAccount->id,
                        'type' => 'debit',
                        'gross_amount' => $plannedGrossAmount,
                        'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                    ],
                    $creditEntry,
                ],
            );

            $settlementEntries = [
                [
                    'sub_account_id' => (int) $validated['receipt_sub_account_id'],
                    'type' => 'debit',
                    'gross_amount' => $plannedGrossAmount - $withholdingTaxAmount,
                    'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                ],
            ];

            if ($withholdingTaxAmount > 0) {
                $settlementEntries[] = [
                    'sub_account_id' => $plan->withholding_sub_account_id,
                    'type' => 'debit',
                    'gross_amount' => $withholdingTaxAmount,
                    'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                ];
            }

            $settlementEntries[] = [
                'sub_account_id' => $accountsReceivableSubAccount->id,
                'type' => 'credit',
                'gross_amount' => $plannedGrossAmount,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ];

            $settlementTransaction = $this->transactionRegistrar->register(
                $confirmedSalesTransaction->fiscalYear,
                [
                    'date' => $receiptDate->toDateString(),
                    'description' => $confirmedSalesTransaction->description,
                    'counterparty_id' => $confirmedSalesTransaction->counterparty_id,
                    'settled_transaction_id' => $confirmedSalesTransaction->id,
                    'is_planned' => $receiptDate->copy()->startOfDay()->gt(now()->startOfDay()),
                    'created_by' => $actor->id,
                ],
                $settlementEntries,
                $actor,
            );

            return collect([
                $confirmedSalesTransaction,
                $settlementTransaction,
            ]);
        }, attempts: 5);
    }

    private function resolveTaxType(RecurringTransactionPlan $plan, ?string $taxOption): string
    {
        if ($taxOption === '8') {
            return JournalEntry::TAX_TYPE_TAXABLE_SALES_8;
        }

        if ($taxOption === '10') {
            return JournalEntry::TAX_TYPE_TAXABLE_SALES_10;
        }

        return $plan->defaultTaxType();
    }

    private function resolveGrossAmount(array $validated): int
    {
        if (($validated['input_mode'] ?? 'gross') === 'net_tax') {
            return (int) $validated['net_amount'] + (int) $validated['tax_amount'];
        }

        return (int) $validated['amount'];
    }

    /**
     * @return array<string, int>
     */
    private function creditEntryOverrides(array $validated): array
    {
        if (($validated['input_mode'] ?? 'gross') !== 'net_tax') {
            return [];
        }

        return [
            'credit_net_amount' => (int) $validated['net_amount'],
            'credit_tax_amount' => (int) $validated['tax_amount'],
        ];
    }

    public static function detectTaxOptionFromNetTax(int $netAmount, int $taxAmount): ?string
    {
        $candidates = collect(['8', '10'])
            ->map(fn (string $taxOption): array => [
                'tax_option' => $taxOption,
                'diff' => abs(intdiv($netAmount * (int) $taxOption, 100) - $taxAmount),
            ]);

        $exactMatches = $candidates
            ->filter(fn (array $candidate): bool => $candidate['diff'] === 0)
            ->values();

        if ($exactMatches->count() === 1) {
            return $exactMatches->first()['tax_option'];
        }

        $nearMatches = $candidates
            ->filter(fn (array $candidate): bool => $candidate['diff'] <= 1)
            ->sortBy('diff')
            ->values();

        if ($nearMatches->count() === 1) {
            return $nearMatches->first()['tax_option'];
        }

        return null;
    }
}
