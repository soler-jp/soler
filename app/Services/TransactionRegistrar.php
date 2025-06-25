<?php

namespace App\Services;

use App\Models\Transaction;
use App\Validators\TransactionValidator;
use App\Validators\JournalEntryValidator;
use Illuminate\Support\Facades\DB;
use App\Models\FiscalYear;
use Illuminate\Validation\ValidationException;

class TransactionRegistrar
{
    /**
     * 取引と仕訳の登録を行う
     *
     * @param  array  $transactionData  取引情報（例: fiscal_year_id, date, description）
     * @param  array  $journalEntriesData  仕訳情報（複数）（例: account_id, type, amount, ...）
     * @return Transaction
     *
     * @throws \Illuminate\Validation\ValidationException
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

        foreach ($journalEntriesData as $entry) {
            JournalEntryValidator::validate($entry, false);
        }

        if (empty($journalEntriesData)) {
            throw new \InvalidArgumentException('仕訳データが空です。');
        }

        // ドメインロジック: 仕訳の金額がバランスしているか確認
        $totalDebit = collect($journalEntriesData)
            ->where('type', 'debit')
            ->sum('amount');

        $totalCredit = collect($journalEntriesData)
            ->where('type', 'credit')
            ->sum('amount');

        if ($totalDebit !== $totalCredit) {
            throw new \DomainException('仕訳の金額がバランスしていません。');
        }

        return DB::transaction(function () use ($transactionData, $journalEntriesData) {
            $transaction = Transaction::create(TransactionValidator::validate($transactionData));

            foreach ($journalEntriesData as $entry) {
                $entry['transaction_id'] = $transaction->id;
                $transaction->journalEntries()->create($entry);
            }

            return $transaction;
        });
    }
}
