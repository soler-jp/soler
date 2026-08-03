<?php

namespace App\Services;

use App\Concerns\AuthorizesBusinessUnitAccess;
use App\Models\Account;
use App\Models\BusinessUnit;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 開始残高の入力を期首仕訳へ変換する上位サービス。
 *
 * TODO: 将来的には銀行口座・事業用現金・固定資産・棚卸資産など、
 * 開始残高に関わる入口をこのサービスへ段階的に統合し、
 * OpeningEntryRegistrar は低レベルな期首仕訳登録/改訂部品へ寄せる。
 */
class OpeningBalanceRegistrationService
{
    use AuthorizesBusinessUnitAccess;

    public function __construct(
        protected OpeningEntryRegistrar $openingEntryRegistrar,
        protected TransactionRevisor $transactionRevisor,
    ) {}

    /**
     * @param  array{
     *     asset_accounts: array<int, array{account_name: string, amount: int}>,
     *     liability_accounts: array<int, array{account_name: string, amount: int}>,
     *     custom_asset_accounts: array<int, array{account_name: string, amount: int}>,
     *     custom_liability_accounts: array<int, array{account_name: string, amount: int}>
     * }  $inputs
     */
    public function register(
        BusinessUnit $businessUnit,
        FiscalYear $fiscalYear,
        array $inputs,
        ?User $actor,
    ): ?Transaction {
        $this->authorizeBusinessUnitAccess($businessUnit, $actor, 'この事業体に開始残高を登録する権限がありません。');
        $this->authorizeBusinessUnitAccess($fiscalYear, $actor, 'この会計年度に開始残高を登録する権限がありません。');
        assert($actor instanceof User);

        if (! $fiscalYear->resolveBusinessUnit()->is($businessUnit)) {
            throw new DomainException('指定された会計年度は対象の事業体に属していません。');
        }

        return DB::transaction(function () use ($businessUnit, $fiscalYear, $inputs, $actor): ?Transaction {
            $assetEntries = $this->normalizeEntries(
                $businessUnit,
                Account::TYPE_ASSET,
                $inputs['asset_accounts'] ?? [],
                $inputs['custom_asset_accounts'] ?? [],
                $actor,
            );

            $liabilityEntries = $this->normalizeEntries(
                $businessUnit,
                Account::TYPE_LIABILITY,
                $inputs['liability_accounts'] ?? [],
                $inputs['custom_liability_accounts'] ?? [],
                $actor,
            );

            $entries = [
                ...array_map(
                    fn (array $entry): array => [
                        'account_name' => $entry['account_name'],
                        'sub_account_name' => $entry['sub_account_name'],
                        'amount' => $entry['amount'],
                        'type' => JournalEntry::TYPE_DEBIT,
                    ],
                    $assetEntries,
                ),
                ...array_map(
                    fn (array $entry): array => [
                        'account_name' => $entry['account_name'],
                        'sub_account_name' => $entry['sub_account_name'],
                        'amount' => $entry['amount'],
                        'type' => JournalEntry::TYPE_CREDIT,
                    ],
                    $liabilityEntries,
                ),
            ];

            $assetTotal = array_sum(array_column($assetEntries, 'amount'));
            $liabilityTotal = array_sum(array_column($liabilityEntries, 'amount'));
            $capitalAmount = $assetTotal - $liabilityTotal;

            $capitalEntry = [
                'account_name' => '元入金',
                'sub_account_name' => '元入金',
                'amount' => abs($capitalAmount),
                'type' => $capitalAmount >= 0 ? JournalEntry::TYPE_CREDIT : JournalEntry::TYPE_DEBIT,
            ];

            $openingEntry = $this->activeOpeningEntry($fiscalYear);

            if ($openingEntry === null) {
                return $this->openingEntryRegistrar->registerForRollover(
                    $fiscalYear,
                    $entries,
                    $capitalEntry,
                    $actor,
                );
            }

            $capitalSubAccountId = $this->subAccountId($businessUnit, '元入金', '元入金');
            $managedSubAccountIds = $this->managedSubAccountIds($businessUnit, $inputs);

            if ($entries === [] && $managedSubAccountIds === []) {
                return $openingEntry;
            }

            $openingEntry->loadMissing('journalEntries');

            // 今回入力された資産・負債（管理対象）の既存行と元入金は入力値で置き換える。
            // 銀行口座・事業用現金など他フローが登録した行はそのまま保持する。
            $rows = $openingEntry->journalEntries
                ->reject(fn (JournalEntry $entry): bool => $entry->sub_account_id === $capitalSubAccountId
                    || in_array($entry->sub_account_id, $managedSubAccountIds, true))
                ->map(fn (JournalEntry $entry): array => [
                    'sub_account_id' => $entry->sub_account_id,
                    'type' => $entry->type,
                    'net_amount' => $entry->net_amount,
                ])
                ->values();

            foreach ($entries as $entry) {
                $rows->push([
                    'sub_account_id' => $this->subAccountId(
                        $businessUnit,
                        $entry['account_name'],
                        $entry['sub_account_name'],
                    ),
                    'type' => $entry['type'],
                    'net_amount' => $entry['amount'],
                ]);
            }

            $rows = $this->appendCapitalEntry($rows, $capitalSubAccountId);

            if ($rows->isEmpty()) {
                return $openingEntry;
            }

            return $this->transactionRevisor->revise($openingEntry, $actor, [
                'transaction' => [
                    'revision_reason' => '開始残高の資産・負債を更新',
                ],
                'journal_entries' => $rows->all(),
            ]);
        });
    }

