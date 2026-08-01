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

class BankAccountRegistrationService
{
    use AuthorizesBusinessUnitAccess;

    public function __construct(
        protected TransactionRevisor $transactionRevisor,
    ) {}

    /**
     * @param  array<int, array{label: string, opening_balance: int}>  $bankAccounts
     * @return array<int, SubAccount>
     */
    public function register(
        BusinessUnit $businessUnit,
        FiscalYear $fiscalYear,
        array $bankAccounts,
        ?User $actor,
    ): array {
        $this->authorizeBusinessUnitAccess($businessUnit, $actor, 'この事業体に銀行口座を登録する権限がありません。');
        $this->authorizeBusinessUnitAccess($fiscalYear, $actor, 'この会計年度に銀行口座を登録する権限がありません。');
        assert($actor instanceof User);

        if (! $fiscalYear->resolveBusinessUnit()->is($businessUnit)) {
            throw new DomainException('指定された会計年度は対象の事業体に属していません。');
        }

        $normalizedBankAccounts = $this->normalizeBankAccounts($bankAccounts);

        if ($normalizedBankAccounts === []) {
            throw new DomainException('銀行口座を1件以上入力してください。');
        }

        $bankAccount = $businessUnit->getAccountByName('その他の預金');

        if ($bankAccount === null) {
            throw new DomainException('銀行口座の登録先となる勘定科目「その他の預金」が存在しません。');
        }

        $labels = array_column($normalizedBankAccounts, 'label');

        if ($bankAccount->subAccounts()->whereIn('name', $labels)->exists()) {
            throw new DomainException('同名の銀行口座はすでに登録されています。');
        }

        return DB::transaction(function () use ($bankAccount, $fiscalYear, $normalizedBankAccounts, $actor): array {
            $registeredBankAccounts = [];

            foreach ($normalizedBankAccounts as $bankEntry) {
                $subAccount = $bankAccount->addCustomSubAccount($bankEntry['label'], $actor);

                $registeredBankAccounts[] = [
                    'sub_account' => $subAccount,
                    'opening_balance' => $bankEntry['opening_balance'],
                ];
            }

            $this->syncOpeningEntries($fiscalYear, $registeredBankAccounts, $actor);

            return array_map(
                fn (array $registeredBankAccount): SubAccount => $registeredBankAccount['sub_account'],
                $registeredBankAccounts,
            );
        });
    }

    /**
     * @param  array<int, mixed>  $bankAccounts
     * @return array<int, array{label: string, opening_balance: int}>
     */
    protected function normalizeBankAccounts(array $bankAccounts): array
    {
        $normalizedBankAccounts = [];

        foreach ($bankAccounts as $bankAccount) {
            if (! is_array($bankAccount)) {
                throw new DomainException('銀行口座の入力形式が不正です。');
            }

            $label = trim((string) ($bankAccount['label'] ?? ''));

            if ($label === '') {
                throw new DomainException('銀行名を入力してください。');
            }

            if (! array_key_exists('opening_balance', $bankAccount)) {
                throw new DomainException('期首残高を入力してください。');
            }

            $openingBalance = $bankAccount['opening_balance'];

            if (! is_numeric($openingBalance) || (int) $openingBalance < 0) {
                throw new DomainException('期首残高は0円以上で入力してください。');
            }

            $normalizedBankAccounts[] = [
                'label' => $label,
                'opening_balance' => (int) $openingBalance,
            ];
        }

        if (count(array_unique(array_column($normalizedBankAccounts, 'label'))) !== count($normalizedBankAccounts)) {
            throw new DomainException('同名の銀行口座は同時に登録できません。');
        }

        return $normalizedBankAccounts;
    }

    /**
     * @param  array<int, array{sub_account: SubAccount, opening_balance: int}>  $registeredBankAccounts
     */
    protected function syncOpeningEntries(
        FiscalYear $fiscalYear,
        array $registeredBankAccounts,
        User $actor,
    ): void {
        $openingEntry = $this->activeOpeningEntry($fiscalYear);
        $bankAccountsWithBalance = array_values(array_filter(
            $registeredBankAccounts,
            fn (array $registeredBankAccount): bool => $registeredBankAccount['opening_balance'] > 0,
        ));

        if ($openingEntry === null) {
            if ($bankAccountsWithBalance === []) {
                return;
            }

            $fiscalYear->registerOpeningEntry(array_map(
                fn (array $registeredBankAccount): array => [
                    'account_name' => 'その他の預金',
                    'sub_account_name' => $registeredBankAccount['sub_account']->name,
                    'amount' => $registeredBankAccount['opening_balance'],
                ],
                $bankAccountsWithBalance,
            ), $actor);

            return;
        }

        if ($bankAccountsWithBalance === []) {
            return;
        }

        $creditEntry = $openingEntry->journalEntries
            ->firstWhere('type', JournalEntry::TYPE_CREDIT);

        if ($creditEntry === null) {
            throw new DomainException('既存の期首仕訳に貸方行が存在しません。');
        }

        if ($openingEntry->journalEntries->where('type', JournalEntry::TYPE_CREDIT)->count() > 1) {
            throw new DomainException('既存の期首仕訳に貸方行が複数存在するため、銀行口座の登録を続行できません。');
        }

        // 既存の借方行はそのまま保持し、新規に登録した銀行口座補助科目だけを追加する。
        $revisedJournalEntries = $openingEntry->journalEntries
            ->where('type', JournalEntry::TYPE_DEBIT)
            ->map(function (JournalEntry $entry): array {
                return [
                    'sub_account_id' => $entry->sub_account_id,
                    'type' => JournalEntry::TYPE_DEBIT,
                    'net_amount' => $entry->net_amount,
                ];
            });

        foreach ($bankAccountsWithBalance as $registeredBankAccount) {
            $revisedJournalEntries->push([
                'sub_account_id' => $registeredBankAccount['sub_account']->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => $registeredBankAccount['opening_balance'],
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
                'revision_reason' => '銀行口座の期首残高を更新',
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
