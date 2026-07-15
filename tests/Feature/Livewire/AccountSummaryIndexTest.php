<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Pages\AccountSummaryIndex;
use App\Models\Account;
use App\Models\User;
use App\Services\TransactionRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AccountSummaryIndexTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 行クリック用モーダルで元取引一覧を表示できる(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '集計テスト事業']);
        $fiscalYear = $unit->createFiscalYear(2025);
        $unit->refresh();

        $bankAccount = $unit->getAccountByName('その他の預金');
        $bankMain = $bankAccount->subAccounts()->firstOrFail();
        $revenue = $unit->getSubAccountByName('売上高', '売上高');

        $expenseAccount = $unit->accounts()->create([
            'name' => '広告宣伝費',
            'type' => Account::TYPE_EXPENSE,
        ]);
        $advertising = $expenseAccount->createSubAccount(['name' => 'Web広告']);

        $registrar = new TransactionRegistrar;

        $registrar->register($fiscalYear, [
            'date' => '2025-01-18',
            'description' => '広告出稿',
        ], [
            [
                'sub_account_id' => $advertising->id,
                'type' => 'debit',
                'net_amount' => 15000,
            ],
            [
                'sub_account_id' => $bankMain->id,
                'type' => 'credit',
                'net_amount' => 15000,
            ],
        ]);

        $registrar->register($fiscalYear, [
            'date' => '2025-02-03',
            'description' => '保険料返金',
        ], [
            [
                'sub_account_id' => $bankMain->id,
                'type' => 'debit',
                'net_amount' => 2000,
            ],
            [
                'sub_account_id' => $advertising->id,
                'type' => 'credit',
                'net_amount' => 2000,
            ],
        ]);

        $registrar->register($fiscalYear, [
            'date' => '2025-01-20',
            'description' => '売上入金',
        ], [
            [
                'sub_account_id' => $bankMain->id,
                'type' => 'debit',
                'net_amount' => 30000,
            ],
            [
                'sub_account_id' => $revenue->id,
                'type' => 'credit',
                'net_amount' => 30000,
            ],
        ]);

        $component = Livewire::actingAs($user)
            ->test(AccountSummaryIndex::class)
            ->call('openTransactionsModal', 'expense', $expenseAccount->id, $advertising->id, 'Web広告')
            ->assertSet('showTransactionsModal', true)
            ->assertSee('Web広告 の元帳')
            ->assertSee('広告出稿')
            ->assertSee('保険料返金')
            ->assertSee('その他の預金')
            ->assertSee('15,000')
            ->assertDontSee('売上入金');

        $transactions = $component->get('transactions');

        $this->assertSame(15000, $transactions[0]['amount']);
        $this->assertSame(15000, $transactions[0]['balance']);
        $this->assertSame(-2000, $transactions[1]['amount']);
        $this->assertSame(13000, $transactions[1]['balance']);
        $this->assertNotSame($transactions[0]['month_stripe'], $transactions[1]['month_stripe']);
    }
}
