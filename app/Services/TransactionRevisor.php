<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TransactionRevisor
{
    public function __construct(
        public TransactionRegistrar $transactionRegistrar
    ) {}

    public function revise(Transaction $transaction, User $user, array $data): Transaction
    {
        $validated = Validator::make(
            $data,
            [
                'transaction.revision_reason' => ['required', 'string', 'max:255'],
                'journal_entries' => ['required', 'array', 'min:1'],
            ],
            [],
            [
                'transaction.revision_reason' => '修正理由',
                'journal_entries' => '仕訳明細',
            ]
        )->validate();

        return DB::transaction(function () use ($transaction, $user, $validated) {
            $lockedTransaction = Transaction::query()
                ->with('fiscalYear')
                ->lockForUpdate()
                ->findOrFail($transaction->getKey());

            $this->ensureTransactionCanBeRevised($lockedTransaction);

            if ($lockedTransaction->revision()->exists()) {
                throw new \InvalidArgumentException('この取引はすでに修正されています。');
            }

            /** @var array<string, mixed> $revisedTransactionData */
            $revisedTransactionData = [
                'date' => $lockedTransaction->date?->toDateString(),
                'description' => $lockedTransaction->description,
                'remarks' => $lockedTransaction->remarks,
                'counterparty_id' => $lockedTransaction->counterparty_id,
                'created_by' => $user->id,
                'revised_from_transaction_id' => $lockedTransaction->id,
                'revision_reason' => $validated['transaction']['revision_reason'],
            ];

            $revisedTransaction = $this->transactionRegistrar->register(
                $lockedTransaction->fiscalYear,
                $revisedTransactionData,
                $validated['journal_entries'],
            );

            $lockedTransaction->deactivate($user, '修正による改訂');

            return $revisedTransaction->fresh(['journalEntries', 'revisedFrom']);
        }, attempts: 5);
    }

    protected function ensureTransactionCanBeRevised(Transaction $transaction): void
    {
        if (! $transaction->is_active) {
            throw new \InvalidArgumentException('無効化済みの取引は修正できません。');
        }

        if ($transaction->is_opening_entry) {
            throw new \InvalidArgumentException('期首仕訳はこの修正機能の対象外です。');
        }

        if ($transaction->is_planned) {
            throw new \InvalidArgumentException('予定取引はこの修正機能の対象外です。');
        }

        if ($transaction->is_adjusting_entry) {
            throw new \InvalidArgumentException('決算整理仕訳はこの修正機能の対象外です。');
        }

        if ($transaction->recurring_transaction_plan_id !== null) {
            throw new \InvalidArgumentException('定期取引計画由来の取引はこの修正機能の対象外です。');
        }

        if ($transaction->credit_card_import_batch_id !== null) {
            throw new \InvalidArgumentException('クレジットカード取込由来の取引はこの修正機能の対象外です。');
        }

        if ($transaction->depreciationEntries()->exists()) {
            throw new \InvalidArgumentException('減価償却仕訳はこの修正機能の対象外です。');
        }

        if ($transaction->fiscalYear->is_closed) {
            throw ValidationException::withMessages([
                'transaction' => ['決算済みの会計年度に属する取引は修正できません。'],
            ]);
        }
    }
}
