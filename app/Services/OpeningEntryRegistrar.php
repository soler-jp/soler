<?php

namespace App\Services;

use App\Models\FiscalYear;
use App\Models\Transaction;
use DomainException;
use Illuminate\Support\Facades\DB;

class OpeningEntryRegistrar
{
    private const DESCRIPTION = '期首残高設定';

    /**
     * @var list<string>
     */
    private const ALLOWED_DEBIT_ACCOUNTS = [
        '現金',
        '定期預金',
        'その他の預金',
        '車両運搬具',
        '棚卸資産',
    ];

    public function register(FiscalYear $fiscalYear, array $entries): ?Transaction
    {
        if ($entries === []) {
            return null;
        }

        if ($fiscalYear->transactions()->where('is_opening_entry', true)->exists()) {
            throw new DomainException('この会計年度にはすでに期首仕訳が登録されています。');
        }

        return DB::transaction(function () use ($fiscalYear, $entries): Transaction {
            $transactionData = [
                'date' => $fiscalYear->start_date,
                'description' => self::DESCRIPTION,
                'is_opening_entry' => true,
                'fiscal_year_id' => $fiscalYear->id,
            ];

            $journalEntriesData = [];
            $totalAmount = 0;

            $capitalAccount = $fiscalYear->businessUnit->accounts()
                ->where('name', '元入金')
                ->firstOrFail();

            $capitalSubAccount = $capitalAccount->subAccounts()->firstOrCreate([
                'name' => $capitalAccount->name,
            ]);

            foreach ($entries as $entry) {
                $journalEntriesData[] = $this->buildDebitEntry($fiscalYear, $entry);
                $totalAmount += (int) $entry['amount'];
            }

            $journalEntriesData[] = [
                'sub_account_id' => $capitalSubAccount->id,
                'type' => 'credit',
                'net_amount' => $totalAmount,
            ];

            return $fiscalYear->registerTransaction($transactionData, $journalEntriesData);
        });
    }

    protected function buildDebitEntry(FiscalYear $fiscalYear, array $entry): array
    {
        if (empty($entry['sub_account_name'])) {
            throw new \InvalidArgumentException('sub_account_name は必須です。');
        }

        if (! isset($entry['amount']) || ! is_numeric($entry['amount']) || $entry['amount'] <= 0) {
            throw new \InvalidArgumentException("金額が不正です: {$entry['amount']}");
        }

        $account = $fiscalYear->businessUnit->accounts()
            ->where('name', $entry['account_name'])
            ->firstOrFail();

        if (! in_array($account->name, self::ALLOWED_DEBIT_ACCOUNTS, true)) {
            throw new \InvalidArgumentException(
                '借方の勘定科目は「現金」「定期預金」「その他の預金」「車両運搬具」「棚卸資産」のみ使用できます。'
            );
        }

        $subAccount = $account->subAccounts()->firstOrCreate([
            'name' => $entry['sub_account_name'],
        ]);

        return [
            'sub_account_id' => $subAccount->id,
            'type' => 'debit',
            'net_amount' => (int) $entry['amount'],
        ];
    }
}
