<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use App\Validators\JournalEntryValidator;
use App\Validators\TransactionValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlannedTransactionConfirmer
{
    public function __construct(
        public TransactionRegistrar $transactionRegistrar,
    ) {}

    public function confirm(
        Transaction $transaction,
        ?User $user = null,
        array $overrides = [],
        array $journalEntriesData = [],
    ): Transaction {
        return DB::transaction(function () use ($transaction, $overrides, $journalEntriesData): Transaction {
            $lockedTransaction = Transaction::query()
                ->with(['fiscalYear.businessUnit', 'journalEntries'])
                ->lockForUpdate()
                ->findOrFail($transaction->getKey());

            if (! $lockedTransaction->is_active || ! $lockedTransaction->is_planned) {
                throw new \InvalidArgumentException('この取引は既に本登録されています。');
            }

            $fiscalYear = $lockedTransaction->fiscalYear;

            if ($fiscalYear === null) {
                throw ValidationException::withMessages([
                    'fiscal_year_id' => ['会計年度が見つかりません。'],
                ]);
            }

            if ($fiscalYear->is_closed) {
                throw ValidationException::withMessages([
                    'fiscal_year_id' => ['決算済みの会計年度に属する予定取引は確定できません。'],
                ]);
            }

            $transactionData = $this->transactionRegistrar->buildPlannedTransactionData(
                $lockedTransaction,
                $overrides,
            );
            $transactionData['fiscal_year_id'] = $fiscalYear->id;
            $transactionData['is_planned'] = false;

            $rawEntries = $journalEntriesData !== []
                ? $journalEntriesData
                : $this->transactionRegistrar->buildPlannedJournalEntries($lockedTransaction, $overrides);

            $normalizedEntries = $this->transactionRegistrar->prepareJournalEntries(
                $fiscalYear,
                $rawEntries,
            );

            if ($normalizedEntries === []) {
                throw new \InvalidArgumentException('予定取引の仕訳を確定できません。');
            }

            $validatedEntries = [];

            foreach ($normalizedEntries as $entry) {
                if (
                    (int) ($entry['net_amount'] ?? 0) === 0
                    && (int) ($entry['tax_amount'] ?? 0) === 0
                ) {
                    $validatedEntries[] = $entry;

                    continue;
                }

                $validatedEntries[] = JournalEntryValidator::validate($entry, false);
            }

            $this->transactionRegistrar->ensureTaxTypeAllowedForFiscalYear($fiscalYear, $validatedEntries);
            $this->transactionRegistrar->ensureEntriesBelongToBusinessUnit($fiscalYear, $validatedEntries);

            $transactionData = TransactionValidator::validate($transactionData);
            $this->transactionRegistrar->ensureDateWithinFiscalYear($fiscalYear, $transactionData['date']);

            $totalDebit = $this->transactionRegistrar->totalWithTax(array_filter($validatedEntries, fn (array $entry): bool => $entry['type'] === 'debit'));
            $totalCredit = $this->transactionRegistrar->totalWithTax(array_filter($validatedEntries, fn (array $entry): bool => $entry['type'] === 'credit'));

            if ($totalDebit !== $totalCredit) {
                throw new \DomainException(sprintf(
                    '仕訳の金額がバランスしていません（借方: %d / 貸方: %d / 差額: %+d）',
                    $totalDebit,
                    $totalCredit,
                    $totalDebit - $totalCredit
                ));
            }

            $lockedTransaction->forceFill($transactionData);
            $lockedTransaction->date = $transactionData['date'];
            $lockedTransaction->is_planned = false;
            $lockedTransaction->save();

            DB::table($lockedTransaction->getTable())
                ->where('id', $lockedTransaction->getKey())
                ->update([
                    'date' => $transactionData['date'],
                    'is_planned' => false,
                    'updated_at' => now(),
                ]);

            $lockedTransaction->journalEntries()->delete();

            foreach ($validatedEntries as $entry) {
                $lockedTransaction->journalEntries()->create($entry);
            }

            return $lockedTransaction->fresh(['journalEntries', 'fiscalYear']);
        }, attempts: 5);
    }
}
