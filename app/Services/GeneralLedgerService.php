<?php

namespace App\Services;

use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\SubAccount;

class GeneralLedgerService
{
    public function generate(Account $account, FiscalYear $fiscalYear): array
    {
        $entries = $account->journalEntries()
            ->with('transaction')
            ->whereHas('transaction', function ($query) use ($fiscalYear) {
                $query->where('date', '>=', $fiscalYear->start_date)
                    ->where('date', '<=', $fiscalYear->end_date);
            })
            ->get()
            ->sortBy(fn($entry) => $entry->transaction->date)
            ->values();

        $balance = 0;
        $ledger = [];

        foreach ($entries as $entry) {
            $amount = $entry->amount;

            if ($entry->type === 'debit') {
                $balance += $amount;
                $ledger[] = [
                    'date' => $entry->transaction->date->toDateString(),
                    'description' => $entry->transaction->description,
                    'debit' => $amount,
                    'credit' => null,
                    'balance' => $balance,
                ];
            } elseif ($entry->type === 'credit') {
                $balance -= $amount;
                $ledger[] = [
                    'date' => $entry->transaction->date->toDateString(),
                    'description' => $entry->transaction->description,
                    'debit' => null,
                    'credit' => $amount,
                    'balance' => $balance,
                ];
            }
        }

        return $ledger;
    }

    public function generateForSubAccount(SubAccount $subAccount, FiscalYear $fiscalYear): array
    {
        $entries = $subAccount->journalEntries()
            ->with('transaction')
            ->whereHas('transaction', function ($query) use ($fiscalYear) {
                $query->where('date', '>=', $fiscalYear->start_date)
                    ->where('date', '<=', $fiscalYear->end_date);
            })
            ->get()
            ->sortBy(fn($entry) => $entry->transaction->date)
            ->values();

        $balance = 0;
        $ledger = [];

        foreach ($entries as $entry) {
            $amount = $entry->amount;

            if ($entry->type === 'debit') {
                $balance += $amount;
                $ledger[] = [
                    'date' => $entry->transaction->date->toDateString(),
                    'description' => $entry->transaction->description,
                    'debit' => $amount,
                    'credit' => null,
                    'balance' => $balance,
                ];
            } elseif ($entry->type === 'credit') {
                $balance -= $amount;
                $ledger[] = [
                    'date' => $entry->transaction->date->toDateString(),
                    'description' => $entry->transaction->description,
                    'debit' => null,
                    'credit' => $amount,
                    'balance' => $balance,
                ];
            }
        }

        return $ledger;
    }


    /// 現金出納帳 / 預金出納帳
    public function generateCashbook(FiscalYear $fiscalYear): array
    {
        $account = $fiscalYear->businessUnit->getAccountByName('現金');

        return $account
            ? $this->generate($account, $fiscalYear)
            : [];
    }
}
