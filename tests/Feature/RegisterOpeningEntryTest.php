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
    public function 現金と定期預金で期首仕訳を登録できる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear = $unit->createFiscalYear(2025);

        $txn = $fiscalYear->registerOpeningEntry([
            [
                'account_name' => '現金',
                'amount' => 100000,
            ],
            [
                'account_name' => '定期預金',
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

        $this->assertEquals('equity', $credits->first()->account->type);
        $this->assertEquals('元入金', $credits->first()->account->name);
    }


    #[Test]
    public function SubAccountを指定して期首仕訳を登録できる()
    {
        $user = User::factory()->create();

        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
        ]);

        $fiscalYear = $unit->createFiscalYear(2025);

        $transaction = $fiscalYear->registerOpeningEntry([
            [
                'account_name' => '現金',
                'amount' => 100000,
                'sub_account_name' => '事務所レジ',
            ],
            [
                'account_name' => '定期預金',
                'amount' => 200000,
                'sub_account_name' => '地方信用金庫',
            ],
        ]);

        $this->assertEquals(3, $transaction->journalEntries->count());

        $subAccounts = SubAccount::all();
        $this->assertCount(2, $subAccounts);

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
            'name' => 'レジ現金',
        ]);

        $existing = SubAccount::create([
            'account_id' => $cashAccount->id,
            'name' => 'レジ現金',
        ]);

        $transaction = $fiscalYear->registerOpeningEntry([
            [
                'account_name' => '現金',
                'amount' => 100000,
                'sub_account_name' => 'レジ現金',
            ],
        ]);

        $usedSubAccountId = $transaction->journalEntries()->first()->sub_account_id;
        $this->assertEquals($existing->id, $usedSubAccountId);

        $count = SubAccount::where('account_id', $cashAccount->id)
            ->where('name', 'レジ現金')
            ->count();

        $this->assertEquals(1, $count); // 「レジ現金」が重複していない
    }
}
