<?php

namespace Tests\Feature;

use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionDiffer;
use App\Services\TransactionRegistrar;
use App\Support\AuditLogTransactionDiffFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuditLogTransactionDiffFormatterTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function single_pairで金額だけ変わる場合は金額差分だけを返す(): void
    {
        $user = User::factory()->create();
        $fiscalYear = $this->createFiscalYear($user, 'formatter 金額差分');
        $unit = $fiscalYear->businessUnit;
        $expense = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();
        $cash = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        $old = $this->registerTransaction($fiscalYear, $user, '改訂前', $expense->id, $cash->id, 1100);
        $new = $this->registerTransaction($fiscalYear, $user, '改訂後', $expense->id, $cash->id, 2200);
        $diff = app(TransactionDiffer::class)->diff($old, $new, $user);

        $lines = app(AuditLogTransactionDiffFormatter::class)->format($old, $new, $diff);

        $this->assertContains('摘要: 改訂前 -> 改訂後', $lines);
        $this->assertContains('金額: 1,100円 -> 2,200円', $lines);
        $this->assertNotContains('借方変更: 消耗品費 1,100円 -> 消耗品費 2,200円', $lines);
        $this->assertNotContains('貸方変更: 現金 1,100円 -> 現金 2,200円', $lines);
    }

    #[Test]
    public function single_pairで借方だけ変わる場合は借方差分だけを返す(): void
    {
        $user = User::factory()->create();
        $fiscalYear = $this->createFiscalYear($user, 'formatter 借方差分');
        $unit = $fiscalYear->businessUnit;
        $expense = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();
        $communication = $unit->getAccountByName('通信費')->subAccounts()->firstOrFail();
        $cash = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        $old = $this->registerTransaction($fiscalYear, $user, '差分なし', $expense->id, $cash->id, 1100);
        $new = $this->registerTransaction($fiscalYear, $user, '差分なし', $communication->id, $cash->id, 1100);
        $diff = app(TransactionDiffer::class)->diff($old, $new, $user);

        $lines = app(AuditLogTransactionDiffFormatter::class)->format($old, $new, $diff);

        $this->assertSame(['借方変更: 消耗品費 -> 通信費'], $lines);
    }

    #[Test]
    public function single_pairで借方貸方が変わる場合は科目差分だけを返す(): void
    {
        $user = User::factory()->create();
        $fiscalYear = $this->createFiscalYear($user, 'formatter 借貸差分');
        $unit = $fiscalYear->businessUnit;
        $expense = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();
        $communication = $unit->getAccountByName('通信費')->subAccounts()->firstOrFail();
        $cash = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $ownerLoan = $unit->getAccountByName('事業主借')->subAccounts()->firstOrFail();

        $old = $this->registerTransaction($fiscalYear, $user, '差分なし', $expense->id, $cash->id, 1100);
        $new = $this->registerTransaction($fiscalYear, $user, '差分なし', $communication->id, $ownerLoan->id, 1100);
        $diff = app(TransactionDiffer::class)->diff($old, $new, $user);

        $lines = app(AuditLogTransactionDiffFormatter::class)->format($old, $new, $diff);

        $this->assertSame([
            '借方変更: 消耗品費 -> 通信費',
            '貸方変更: 現金 -> 個人の財布・個人のクレジットカードで支払い',
        ], $lines);
    }

    #[Test]
    public function single_pairでdescriptionだけ変わる場合はdescription差分だけを返す(): void
    {
        $user = User::factory()->create();
        $fiscalYear = $this->createFiscalYear($user, 'formatter 摘要差分');
        $unit = $fiscalYear->businessUnit;
        $expense = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();
        $cash = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        $old = $this->registerTransaction($fiscalYear, $user, '改訂前摘要', $expense->id, $cash->id, 1100);
        $new = $this->registerTransaction($fiscalYear, $user, '改訂後摘要', $expense->id, $cash->id, 1100);
        $diff = app(TransactionDiffer::class)->diff($old, $new, $user);

        $lines = app(AuditLogTransactionDiffFormatter::class)->format($old, $new, $diff);

        $this->assertSame(['摘要: 改訂前摘要 -> 改訂後摘要'], $lines);
    }

    #[Test]
    public function single_pairで摘要と金額が変わる場合は両方の差分を返す(): void
    {
        $user = User::factory()->create();
        $fiscalYear = $this->createFiscalYear($user, 'formatter 摘要金額差分');
        $unit = $fiscalYear->businessUnit;
        $expense = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();
        $cash = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        $old = $this->registerTransaction($fiscalYear, $user, '改訂前摘要', $expense->id, $cash->id, 1100);
        $new = $this->registerTransaction($fiscalYear, $user, '改訂後摘要', $expense->id, $cash->id, 2200);
        $diff = app(TransactionDiffer::class)->diff($old, $new, $user);

        $lines = app(AuditLogTransactionDiffFormatter::class)->format($old, $new, $diff);

        $this->assertSame([
            '摘要: 改訂前摘要 -> 改訂後摘要',
            '金額: 1,100円 -> 2,200円',
        ], $lines);
    }

    #[Test]
    public function single_pairで金額と借方科目が変わる場合は金額行と借方差分を返す(): void
    {
        $user = User::factory()->create();
        $fiscalYear = $this->createFiscalYear($user, 'formatter 金額借方差分');
        $unit = $fiscalYear->businessUnit;
        $expense = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();
        $communication = $unit->getAccountByName('通信費')->subAccounts()->firstOrFail();
        $cash = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        $old = $this->registerTransaction($fiscalYear, $user, '差分なし', $expense->id, $cash->id, 1100);
        $new = $this->registerTransaction($fiscalYear, $user, '差分なし', $communication->id, $cash->id, 2200);
        $diff = app(TransactionDiffer::class)->diff($old, $new, $user);

        $lines = app(AuditLogTransactionDiffFormatter::class)->format($old, $new, $diff);

        $this->assertSame([
            '金額: 1,100円 -> 2,200円',
            '借方変更: 消耗品費 1,100円 -> 通信費 2,200円',
        ], $lines);
    }

    #[Test]
    public function single_pairでcreditだけ変わる場合はcredit差分だけを返す(): void
    {
        $user = User::factory()->create();
        $fiscalYear = $this->createFiscalYear($user, 'formatter 貸方差分');
        $unit = $fiscalYear->businessUnit;
        $expense = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();
        $cash = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $ownerLoan = $unit->getAccountByName('事業主借')->subAccounts()->firstOrFail();

        $old = $this->registerTransaction($fiscalYear, $user, '差分なし', $expense->id, $cash->id, 1100);
        $new = $this->registerTransaction($fiscalYear, $user, '差分なし', $expense->id, $ownerLoan->id, 1100);
        $diff = app(TransactionDiffer::class)->diff($old, $new, $user);

        $lines = app(AuditLogTransactionDiffFormatter::class)->format($old, $new, $diff);

        $this->assertSame([
            '貸方変更: 現金 -> 個人の財布・個人のクレジットカードで支払い',
        ], $lines);
    }

    private function createFiscalYear(User $user, string $unitName): FiscalYear
    {
        $unit = $user->createBusinessUnitWithDefaults(['name' => $unitName]);

        return $unit->createFiscalYear(2025, $user);
    }

    private function registerTransaction(
        FiscalYear $fiscalYear,
        User $actor,
        string $description,
        int $debitSubAccountId,
        int $creditSubAccountId,
        int $grossAmount,
    ): Transaction {
        return app(TransactionRegistrar::class)->register(
            $fiscalYear,
            [
                'date' => '2025-04-01',
                'description' => $description,
                'created_by' => $actor->id,
            ],
            [
                [
                    'sub_account_id' => $debitSubAccountId,
                    'type' => JournalEntry::TYPE_DEBIT,
                    'gross_amount' => $grossAmount,
                    'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
                ],
                [
                    'sub_account_id' => $creditSubAccountId,
                    'type' => JournalEntry::TYPE_CREDIT,
                    'gross_amount' => $grossAmount,
                    'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                ],
            ],
            $actor,
        );
    }
}
