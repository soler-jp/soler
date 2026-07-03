<?php

namespace App\Services;

use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\SubAccount;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;

class InventoryClosingService
{
    private const INVENTORY_ASSET_ACCOUNT = '棚卸資産';

    private const OPENING_INVENTORY_ACCOUNT = '期首商品（棚卸高）';

    private const CLOSING_INVENTORY_ACCOUNT = '期末商品（棚卸高）';

    public function __construct(
        private readonly TransactionRegistrar $transactionRegistrar,
    ) {}

    /**
     * 期末の実地棚卸高から棚卸の決算整理仕訳を登録する。
     *
     * 期末実地棚卸高は「棚卸資産」配下の SubAccount ごとに受け取り、SubAccount 単位で
     * 期首/期末を振り替える。これにより SubAccount を分離している場合でも各補助科目の
     * 貸借対照表残高が実態と一致する。
     *
     * 期首棚卸高は手入力せず、その年度の期首時点（期首仕訳）の各「棚卸資産」SubAccount
     * 残高から導出する。期首分・期末分をまとめて 1 伝票の決算整理仕訳として期末日付で
     * 登録する。損益科目（期首商品/期末商品）側は集計科目のため合算 1 行にまとめる。
     *
     * @param  array<int, int>  $closingAmounts  [棚卸資産 SubAccount ID => 期末の実地棚卸高]
     */
    public function registerFor(FiscalYear $fiscalYear, array $closingAmounts): ?Transaction
    {
        $inventoryAccount = $this->resolveInventoryAccount($fiscalYear);
        $closingAmounts = $this->normalizeClosingAmounts($inventoryAccount, $closingAmounts);

        if ($this->hasActiveClosingTransaction($fiscalYear)) {
            throw new \InvalidArgumentException('この会計年度にはすでに棚卸の決算整理仕訳が登録されています。');
        }

        $openingBalances = $this->resolveOpeningInventoryBalances($fiscalYear, $inventoryAccount);

        $subAccountIds = array_unique(array_merge(
            array_keys($openingBalances),
            array_keys($closingAmounts),
        ));
        sort($subAccountIds);

        $openingLines = [];
        $closingLines = [];
        $totalOpening = 0;
        $totalClosing = 0;

        foreach ($subAccountIds as $subAccountId) {
            $opening = $openingBalances[$subAccountId] ?? 0;
            $closing = $closingAmounts[$subAccountId] ?? 0;

            // 期首分: 貸方 その棚卸資産 SubAccount（期首の帳簿棚卸高）
            if ($opening > 0) {
                $openingLines[] = $this->buildEntry($subAccountId, JournalEntry::TYPE_CREDIT, $opening);
                $totalOpening += $opening;
            }

            // 期末分: 借方 その棚卸資産 SubAccount（期末の実地棚卸高）
            if ($closing > 0) {
                $closingLines[] = $this->buildEntry($subAccountId, JournalEntry::TYPE_DEBIT, $closing);
                $totalClosing += $closing;
            }
        }

        if ($totalOpening === 0 && $totalClosing === 0) {
            return null;
        }

        $journalEntries = [];

        // 期首分: 借方 期首商品（棚卸高）（合算）/ 貸方 各棚卸資産 SubAccount
        if ($totalOpening > 0) {
            $journalEntries[] = $this->buildEntry(
                $this->resolveSubAccount($fiscalYear, self::OPENING_INVENTORY_ACCOUNT)->id,
                JournalEntry::TYPE_DEBIT,
                $totalOpening,
            );
            $journalEntries = array_merge($journalEntries, $openingLines);
        }

        // 期末分: 借方 各棚卸資産 SubAccount / 貸方 期末商品（棚卸高）（合算）
        if ($totalClosing > 0) {
            $journalEntries = array_merge($journalEntries, $closingLines);
            $journalEntries[] = $this->buildEntry(
                $this->resolveSubAccount($fiscalYear, self::CLOSING_INVENTORY_ACCOUNT)->id,
                JournalEntry::TYPE_CREDIT,
                $totalClosing,
            );
        }

        return $this->transactionRegistrar->register(
            $fiscalYear,
            [
                'date' => $fiscalYear->end_date->toDateString(),
                'description' => sprintf('%d年 棚卸', $fiscalYear->year),
                'is_adjusting_entry' => true,
                'adjusting_entry_type' => Transaction::ADJUSTING_ENTRY_TYPE_INVENTORY_CLOSING,
            ],
            $journalEntries,
        );
    }

