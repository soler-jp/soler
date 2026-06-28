<?php

namespace App\Services;

use App\Models\FiscalYear;
use App\Models\JournalEntry;
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

        if ($fiscalYear->is_closed) {
            throw ValidationException::withMessages([
                'fiscal_year_id' => ['決算済みの会計年度には新規取引を登録できません。'],
            ]);
        }

        // バリデーション（失敗すると ValidationException をスロー）
        $transactionData['fiscal_year_id'] = $fiscalYear->id;
        $transactionData = TransactionValidator::validate($transactionData);

        $normalizedEntries = $this->normalizeEntries($fiscalYear, $journalEntriesData);
        $validatedEntries = [];

        foreach ($normalizedEntries as $entry) {
            $validatedEntries[] = JournalEntryValidator::validate($entry, false);
        }

        $this->ensureTaxTypeAllowedForFiscalYear($fiscalYear, $validatedEntries);

        $this->ensureEntriesBelongToBusinessUnit($fiscalYear, $validatedEntries);

        if (empty($journalEntriesData)) {
            throw new \InvalidArgumentException('仕訳データが空です。');
        }

        // ドメインロジック: 仕訳の金額がバランスしているか確認
        $totalDebit = $this->totalWithTax(array_filter($validatedEntries, fn ($e) => $e['type'] === JournalEntry::TYPE_DEBIT));
        $totalCredit = $this->totalWithTax(array_filter($validatedEntries, fn ($e) => $e['type'] === JournalEntry::TYPE_CREDIT));

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

    protected function normalizeEntries(FiscalYear $fiscalYear, array $journalEntriesData): array
    {
        return array_map(fn (array $entry) => $this->normalizeEntry($fiscalYear, $entry), $journalEntriesData);
    }

    protected function normalizeEntry(FiscalYear $fiscalYear, array $entry): array
    {
        if (! array_key_exists('gross_amount', $entry) || array_key_exists('net_amount', $entry)) {
            return $entry;
        }

        $grossAmount = (int) $entry['gross_amount'];
        $taxType = $entry['tax_type'] ?? null;

        if ($taxType === null && $fiscalYear->is_taxable) {
            throw ValidationException::withMessages([
                'tax_type' => ['課税事業者の税込入力では消費税区分が必須です。'],
            ]);
        }

        $taxType ??= $this->defaultTaxTypeForExemptBusiness($entry['type'] ?? null);
        [$netAmount, $taxAmount] = $this->splitGrossAmount($grossAmount, $taxType);

        unset($entry['gross_amount']);

        return array_merge($entry, [
            'net_amount' => $netAmount,
            'tax_amount' => $taxAmount,
            'tax_type' => $taxType,
        ]);
    }

    protected function defaultTaxTypeForExemptBusiness(?string $entryType): string
    {
        return match ($entryType) {
            JournalEntry::TYPE_CREDIT => JournalEntry::TAX_TYPE_DEEMED_TAXABLE_SALES_10,
            default => JournalEntry::TAX_TYPE_DEEMED_TAXABLE_PURCHASES_10,
        };
    }

    protected function splitGrossAmount(int $grossAmount, string $taxType): array
    {
        $rate = match ($taxType) {
            JournalEntry::TAX_TYPE_TAXABLE_SALES_10,
            JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            JournalEntry::TAX_TYPE_DEEMED_TAXABLE_SALES_10,
            JournalEntry::TAX_TYPE_DEEMED_TAXABLE_PURCHASES_10 => 10,
            JournalEntry::TAX_TYPE_TAXABLE_SALES_8,
            JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_8 => 8,
            JournalEntry::TAX_TYPE_NON_TAXABLE,
            JournalEntry::TAX_TYPE_TAX_FREE => 0,
            default => throw ValidationException::withMessages([
                'tax_type' => ['未対応の消費税区分です。'],
            ]),
        };

        if ($rate === 0) {
            return [$grossAmount, 0];
        }

        $netAmount = intdiv($grossAmount * 100, 100 + $rate);
        $taxAmount = $grossAmount - $netAmount;

        return [$netAmount, $taxAmount];
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

    protected function ensureTaxTypeAllowedForFiscalYear(FiscalYear $fiscalYear, array $validatedEntries): void
    {
        if (! $fiscalYear->is_taxable) {
            return;
        }

        foreach ($validatedEntries as $index => $entry) {
            $taxType = $entry['tax_type'] ?? null;

            if (in_array($taxType, [
                JournalEntry::TAX_TYPE_DEEMED_TAXABLE_SALES_10,
                JournalEntry::TAX_TYPE_DEEMED_TAXABLE_PURCHASES_10,
            ], true)) {
                throw ValidationException::withMessages([
                    "journal_entries.$index.tax_type" => ['課税事業者の会計年度では見なし消費税区分は使用できません。'],
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
