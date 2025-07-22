<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use App\Models\User;
use App\Models\SubAccount;

class RegisterOpeningEntryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 何も登録しない期首仕訳ができる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear = $unit->createFiscalYear(2025);

        $txn = $fiscalYear->registerOpeningEntry([]);

        $this->assertEquals(null, $txn);
        $this->assertCount(0, $fiscalYear->journalEntries);
    }

    #[Test]
    public function 現金と定期預金で期首仕訳を登録できる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear = $unit->createFiscalYear(2025);

        $txn = $fiscalYear->registerOpeningEntry([
            [
                'account_name' => '現金',
                'sub_account_name' => '現金',
                'amount' => 100000,
            ],
            [
                'account_name' => 'その他の預金',
                'sub_account_name' => '定期預金',
                'amount' => 200000,
            ],
        ]);

        $this->assertEquals('期首残高設定', $txn->description);
        $this->assertTrue($txn->is_opening_entry);
        $this->assertEquals($fiscalYear->id, $txn->fiscal_year_id);
        $this->assertCount(3, $txn->journalEntries); // 2 debit + 1 credit

        $debits = $txn->journalEntries->where('type', 'debit');
        $credits = $txn->journalEntries->where('type', 'credit');

        $this->assertEquals(2, $debits->count());
        $this->assertEquals(1, $credits->count());

        $this->assertEquals(300000, $debits->sum('amount'));
        $this->assertEquals(300000, $credits->sum('amount'));

        $this->assertEquals('equity', $credits->first()->subAccount->account->type);
        $this->assertEquals('元入金', $credits->first()->subAccount->account->name);
    }


    #[Test]
    public function SubAccountを指定して期首仕訳を登録できる()
    {
        $user = User::factory()->create();

        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
        ]);

        $orgSubAccounts = $unit->subAccounts->all();
        $this->assertCount(51, $orgSubAccounts, '初期状態ではSubAccountがデフォルトの50');

        $this->assertDatabaseMissing('sub_accounts', [
            'name' => '事務所レジ',
        ]);

        $this->assertDatabaseMissing('sub_accounts', [
            'name' => '地方信用金庫',
        ]);

        $fiscalYear = $unit->createFiscalYear(2025);

        $transaction = $fiscalYear->registerOpeningEntry([
            [
                'amount' => 100000,
                'sub_account_name' => '事務所レジ',
                'account_name' => '現金',
            ],
            [
                'amount' => 200000,
                'sub_account_name' => '地方信用金庫',
                'account_name' => 'その他の預金',
            ],
        ]);


        $this->assertEquals(3, $transaction->journalEntries->count());

        $unit->refresh();
        $subAccounts = $unit->subAccounts->all();
        $this->assertCount(53, $subAccounts, 'SubAccountが2つ追加されていることを確認');


        $this->assertDatabaseHas('sub_accounts', [
            'name' => '事務所レジ',
        ]);

        $this->assertDatabaseHas('sub_accounts', [
            'name' => '地方信用金庫',
        ]);

        $subAccountNames = $transaction->journalEntries
            ->filter(fn($e) => $e->subAccount)
            ->pluck('subAccount.name')
            ->all();

        $this->assertContains('事務所レジ', $subAccountNames);
        $this->assertContains('地方信用金庫', $subAccountNames);
    }


    #[Test]
    public function 既存のSubAccountがある場合は再利用される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear = $unit->createFiscalYear(2025);

        $cashAccount = $unit->accounts()->where('name', '現金')->first();

        // レジ現金がないことを確認
        $this->assertDatabaseMissing('sub_accounts', [
            'account_id' => $cashAccount->id,
            'name' => '金庫',
        ]);

        $existing = $cashAccount->subAccounts()->create([
            'name' => '金庫',
        ]);


        $transaction = $fiscalYear->registerOpeningEntry([
            [
                'account_name' => '現金',
                'amount' => 100000,
                'sub_account_name' => '金庫',
            ],
        ]);

        $usedSubAccountId = $transaction->journalEntries()->first()->sub_account_id;
        $this->assertEquals($existing->id, $usedSubAccountId);

        $count = SubAccount::where('account_id', $cashAccount->id)
            ->where('name', '金庫')
            ->count();

        $this->assertEquals(1, $count); // 「金庫」が重複していない
    }
}
