<?php

namespace App\Services;

use App\Concerns\AuthorizesBusinessUnitAccess;
use App\Models\BusinessUnit;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\SubAccount;
use App\Models\Transaction;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class CashOnHandRegistrationService
{
    use AuthorizesBusinessUnitAccess;

    public function __construct(
        protected TransactionRevisor $transactionRevisor,
    ) {}

    /**
     * @param  array<int, array{label: string, opening_balance: int}>  $cashAccounts
     * @return array<int, SubAccount>
     */
    public function register(
        BusinessUnit $businessUnit,
        FiscalYear $fiscalYear,
        array $cashAccounts,
        ?User $actor,
    ): array {
        $this->authorizeBusinessUnitAccess($businessUnit, $actor, 'この事業体に事業用現金を登録する権限がありません。');
        $this->authorizeBusinessUnitAccess($fiscalYear, $actor, 'この会計年度に事業用現金を登録する権限がありません。');
        assert($actor instanceof User);

        if (! $fiscalYear->resolveBusinessUnit()->is($businessUnit)) {
            throw new DomainException('指定された会計年度は対象の事業体に属していません。');
        }

        $normalizedCashAccounts = $this->normalizeCashAccounts($cashAccounts);

        if ($normalizedCashAccounts === []) {
            throw new DomainException('事業用現金を1件以上入力してください。');
        }

        $cashAccount = $businessUnit->getAccountByName('現金');

        if ($cashAccount === null) {
            throw new DomainException('事業用現金の登録先となる勘定科目「現金」が存在しません。');
        }

        $labels = array_column($normalizedCashAccounts, 'label');

        if ($cashAccount->subAccounts()->whereIn('name', $labels)->exists()) {
            throw new DomainException('同名の事業用現金はすでに登録されています。');
        }

        return DB::transaction(function () use ($cashAccount, $fiscalYear, $normalizedCashAccounts, $actor): array {
            $registeredCashAccounts = [];

            foreach ($normalizedCashAccounts as $cashEntry) {
                $subAccount = $cashAccount->addCustomSubAccount($cashEntry['label'], $actor);

                $registeredCashAccounts[] = [
                    'sub_account' => $subAccount,
                    'opening_balance' => $cashEntry['opening_balance'],
                ];
            }

            $this->syncOpeningEntries($fiscalYear, $registeredCashAccounts, $actor);

            return array_map(
                fn (array $registeredCashAccount): SubAccount => $registeredCashAccount['sub_account'],
                $registeredCashAccounts,
            );
        });
    }

    /**
     * @param  array<int, mixed>  $cashAccounts
     * @return array<int, array{label: string, opening_balance: int}>
     */
    protected function normalizeCashAccounts(array $cashAccounts): array
    {
        $normalizedCashAccounts = [];

        foreach ($cashAccounts as $cashAccount) {
            if (! is_array($cashAccount)) {
                throw new DomainException('現金の入力形式が不正です。');
            }

            $label = trim((string) ($cashAccount['label'] ?? ''));

            if ($label === '') {
                throw new DomainException('現金の表示名を入力してください。');
            }

            if (! array_key_exists('opening_balance', $cashAccount)) {
                throw new DomainException('期首残高を入力してください。');
            }

            $openingBalance = $cashAccount['opening_balance'];

            if (! is_numeric($openingBalance) || (int) $openingBalance < 0) {
                throw new DomainException('期首残高は0円以上で入力してください。');
            }

            $normalizedCashAccounts[] = [
                'label' => $label,
                'opening_balance' => (int) $openingBalance,
            ];
        }

        if (count(array_unique(array_column($normalizedCashAccounts, 'label'))) !== count($normalizedCashAccounts)) {
            throw new DomainException('同名の事業用現金は同時に登録できません。');
        }

        return $normalizedCashAccounts;
    }

    /**
     * @param  array<int, array{sub_account: SubAccount, opening_balance: int}>  $registeredCashAccounts
     */
    protected function syncOpeningEntries(
        FiscalYear $fiscalYear,
        array $registeredCashAccounts,
        User $actor,
    ): void {
        $openingEntry = $this->activeOpeningEntry($fiscalYear);
        $cashAccountsWithBalance = array_values(array_filter(
            $registeredCashAccounts,
            fn (array $registeredCashAccount): bool => $registeredCashAccount['opening_balance'] > 0,
        ));

        if ($openingEntry === null) {
            if ($cashAccountsWithBalance === []) {
                return;
            }

            $fiscalYear->registerOpeningEntry(
                array_map(
                    fn (array $registeredCashAccount): array => [
                        'account_name' => '現金',
                        'sub_account_name' => $registeredCashAccount['sub_account']->name,
                        'amount' => $registeredCashAccount['opening_balance'],
                    ],
                    $cashAccountsWithBalance,
                ),
                $actor,
            );

            return;
        }

        if ($cashAccountsWithBalance === []) {
            return;
        }

        $creditEntry = $openingEntry->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT);

        if ($creditEntry === null) {
            throw new DomainException('既存の期首仕訳に貸方行が存在しません。');
        }

        if ($openingEntry->journalEntries->where('type', JournalEntry::TYPE_CREDIT)->count() > 1) {
            throw new DomainException('既存の期首仕訳に貸方行が複数存在するため、事業用現金の登録を続行できません。');
        }

        // 既存の借方行はそのまま保持し、新規に登録した現金補助科目だけを追加する。
        $revisedJournalEntries = $openingEntry->journalEntries
            ->where('type', JournalEntry::TYPE_DEBIT)
            ->map(fn (JournalEntry $entry): array => [
                'sub_account_id' => $entry->sub_account_id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => $entry->net_amount,
            ]);

        foreach ($cashAccountsWithBalance as $registeredCashAccount) {
            $revisedJournalEntries->push([
                'sub_account_id' => $registeredCashAccount['sub_account']->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => $registeredCashAccount['opening_balance'],
            ]);
        }

        $revisedJournalEntries = $revisedJournalEntries->values();
        $totalDebit = $revisedJournalEntries->sum('net_amount');

        $revisedJournalEntries->push([
            'sub_account_id' => $creditEntry->sub_account_id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => $totalDebit,
        ]);

        $this->transactionRevisor->revise($openingEntry, $actor, [
            'transaction' => [
                'revision_reason' => '事業用現金の期首残高を更新',
            ],
            'journal_entries' => $revisedJournalEntries->all(),
        ]);
    }

    protected function activeOpeningEntry(FiscalYear $fiscalYear): ?Transaction
    {
        return $fiscalYear->transactions()
            ->with('journalEntries')
            ->where('is_opening_entry', true)
            ->where('is_active', true)
            ->first();
    }
}
