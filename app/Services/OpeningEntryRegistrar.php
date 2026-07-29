<?php

namespace App\Services;

use App\Concerns\AuthorizesBusinessUnitAccess;
use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class OpeningEntryRegistrar
{
    use AuthorizesBusinessUnitAccess;

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

    public function register(FiscalYear $fiscalYear, array $entries, User $actor): ?Transaction
    {
        $this->authorizeBusinessUnitAccess($fiscalYear, $actor, 'この会計年度に期首仕訳を登録する権限がありません。');

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

    /**
     * 翌期繰越用に、貸借対照表科目と元入金の調整額から期首仕訳を登録する。
     *
     * @param  array<int, array{account_name: string, sub_account_name: string, amount: int, type: 'debit'|'credit'}>  $entries
     * @param  array{account_name: string, sub_account_name: string, amount: int, type: 'debit'|'credit'}  $capitalEntry
     */
    public function registerForRollover(FiscalYear $fiscalYear, array $entries, array $capitalEntry, User $actor): ?Transaction
    {
        $this->authorizeBusinessUnitAccess($fiscalYear, $actor, 'この会計年度に期首仕訳を登録する権限がありません。');

        if ($entries === [] && (int) $capitalEntry['amount'] === 0) {
            return null;
        }

        if ($fiscalYear->transactions()->where('is_opening_entry', true)->exists()) {
            throw new DomainException('この会計年度にはすでに期首仕訳が登録されています。');
        }

        return DB::transaction(function () use ($fiscalYear, $entries, $capitalEntry): Transaction {
            $transactionData = [
                'date' => $fiscalYear->start_date,
                'description' => self::DESCRIPTION,
                'is_opening_entry' => true,
                'fiscal_year_id' => $fiscalYear->id,
            ];

            $journalEntriesData = [];

            foreach ($entries as $entry) {
                $journalEntriesData[] = $this->buildRolloverEntry($fiscalYear, $entry);
            }

            if ((int) $capitalEntry['amount'] > 0) {
                $journalEntriesData[] = $this->buildCapitalEntry($fiscalYear, $capitalEntry);
            }

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

    /**
     * @param  array{account_name: string, sub_account_name: string, amount: int, type: 'debit'|'credit'}  $entry
     */
    protected function buildRolloverEntry(FiscalYear $fiscalYear, array $entry): array
    {
        if (empty($entry['sub_account_name'])) {
            throw new \InvalidArgumentException('sub_account_name は必須です。');
        }

        if (! isset($entry['amount']) || ! is_numeric($entry['amount']) || $entry['amount'] <= 0) {
            throw new \InvalidArgumentException("金額が不正です: {$entry['amount']}");
        }

        if (! in_array($entry['type'], [JournalEntry::TYPE_DEBIT, JournalEntry::TYPE_CREDIT], true)) {
            throw new \InvalidArgumentException('type は debit か credit を指定してください。');
        }

        $account = $fiscalYear->businessUnit->accounts()
            ->where('name', $entry['account_name'])
            ->firstOrFail();

        if (! in_array($account->type, [Account::TYPE_ASSET, Account::TYPE_LIABILITY], true)) {
            throw new \InvalidArgumentException('繰越の期首仕訳には資産科目または負債科目のみ使用できます。');
        }

        $subAccount = $account->subAccounts()->firstOrCreate([
            'name' => $entry['sub_account_name'],
        ]);

        return [
            'sub_account_id' => $subAccount->id,
            'type' => $entry['type'],
            'net_amount' => (int) $entry['amount'],
        ];
    }

    /**
     * @param  array{account_name: string, sub_account_name: string, amount: int, type: 'debit'|'credit'}  $entry
     */
    protected function buildCapitalEntry(FiscalYear $fiscalYear, array $entry): array
    {
        if (empty($entry['sub_account_name'])) {
            throw new \InvalidArgumentException('sub_account_name は必須です。');
        }

        if (! isset($entry['amount']) || ! is_numeric($entry['amount']) || $entry['amount'] < 0) {
            throw new \InvalidArgumentException("金額が不正です: {$entry['amount']}");
        }

        if (! in_array($entry['type'], [JournalEntry::TYPE_DEBIT, JournalEntry::TYPE_CREDIT], true)) {
            throw new \InvalidArgumentException('type は debit か credit を指定してください。');
        }

        $capitalAccount = $fiscalYear->businessUnit->accounts()
            ->where('name', '元入金')
            ->firstOrFail();

        $capitalSubAccount = $capitalAccount->subAccounts()->firstOrCreate([
            'name' => $capitalAccount->name,
        ]);

        return [
            'sub_account_id' => $capitalSubAccount->id,
            'type' => $entry['type'],
            'net_amount' => (int) $entry['amount'],
        ];
    }
}
