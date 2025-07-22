<?php

namespace Tests\Feature\Livewire\Recurring;

use App\Livewire\Recurring\TabList;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use App\Models\Transaction;

class TabListTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 固定費一覧が表示される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業']);
        $fiscalYear = $unit->createFiscalYear(2025);

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
            ->assertSee('サーバー代')
            ->assertSee('ソフトウェア使用料');
    }

    #[Test]
    public function 確定できる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業']);
        $fiscalYear = $unit->createFiscalYear(2025);

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

        $transactions = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear);

        $tx = $transactions->first();
        $orgDebit = $tx->journalEntries->where('type', 'debit')->first();
        $orgCredit = $tx->journalEntries->where('type', 'credit')->first();

        $this->assertTrue($tx->is_planned);
        $this->assertEquals(1100, $orgDebit->amount);
        $this->assertEquals(0, $orgDebit->tax_amount);
        $this->assertEquals($orgDebit->sub_account_id, $debitSubAccount->id);
        $this->assertEquals(1100, $orgCredit->amount);
        $this->assertEquals(0, $orgCredit->tax_amount);
        $this->assertEquals($orgCredit->sub_account_id, $creditSubAccount->id);


        $newCreditSubAccount = $unit->getAccountByName('事業主借')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(TabList::class)
            ->set('inputs', [
                $tx->id => [
                    'date' => '2025-12-10',
                    'amount' => 1400, // 税込み金額
                    'credit_sub_account_id' => $newCreditSubAccount->id,
                ],
            ])
            ->call('confirm', $tx->id);

        $tx->refresh();

        $this->assertDatabaseHas('transactions', [
            'date' => '2025-12-10',
            'id' => $tx->id,
            'is_planned' => false,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $tx->id,
            'type' => 'debit',
            'amount' => 1400,
            'tax_amount' => 0,
            'sub_account_id' => $debitSubAccount->id,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $tx->id,
            'type' => 'credit',
            'amount' => 1400,
            'tax_amount' => 0,
            'sub_account_id' => $newCreditSubAccount->id,
        ]);
    }
}
