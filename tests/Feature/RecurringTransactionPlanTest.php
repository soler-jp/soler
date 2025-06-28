<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecurringTransactionPlanTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function データが正しく保存される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業',
        ]);

        $debit = $unit->accounts()->where('name', '水道光熱費')->first();
        $credit = $unit->accounts()->where('name', 'その他の預金')->first();

        $data = [
            'name' => '水道代',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'is_income' => false,
            'debit_account_id' => $debit->id,
            'credit_account_id' => $credit->id,
            'amount' => 4000,
            'tax_amount' => 400,
            'tax_type' => 'taxable_10',
        ];

        $plan = $unit->createRecurringTransactionPlan($data);

        $this->assertDatabaseHas('recurring_transaction_plans', $data);
    }

    #[Test]
    public function 必須項目がなければ保存に失敗する()
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業',
        ]);

        $unit->createRecurringTransactionPlan([]);
    }

    #[Test]
    public function is_incomeとis_activeはbooleanとしてキャストされる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業',
        ]);

        $account = $unit->accounts()->where('name', 'その他の預金')->first();

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '定期収入',
            'interval' => 'monthly',
            'day_of_month' => 5,
            'is_income' => true,
            'debit_account_id' => $account->id,
            'credit_account_id' => $account->id,
            'amount' => 30000,
        ]);

        $this->assertTrue($plan->is_income);
        $this->assertTrue($plan->is_active);
    }

    #[Test]
    public function businessUnitとのリレーションが正しく動作する()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業',
        ]);

        $account = $unit->accounts()->where('name', 'その他の預金')->first();

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '家賃',
            'interval' => 'monthly',
            'day_of_month' => 1,
            'is_income' => false,
            'debit_account_id' => $account->id,
            'credit_account_id' => $account->id,
            'amount' => 50000,
        ]);

        $this->assertEquals($unit->id, $plan->businessUnit->id);
    }
}