    /**
     * 今回の入力が対象とする科目（金額0を含む）の補助科目IDを返す。
     * 既存の期首仕訳のうち、これらの科目の行は入力値で置き換える。
     *
     * @param  array<string, mixed>  $inputs
     * @return list<int>
     */
    protected function managedSubAccountIds(BusinessUnit $businessUnit, array $inputs): array
    {
        $names = [];

        foreach (['asset_accounts', 'custom_asset_accounts', 'liability_accounts', 'custom_liability_accounts'] as $field) {
            foreach ($inputs[$field] ?? [] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $name = trim((string) ($entry['account_name'] ?? ''));

                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        $ids = [];

        foreach (array_unique($names) as $name) {
            $subAccountId = $businessUnit->getSubAccountByName($name, $name)?->id;

            if ($subAccountId !== null) {
                $ids[] = $subAccountId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * 借方・貸方の差額を元入金として rows へ追加して返す。差額が 0 なら元入金行は追加しない。
     *
     * @param  Collection<int, array{sub_account_id: int, type: string, net_amount: int}>  $rows
     * @return Collection<int, array{sub_account_id: int, type: string, net_amount: int}>
     */
    protected function appendCapitalEntry(Collection $rows, int $capitalSubAccountId): Collection
    {
        $rows = $rows->values();

        $totalDebit = $rows->where('type', JournalEntry::TYPE_DEBIT)->sum('net_amount');
        $totalCredit = $rows->where('type', JournalEntry::TYPE_CREDIT)->sum('net_amount');
        $capital = $totalDebit - $totalCredit;

        if ($capital > 0) {
            $rows->push([
                'sub_account_id' => $capitalSubAccountId,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => $capital,
            ]);
        } elseif ($capital < 0) {
            $rows->push([
                'sub_account_id' => $capitalSubAccountId,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => -$capital,
            ]);
        }

        return $rows->values();
    }

    /**
     * @param  array<int, array{account_name: string, amount: int}>  $defaultEntries
     * @param  array<int, array{account_name: string, amount: int}>  $customEntries
     * @return array<int, array{account_name: string, sub_account_name: string, amount: int}>
     */
    protected function normalizeEntries(
        BusinessUnit $businessUnit,
        string $accountType,
        array $defaultEntries,
        array $customEntries,
        User $actor,
    ): array {
        $entries = [];

        foreach ($defaultEntries as $entry) {
            if (($entry['amount'] ?? 0) <= 0) {
                continue;
            }

            $account = $businessUnit->getAccountByName($entry['account_name']);

            if (! $account instanceof Account || $account->type !== $accountType) {
                throw new DomainException('開始残高の勘定科目が不正です。');
            }

            $entries[] = [
                'account_name' => $account->name,
                'sub_account_name' => $account->name,
                'amount' => (int) $entry['amount'],
            ];
        }

        foreach ($customEntries as $entry) {
            if (($entry['amount'] ?? 0) <= 0) {
                continue;
            }

            $accountName = trim((string) $entry['account_name']);

            if ($accountName === '') {
                throw new DomainException('勘定科目名を入力してください。');
            }

            $account = $businessUnit->getAccountByName($accountName);

            if ($account === null) {
                $account = $businessUnit->addCustomAccount($accountType, $accountName, null, $actor);
            }

            if ($account->type !== $accountType) {
                throw new DomainException('同名の勘定科目が別の区分ですでに存在します。');
            }

            $entries[] = [
                'account_name' => $account->name,
                'sub_account_name' => $account->name,
                'amount' => (int) $entry['amount'],
            ];
        }

        return $entries;
    }

    protected function activeOpeningEntry(FiscalYear $fiscalYear): ?Transaction
    {
        return $fiscalYear->transactions()
            ->where('is_opening_entry', true)
            ->where('is_active', true)
            ->first();
    }

    protected function subAccountId(
        BusinessUnit $businessUnit,
        string $accountName,
        string $subAccountName,
    ): int {
        return $businessUnit->getSubAccountByName($accountName, $subAccountName)?->id
            ?? throw new DomainException('開始残高の補助科目が見つかりません。');
    }
}
