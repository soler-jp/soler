<?php

namespace App\Services;

use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Validators\JournalEntryValidator;
use App\Validators\TransactionValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransactionRegistrar
{
    /**
     * 取引と仕訳の登録を行う
     *
     * @param  array  $transactionData  取引情報（例: fiscal_year_id, date, description）
     * @param  array  $journalEntriesData  仕訳情報（複数）（例: sub_account_id, type, net_amount, ...）
     *
     * @throws ValidationException
     */
    public function register(?FiscalYear $fiscalYear, array $transactionData, array $journalEntriesData): Transaction
    {
        // fiscalYear がnullの場合はバリデーションエラー
        if (is_null($fiscalYear)) {
            throw ValidationException::withMessages([
                'fiscal_year_id' => ['Fiscal year is required.'],
            ]);
        }

        // バリデーション（失敗すると ValidationException をスロー）
        $transactionData['fiscal_year_id'] = $fiscalYear->id;
        $transactionData = TransactionValidator::validate($transactionData);

        $validatedEntries = [];

        foreach ($journalEntriesData as $entry) {
            $validatedEntries[] = JournalEntryValidator::validate($entry, false);
        }

        $this->ensureEntriesBelongToBusinessUnit($fiscalYear, $validatedEntries);

        if (empty($journalEntriesData)) {
            throw new \InvalidArgumentException('仕訳データが空です。');
        }

        // ドメインロジック: 仕訳の金額がバランスしているか確認
        $totalDebit = $this->totalWithTax(array_filter($journalEntriesData, fn ($e) => $e['type'] === 'debit'));
        $totalCredit = $this->totalWithTax(array_filter($journalEntriesData, fn ($e) => $e['type'] === 'credit'));

        if ($totalDebit !== $totalCredit) {
            $diff = $totalDebit - $totalCredit;
            throw new \DomainException(sprintf(
                '仕訳の金額がバランスしていません（借方: %d / 貸方: %d / 差額: %+d）',
                $totalDebit,
                $totalCredit,
                $diff
            ));
        }

        return DB::transaction(function () use ($transactionData, $validatedEntries) {
            $transaction = Transaction::create(TransactionValidator::validate($transactionData));

            foreach ($validatedEntries as $entry) {
                $entry['transaction_id'] = $transaction->id;
                $transaction->journalEntries()->create($entry);
            }

            return $transaction;
        });
    }

    public function totalWithTax(array $entries): int
    {
        return collect($entries)->sum(fn ($e) => (int) ($e['net_amount'] ?? 0) + (int) ($e['tax_amount'] ?? 0));
    }

    protected function ensureEntriesBelongToBusinessUnit(FiscalYear $fiscalYear, array $validatedEntries): void
    {
        $businessUnit = $fiscalYear->businessUnit;

        foreach ($validatedEntries as $index => $entry) {
            if (! $businessUnit->hasSubAccount((int) $entry['sub_account_id'])) {
                throw ValidationException::withMessages([
                    "journal_entries.$index.sub_account_id" => ['選択中の事業体に属する補助科目を指定してください。'],
                ]);
            }
        }
    }

    public function confirmPlanned(Transaction $transaction): Transaction
    {
        if (! $transaction->is_planned) {
            throw new \InvalidArgumentException('この取引は既に本登録されています。');
        }

        return DB::transaction(function () use ($transaction) {
            $transaction->is_planned = false;
            $transaction->save();

            foreach ($transaction->journalEntries as $entry) {
                $entry->save();
            }

            return $transaction->fresh();
        });
    }

    public function cancelPlanned(Transaction $transaction): Transaction
    {
        if (! $transaction->is_planned) {
            throw new \InvalidArgumentException('本登録された取引は取消できません。');
        }

        $originalEntries = $transaction->journalEntries()->get();

        if ($originalEntries->isEmpty()) {
            throw new \RuntimeException('元の仕訳が存在しません。');
        }

        $zeroedEntries = $originalEntries->map(function ($entry) {
            return [
                'sub_account_id' => $entry->sub_account_id,
                'type' => $entry->type,
                'net_amount' => 0,
                'tax_type' => null,
                'tax_amount' => 0,
            ];
        })->all();

        return $this->confirmPlanned($transaction, [
            'description' => $transaction->description.'（取消）',
            'date' => $transaction->date->toDateString(),
            'journal_entries' => $zeroedEntries,
        ]);
    }
}
