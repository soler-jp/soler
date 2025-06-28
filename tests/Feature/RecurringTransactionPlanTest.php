<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use App\Models\RecurringTransactionPlan;
use Illuminate\Validation\ValidationException;

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
        $this->expectException(ValidationException::class);

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

    // バリデーションテスト
    #[Test]
    public function 必須項目が全てあればバリデーションに成功する()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業']);
        $debit = $unit->accounts()->where('name', '水道光熱費')->first();
        $credit = $unit->accounts()->where('name', 'その他の預金')->first();

        $validated = RecurringTransactionPlan::validate([
            'name' => '水道代',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'is_income' => false,
            'debit_account_id' => $debit->id,
            'credit_account_id' => $credit->id,
            'amount' => 3000,
            'business_unit_id' => $unit->id,
        ]);

        $this->assertSame('水道代', $validated['name']);
    }

    // バリデーションテスト
    #[Test]
    public function 必須項目が欠けていればバリデーションエラーになる()
    {
        $this->expectException(ValidationException::class);

        RecurringTransactionPlan::validate([]);
    }

    // バリデーションテスト
    #[Test]
    public function intervalが不正な値ならエラーになる()
    {
        $this->expectException(ValidationException::class);

        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業']);
        $account = $unit->accounts()->where('name', 'その他の預金')->first();

        RecurringTransactionPlan::validate([
            'business_unit_id' => $unit->id,
            'name' => '誤った間隔',
            'interval' => 'weekly',
            'day_of_month' => 1,
            'is_income' => false,
            'debit_account_id' => $account->id,
            'credit_account_id' => $account->id,
            'amount' => 1000,
            'business_unit_id' => $unit->id,
        ]);
    }

    #[Test]
    public function 同じ事業単位でnameが重複するとバリデーションエラーになる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業']);
        $account = $unit->accounts()->where('name', 'その他の預金')->first();

        $unit->createRecurringTransactionPlan([
            'business_unit_id' => $unit->id,
            'name' => '重複チェック',
            'interval' => 'monthly',
            'day_of_month' => 1,
            'is_income' => false,
            'debit_account_id' => $account->id,
            'credit_account_id' => $account->id,
            'amount' => 1000,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('【重複チェック】はすでに使われているので使用できません');

        RecurringTransactionPlan::validate([
            'business_unit_id' => $unit->id,
            'name' => '重複チェック',
            'interval' => 'monthly',
            'day_of_month' => 1,
            'is_income' => false,
            'debit_account_id' => $account->id,
            'credit_account_id' => $account->id,
            'amount' => 1000,
        ]);
    }


    #[Test]
    public function 異なる事業単位で同じnameが重複してもバリデーションエラーにならない()
    {
        $user = User::factory()->create();
        $unit1 = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業1']);
        $unit2 = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業2']);
        $account1 = $unit1->accounts()->where('name', 'その他の預金')->first();
        $account2 = $unit2->accounts()->where('name', 'その他の預金')->first();

        $plan1 = $unit1->createRecurringTransactionPlan([
            'business_unit_id' => $unit1->id,
            'name' => '重複チェック',
            'interval' => 'monthly',
            'day_of_month' => 1,
            'is_income' => false,
            'debit_account_id' => $account1->id,
            'credit_account_id' => $account1->id,
            'amount' => 1000,
        ]);
        $this->assertNotNull($plan1);

        // 異なる事業単位で同じnameを使用してもエラーにならないことを確認
        $plan2 = $unit2->createRecurringTransactionPlan([
            'business_unit_id' => $unit2->id,
            'name' => '重複チェック',
            'interval' => 'monthly',
            'day_of_month' => 1,
            'is_income' => false,
            'debit_account_id' => $account2->id,
            'credit_account_id' => $account2->id,
            'amount' => 1000,
        ]);

        $this->assertNotNull($plan2);
    }
}