    /**
     * 入力された期末棚卸高を検証し、[SubAccount ID => 金額] に正規化する。
     * 棚卸資産の全 SubAccount について、0 を含めて明示的に指定させる。
     * 0 の指定は「期末残高なし（売り切り）」として扱う。
     *
     * @param  array<int, int|string>  $closingAmounts
     * @return array<int, int>
     */
    protected function normalizeClosingAmounts(Account $inventoryAccount, array $closingAmounts): array
    {
        $validSubAccountIds = $inventoryAccount->subAccounts()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $normalized = [];

        foreach ($closingAmounts as $subAccountId => $amount) {
            $subAccountId = (int) $subAccountId;
            $amount = $this->normalizeClosingAmount($amount);

            if ($amount < 0) {
                throw new \InvalidArgumentException('期末棚卸高は0以上で指定してください。');
            }

            if (! in_array($subAccountId, $validSubAccountIds, true)) {
                throw new \InvalidArgumentException('棚卸資産の補助科目を指定してください。');
            }

            $normalized[$subAccountId] = $amount;
        }

        sort($validSubAccountIds);
        $providedSubAccountIds = array_keys($normalized);
        sort($providedSubAccountIds);

        if ($providedSubAccountIds !== $validSubAccountIds) {
            throw new \InvalidArgumentException('棚卸資産の全補助科目について、0を含めて期末棚卸高を指定してください。');
        }

        return $normalized;
    }

    protected function normalizeClosingAmount(mixed $amount): int
    {
        if (is_int($amount)) {
            return $amount;
        }

        if (is_string($amount) && preg_match('/\A-?\d+\z/', $amount) === 1) {
            return (int) $amount;
        }

        throw new \InvalidArgumentException('期末棚卸高は整数で指定してください。');
    }

    /**
     * その年度の期首時点における「棚卸資産」SubAccount ごとの帳簿残高を求める。
     *
     * 期中に棚卸資産が動くのは期首仕訳（セットアップまたは翌期繰越）だけなので、
     * 期首仕訳の残高が期首棚卸高と一致する。棚卸の決算整理仕訳自体には影響されない。
     * 残高が正のものだけを返す。
     *
     * @return array<int, int> [SubAccount ID => 期首残高]
     */
    protected function resolveOpeningInventoryBalances(FiscalYear $fiscalYear, Account $inventoryAccount): array
    {
        $entries = JournalEntry::query()
            ->whereHas('transaction', function (Builder $query) use ($fiscalYear): void {
                $query->whereBelongsTo($fiscalYear)
                    ->where('is_active', true)
                    ->where('is_opening_entry', true);
            })
            ->whereHas('subAccount', function (Builder $query) use ($inventoryAccount): void {
                $query->where('account_id', $inventoryAccount->id);
            })
            ->get(['sub_account_id', 'type', 'net_amount', 'tax_amount']);

        $balances = [];

        foreach ($entries as $entry) {
            $amount = (int) $entry->net_amount + (int) $entry->tax_amount;
            $delta = $entry->type === JournalEntry::TYPE_DEBIT ? $amount : -$amount;
            $subAccountId = (int) $entry->sub_account_id;
            $balances[$subAccountId] = ($balances[$subAccountId] ?? 0) + $delta;
        }

        return array_filter($balances, fn (int $balance): bool => $balance > 0);
    }

    protected function hasActiveClosingTransaction(FiscalYear $fiscalYear): bool
    {
        return $fiscalYear->transactions()
            ->where('is_active', true)
            ->where('adjusting_entry_type', Transaction::ADJUSTING_ENTRY_TYPE_INVENTORY_CLOSING)
            ->exists();
    }

    /**
     * @return array{sub_account_id: int, type: string, net_amount: int, tax_type: string, tax_amount: int}
     */
    protected function buildEntry(int $subAccountId, string $type, int $amount): array
    {
        return [
            'sub_account_id' => $subAccountId,
            'type' => $type,
            'net_amount' => $amount,
            'tax_type' => JournalEntry::TAX_TYPE_NON_TAXABLE,
            'tax_amount' => 0,
        ];
    }

    protected function resolveInventoryAccount(FiscalYear $fiscalYear): Account
    {
        $account = $fiscalYear->businessUnit->getAccountByName(self::INVENTORY_ASSET_ACCOUNT);

        if ($account === null) {
            throw new \RuntimeException('棚卸資産の勘定科目が見つかりません。');
        }

        return $account;
    }

    protected function resolveSubAccount(FiscalYear $fiscalYear, string $accountName): SubAccount
    {
        $subAccount = $fiscalYear->businessUnit->getSubAccountByName($accountName, $accountName);

        if ($subAccount === null) {
            throw new \RuntimeException("{$accountName} の補助科目が見つかりません。");
        }

        return $subAccount;
    }
}
