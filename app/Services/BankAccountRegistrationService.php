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

    public function register(
        BusinessUnit $businessUnit,
        FiscalYear $fiscalYear,
        string $bankName,
        int $openingBalance,
        ?User $actor,
    ): SubAccount {
        $this->authorizeBusinessUnitAccess($businessUnit, $actor, 'この事業体に銀行口座を登録する権限がありません。');
        $this->authorizeBusinessUnitAccess($fiscalYear, $actor, 'この会計年度に銀行口座を登録する権限がありません。');
        assert($actor instanceof User);

        $normalizedBankName = trim($bankName);

        if ($normalizedBankName === '') {
            throw new DomainException('銀行名を入力してください。');
        }

        if ($openingBalance < 0) {
            throw new DomainException('期首残高は0円以上で入力してください。');
        }

        if (! $fiscalYear->resolveBusinessUnit()->is($businessUnit)) {
            throw new DomainException('指定された会計年度は対象の事業体に属していません。');
        }

        $bankAccount = $businessUnit->getAccountByName('その他の預金');

        if ($bankAccount === null) {
            throw new DomainException('銀行口座の登録先となる勘定科目「その他の預金」が存在しません。');
        }

        if ($bankAccount->subAccounts()->where('name', $normalizedBankName)->exists()) {
            throw new DomainException('同名の銀行口座はすでに登録されています。');
        }

        return DB::transaction(function () use ($bankAccount, $fiscalYear, $normalizedBankName, $openingBalance, $actor): SubAccount {
            $subAccount = $bankAccount->addCustomSubAccount($normalizedBankName, $actor);

            $this->syncOpeningEntry($fiscalYear, $subAccount, $openingBalance, $actor);

            return $subAccount;
        });
    }

    protected function syncOpeningEntry(
        FiscalYear $fiscalYear,
        SubAccount $subAccount,
        int $openingBalance,
        User $actor,
    ): void {
        $openingEntry = $this->activeOpeningEntry($fiscalYear);

        if ($openingEntry === null) {
            if ($openingBalance === 0) {
                return;
            }

            $fiscalYear->registerOpeningEntry([
                [
                    'account_name' => 'その他の預金',
                    'sub_account_name' => $subAccount->name,
                    'amount' => $openingBalance,
                ],
            ], $actor);

            return;
        }

        if ($openingBalance === 0) {
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

        $revisedJournalEntries = $openingEntry->journalEntries
            ->where('type', JournalEntry::TYPE_DEBIT)
            ->map(function (JournalEntry $entry): array {
                return [
                    'sub_account_id' => $entry->sub_account_id,
                    'type' => JournalEntry::TYPE_DEBIT,
                    'net_amount' => $entry->net_amount,
                ];
            })
            ->push([
                'sub_account_id' => $subAccount->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => $openingBalance,
            ]);

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
