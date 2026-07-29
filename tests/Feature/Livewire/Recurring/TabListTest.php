<?php

namespace Tests\Feature\Livewire\Recurring;

use App\Livewire\Recurring\TabList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TabListTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 固定費一覧が表示される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $unit->createRecurringTransactionPlan([
            'name' => 'サーバー代',
            'amount' => 1100,
            'interval' => 'monthly',
            'day_of_month' => 10,
            'is_income' => false,
            'debit_sub_account_id' => $unit->subAccounts()->first()->id,
            'credit_sub_account_id' => $unit->subAccounts()->first()->id,
        ]);

        $unit->createRecurringTransactionPlan([
            'name' => 'ソフトウェア使用料',
            'amount' => 2200,
            'interval' => 'bimonthly',
            'day_of_month' => 15,
            'start_month' => 1,
            'is_income' => false,
            'debit_sub_account_id' => $unit->subAccounts()->first()->id,
            'credit_sub_account_id' => $unit->subAccounts()->first()->id,
        ]);

        Livewire::actingAs($user)
            ->test(TabList::class)
            ->assertSee('支払額')
            ->assertSee('事業割合')
            ->assertSee('経費額')
            ->assertSee('支払元')
            ->assertSee('サーバー代')
            ->assertSee('ソフトウェア使用料');
    }

    #[Test]
    public function 確定できる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $debitSubAccount = $unit->subAccounts()->first();
        $creditSubAccount = $unit->subAccounts()->first(); // 同一でもOK（ここでは簡略化）

        $plan = $unit->createRecurringTransactionPlan([
            'name' => 'サーバー代',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'amount' => 1100,
            'tax_amount' => 0,
            'is_income' => false,
            'debit_sub_account_id' => $debitSubAccount->id,
            'credit_sub_account_id' => $creditSubAccount->id,
        ]);

        $transactions = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user);

        $tx = $transactions->first();
        $orgDebit = $tx->journalEntries->where('type', 'debit')->first();
        $orgCredit = $tx->journalEntries->where('type', 'credit')->first();

        $this->assertTrue($tx->is_planned);
        $this->assertEquals(1100, $orgDebit->net_amount);
        $this->assertEquals(0, $orgDebit->tax_amount);
        $this->assertEquals($orgDebit->sub_account_id, $debitSubAccount->id);
        $this->assertEquals(1100, $orgCredit->net_amount);
        $this->assertEquals(0, $orgCredit->tax_amount);
        $this->assertEquals($orgCredit->sub_account_id, $creditSubAccount->id);

        $newCreditSubAccount = $unit->getAccountByName('事業主借')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(TabList::class)
            ->set("inputs.{$tx->id}.date", '2025-12-10')
            ->set("inputs.{$tx->id}.amount", 1400)
            ->set("inputs.{$tx->id}.credit_sub_account_id", $newCreditSubAccount->id)
            ->call('confirm', $tx->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('transactions', [
            'id' => $tx->id,
            'is_planned' => false,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $tx->id,
            'type' => 'debit',
            'net_amount' => 1400,
            'tax_amount' => 0,
            'sub_account_id' => $debitSubAccount->id,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $tx->id,
            'type' => 'credit',
            'net_amount' => 1400,
            'tax_amount' => 0,
            'sub_account_id' => $newCreditSubAccount->id,
        ]);

        $tx->refresh();

        $this->assertSame('2025-12-10', $tx->date->toDateString());
    }

    #[Test]
    public function 事業割合と経費額が表示される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $debitSubAccount = $unit->getAccountByName('消耗品費')->subAccounts()->first();
        $creditSubAccount = $unit->getAccountByName('現金')->subAccounts()->first();

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '按分あり固定費',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'amount' => 5678,
            'tax_amount' => 0,
            'business_ratio' => 60,
            'is_income' => false,
            'debit_sub_account_id' => $debitSubAccount->id,
            'credit_sub_account_id' => $creditSubAccount->id,
        ]);

        $tx = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user)->firstOrFail();

        Livewire::actingAs($user)
            ->test(TabList::class)
            ->assertSeeHtml('wire:model.defer="inputs.'.$tx->id.'.business_ratio"')
            ->assertSee('3,406');
    }

    #[Test]
    public function 他ユーザーの予定取引は確定できない()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $unit = $user->createBusinessUnitWithDefaults(['name' => '自分の事業体']);
        $unit->createFiscalYear(2025, $user);

        $otherUnit = $otherUser->createBusinessUnitWithDefaults(['name' => '他人の事業体']);
        $otherFiscalYear = $otherUnit->createFiscalYear(2025, $otherUser);

        $otherDebit = $otherUnit->subAccounts()->first();
        $otherCredit = $otherUnit->getAccountByName('現金')->subAccounts()->first();

        $plan = $otherUnit->createRecurringTransactionPlan([
            'name' => '他人の固定費',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'amount' => 1100,
            'tax_amount' => 0,
            'is_income' => false,
            'debit_sub_account_id' => $otherDebit->id,
            'credit_sub_account_id' => $otherCredit->id,
        ]);

        $otherTx = $otherUnit->generatePlannedTransactionsForPlan($plan, $otherFiscalYear, $otherUser)->first();
        $ownCredit = $unit->getAccountByName('事業主借')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(TabList::class)
            ->set('inputs', [
                $otherTx->id => [
                    'date' => '2025-12-10',
                    'amount' => 1400,
                    'credit_sub_account_id' => $ownCredit->id,
                ],
            ])
            ->call('confirm', $otherTx->id);

        $this->assertDatabaseHas('transactions', [
            'id' => $otherTx->id,
            'is_planned' => true,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $otherTx->id,
            'type' => 'credit',
            'net_amount' => 1100,
            'sub_account_id' => $otherCredit->id,
        ]);
    }

    #[Test]
    public function 他ユーザー事業体の貸方補助科目では確定できない()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $unit = $user->createBusinessUnitWithDefaults(['name' => '自分の事業体']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $otherUnit = $otherUser->createBusinessUnitWithDefaults(['name' => '他人の事業体']);
        $otherUnit->createFiscalYear(2025, $otherUser);

        $debitSubAccount = $unit->subAccounts()->first();
        $creditSubAccount = $unit->getAccountByName('現金')->subAccounts()->first();
        $foreignCredit = $otherUnit->getAccountByName('現金')->subAccounts()->first();

        $plan = $unit->createRecurringTransactionPlan([
            'name' => 'サーバー代',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'amount' => 1100,
            'tax_amount' => 0,
            'is_income' => false,
            'debit_sub_account_id' => $debitSubAccount->id,
            'credit_sub_account_id' => $creditSubAccount->id,
        ]);

        $tx = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user)->first();

        Livewire::actingAs($user)
            ->test(TabList::class)
            ->set('inputs', [
                $tx->id => [
                    'date' => '2025-12-10',
                    'amount' => 1400,
                    'credit_sub_account_id' => $foreignCredit->id,
                ],
            ])
            ->call('confirm', $tx->id)
            ->assertHasErrors(['credit_sub_account_id']);

        $this->assertDatabaseHas('transactions', [
            'id' => $tx->id,
            'is_planned' => true,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $tx->id,
            'type' => 'credit',
            'net_amount' => 1100,
            'sub_account_id' => $creditSubAccount->id,
        ]);
    }
}
