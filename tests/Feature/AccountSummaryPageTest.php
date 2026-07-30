<?php

namespace Tests\Feature;

use App\Livewire\Pages\AccountSummaryIndex;
use App\Models\Account;
use App\Models\User;
use App\Services\TransactionRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AccountSummaryPageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 勘定科目ごとの集計ページで補助科目のまとまりを確認できる(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '集計テスト事業']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);
        $unit->refresh();

        $cash = $unit->getSubAccountByName('現金', '現金');
        $bankAccount = $unit->getAccountByName('その他の預金');
        $bankMain = $bankAccount->subAccounts()->firstOrFail();
        $bankA = $bankAccount->createSubAccount(['name' => '三井住友銀行'], $user);
        $bankB = $bankAccount->createSubAccount(['name' => '楽天銀行'], $user);
        $loan = $unit->getSubAccountByName('借入金', '借入金');
        $capital = $unit->getSubAccountByName('元入金', '元入金');
        $revenue = $unit->getSubAccountByName('売上高', '売上高');

        $expenseAccount = $unit->accounts()->create([
            'name' => '広告宣伝費',
            'type' => Account::TYPE_EXPENSE,
        ]);
        $advertising = $expenseAccount->createSubAccount(['name' => 'Web広告'], $user);

        $registrar = new TransactionRegistrar;

        $registrar->register($fiscalYear, [
            'date' => '2025-01-10',
            'description' => '売上入金',
        ], [
            [
                'sub_account_id' => $bankA->id,
                'type' => 'debit',
                'net_amount' => 120000,
            ],
            [
                'sub_account_id' => $revenue->id,
                'type' => 'credit',
                'net_amount' => 120000,
            ],
        ], $user);

        $registrar->register($fiscalYear, [
            'date' => '2025-01-12',
            'description' => '運転資金借入',
        ], [
            [
                'sub_account_id' => $cash->id,
                'type' => 'debit',
                'net_amount' => 50000,
            ],
            [
                'sub_account_id' => $loan->id,
                'type' => 'credit',
                'net_amount' => 50000,
            ],
        ], $user);

        $registrar->register($fiscalYear, [
            'date' => '2025-01-15',
            'description' => '元入金投入',
        ], [
            [
                'sub_account_id' => $bankB->id,
                'type' => 'debit',
                'net_amount' => 70000,
            ],
            [
                'sub_account_id' => $capital->id,
                'type' => 'credit',
                'net_amount' => 70000,
            ],
        ], $user);

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
        ], $user);

        $response = $this->actingAs($user)->get(route('accounts.summary'));

        $response->assertOk();
        $response->assertSeeLivewire(AccountSummaryIndex::class);
        $response->assertSee('資産');
        $response->assertSee('負債');
        $response->assertSee('収益');
        $response->assertSee('純資産');
        $response->assertSee('費用');
        $response->assertSee('その他の預金');
        $response->assertSee('三井住友銀行');
        $response->assertSee('楽天銀行');
        $response->assertSee('Web広告');
        $response->assertDontSee('広告宣伝費');
        $response->assertSee('120,000');
        $response->assertSee('50,000');
        $response->assertSee('70,000');
        $response->assertSee('15,000');
    }
}
