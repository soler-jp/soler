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

        $validated = Validator::make($input, [
            'amount' => ['required', 'integer', 'min:1'],
            'withholding_tax_amount' => ['nullable', 'integer', 'min:0'],
            'receipt_date' => ['required', 'date'],
            'receipt_sub_account_id' => ['required', $plan->businessUnit->subAccountExistsRule()],
        ])->validate();

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

        if ($plan->interval === 'yearly') {
            $confirmed = $plan->confirmTransaction($plannedTransaction->id, [
                'date' => $receiptDate->toDateString(),
                'amount' => (int) $validated['amount'],
                'debit_sub_account_id' => (int) $validated['receipt_sub_account_id'],
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
            'amount' => (int) $validated['amount'],
            'debit_sub_account_id' => (int) $validated['receipt_sub_account_id'],
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

            $plannedGrossAmount = (int) $validated['amount'];
            $withholdingTaxAmount = (int) ($validated['withholding_tax_amount'] ?? 0);

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
                    [
                        'sub_account_id' => $plan->credit_sub_account_id,
                        'type' => 'credit',
                        'gross_amount' => $plannedGrossAmount,
                        'tax_type' => $plan->defaultTaxType(),
                    ],
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
}
