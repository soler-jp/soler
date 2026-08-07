<?php

namespace Tests\Feature;

use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionDiffer;
use App\Services\TransactionRegistrar;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionDifferTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 取引本体と仕訳明細の差分を取得できる(): void
    {
        $user = User::factory()->create();
        $fiscalYear = $this->createFiscalYear($user, '差分比較事業体');
        $unit = $fiscalYear->businessUnit;
        $expense = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();
        $cash = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        $old = $this->registerTransaction($fiscalYear, $user, [
            'description' => '改訂前の取引',
        ], [
            [
                'sub_account_id' => $expense->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'gross_amount' => 1100,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'gross_amount' => 1100,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ]);

        $new = $this->registerTransaction($fiscalYear, $user, [
            'description' => '改訂後の取引',
        ], [
            [
                'sub_account_id' => $expense->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'gross_amount' => 2200,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'gross_amount' => 2200,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ]);

        $diff = app(TransactionDiffer::class)->diff($old, $new, $user);

        $this->assertTrue($diff->hasChanges());
        $this->assertSame(['改訂前の取引', '改訂後の取引'], $diff->subjectChanges()['description'] ?? null);
        $this->assertSame([1100, 2200], $diff->derivedChanges()['total_amount'] ?? null);

        $journalEntryChanges = $diff->relatedChanges()['journal_entries'];

        $this->assertCount(2, $journalEntryChanges['updated']);
        $this->assertSame([], $journalEntryChanges['created']);
        $this->assertSame([], $journalEntryChanges['deleted']);
        $this->assertSame(
            [1000, 2000],
            $journalEntryChanges['updated'][0]['changes']['net_amount'] ?? null,
        );
    }

    #[Test]
    public function 借方と貸方が入れ替わる変更はcreated_and_deletedとして扱う(): void
    {
        $user = User::factory()->create();
        $fiscalYear = $this->createFiscalYear($user, '借貸入れ替え事業体');
        $unit = $fiscalYear->businessUnit;
        $expense = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();
        $cash = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        $old = $this->registerTransaction($fiscalYear, $user, [], [
            [
                'sub_account_id' => $expense->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'gross_amount' => 1100,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'gross_amount' => 1100,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ]);

        $new = $this->registerTransaction($fiscalYear, $user, [], [
            [
                'sub_account_id' => $cash->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'gross_amount' => 1100,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $expense->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'gross_amount' => 1100,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ]);

        $journalEntryChanges = app(TransactionDiffer::class)->diff($old, $new, $user)->relatedChanges()['journal_entries'];

        $this->assertSame([], $journalEntryChanges['updated']);
        $this->assertCount(2, $journalEntryChanges['created']);
        $this->assertCount(2, $journalEntryChanges['deleted']);
    }

    #[Test]
    public function 同一キーで件数がずれる曖昧ケースはupdatedに寄せない(): void
    {
        $user = User::factory()->create();
        $fiscalYear = $this->createFiscalYear($user, '曖昧ケース事業体');
        $unit = $fiscalYear->businessUnit;
        $expense = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();
        $cash = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        $old = $this->registerTransaction($fiscalYear, $user, [], [
            [
                'sub_account_id' => $expense->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'gross_amount' => 550,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            ],
            [
                'sub_account_id' => $expense->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'gross_amount' => 550,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'gross_amount' => 1100,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ]);

        $new = $this->registerTransaction($fiscalYear, $user, [], [
            [
                'sub_account_id' => $expense->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'gross_amount' => 1100,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'gross_amount' => 1100,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ]);

        $journalEntryChanges = app(TransactionDiffer::class)->diff($old, $new, $user)->relatedChanges()['journal_entries'];

        $this->assertSame([], $journalEntryChanges['updated']);
        $this->assertCount(1, $journalEntryChanges['created']);
        $this->assertCount(2, $journalEntryChanges['deleted']);
    }

    #[Test]
    public function actorに参照権限がなければ拒否する(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $fiscalYear = $this->createFiscalYear($owner, '認可テスト事業体');
        $unit = $fiscalYear->businessUnit;
        $expense = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();
        $cash = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        $old = $this->registerTransaction($fiscalYear, $owner, [], [
            [
                'sub_account_id' => $expense->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'gross_amount' => 1100,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'gross_amount' => 1100,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ]);

        $new = $this->registerTransaction($fiscalYear, $owner, [], [
            [
                'sub_account_id' => $expense->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'gross_amount' => 2200,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'gross_amount' => 2200,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ]);

        $this->expectException(AuthorizationException::class);

        app(TransactionDiffer::class)->diff($old, $new, $otherUser);
    }

    #[Test]
    public function 異なる事業体の取引比較は拒否する(): void
    {
        $user = User::factory()->create();
        $firstFiscalYear = $this->createFiscalYear($user, '比較元事業体');
        $secondFiscalYear = $this->createFiscalYear($user, '比較先事業体');
        $firstUnit = $firstFiscalYear->businessUnit;
        $secondUnit = $secondFiscalYear->businessUnit;

        $old = $this->registerTransaction($firstFiscalYear, $user, [], [
            [
                'sub_account_id' => $firstUnit->getAccountByName('消耗品費')->subAccounts()->firstOrFail()->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'gross_amount' => 1100,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            ],
            [
                'sub_account_id' => $firstUnit->getAccountByName('現金')->subAccounts()->firstOrFail()->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'gross_amount' => 1100,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ]);

        $new = $this->registerTransaction($secondFiscalYear, $user, [], [
            [
                'sub_account_id' => $secondUnit->getAccountByName('消耗品費')->subAccounts()->firstOrFail()->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'gross_amount' => 1100,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            ],
            [
                'sub_account_id' => $secondUnit->getAccountByName('現金')->subAccounts()->firstOrFail()->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'gross_amount' => 1100,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(TransactionDiffer::class)->diff($old, $new, $user);
    }

    private function createFiscalYear(User $user, string $unitName): FiscalYear
    {
        $unit = $user->createBusinessUnitWithDefaults(['name' => $unitName]);

        return $unit->createFiscalYear(2025, $user);
    }

    /**
     * @param  array<string, mixed>  $transactionOverrides
     * @param  array<int, array<string, mixed>>  $journalEntries
     */
    private function registerTransaction(FiscalYear $fiscalYear, User $actor, array $transactionOverrides, array $journalEntries): Transaction
    {
        return app(TransactionRegistrar::class)->register(
            $fiscalYear,
            array_merge([
                'date' => '2025-04-01',
                'description' => '差分テスト',
                'created_by' => $actor->id,
            ], $transactionOverrides),
            $journalEntries,
            $actor,
        );
    }
}
