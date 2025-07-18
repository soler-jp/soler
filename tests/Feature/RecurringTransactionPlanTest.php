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

        $debit = $unit->subAccounts()->whereHas('account', function ($q) {
            $q->where('name', '水道光熱費');
        })->first();

        $credit = $unit->subAccounts()->whereHas('account', function ($q) {
            $q->where('name', 'その他の預金');
        })->first();

        $data = [
            'business_unit_id' => $unit->id,
            'name' => '水道代',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'is_income' => false,
            'debit_sub_account_id' => $debit->id,
            'credit_sub_account_id' => $credit->id,
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

        $subAccount = $unit->subAccounts()
            ->whereHas('account', fn($q) => $q->where('name', 'その他の預金'))
            ->firstOrFail();

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '定期収入',
            'interval' => 'monthly',
            'day_of_month' => 5,
            'is_income' => true,
            'debit_sub_account_id' => $subAccount->id,
            'credit_sub_account_id' => $subAccount->id,
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

        $subAccount = $unit->subAccounts()
            ->whereHas('account', fn($q) => $q->where('name', 'その他の預金'))
            ->firstOrFail();

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '家賃',
            'interval' => 'monthly',
            'day_of_month' => 1,
            'is_income' => false,
            'debit_sub_account_id' => $subAccount->id,
            'credit_sub_account_id' => $subAccount->id,
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

        $debit = $unit->subAccounts()
            ->whereHas('account', fn($q) => $q->where('name', '水道光熱費'))
            ->firstOrFail();

        $credit = $unit->subAccounts()
            ->whereHas('account', fn($q) => $q->where('name', 'その他の預金'))
            ->firstOrFail();

        $validated = \App\Models\RecurringTransactionPlan::validate([
            'name' => '水道代',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'is_income' => false,
            'debit_sub_account_id' => $debit->id,
            'credit_sub_account_id' => $credit->id,
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
        $subAccount = $unit->subAccounts()
            ->whereHas('account', fn($q) => $q->where('name', 'その他の預金'))
            ->firstOrFail();

        RecurringTransactionPlan::validate([
            'business_unit_id' => $unit->id,
            'name' => '誤った間隔',
            'interval' => 'weekly',
            'day_of_month' => 1,
            'is_income' => false,
            'debit_sub_account_id' => $subAccount->id,
            'credit_sub_account_id' => $subAccount->id,
            'amount' => 1000,
            'business_unit_id' => $unit->id,
        ]);
    }

    #[Test]
    public function 同じ事業単位でnameが重複するとバリデーションエラーになる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業']);

        $subAccount = $unit->subAccounts()
            ->whereHas('account', fn($q) => $q->where('name', 'その他の預金'))
            ->firstOrFail();

        $unit->createRecurringTransactionPlan([
            'business_unit_id' => $unit->id,
            'name' => '重複チェック',
            'interval' => 'monthly',
            'day_of_month' => 1,
            'is_income' => false,
            'debit_sub_account_id' => $subAccount->id,
            'credit_sub_account_id' => $subAccount->id,
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
            'debit_sub_account_id' => $subAccount->id,
            'credit_sub_account_id' => $subAccount->id,
            'amount' => 1000,
        ]);
    }

    #[Test]
    public function 異なる事業単位で同じnameが重複してもバリデーションエラーにならない()
    {
        $user = User::factory()->create();
        $unit1 = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業1']);
        $unit2 = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業2']);

        $sub1 = $unit1->subAccounts()
            ->whereHas('account', fn($q) => $q->where('name', 'その他の預金'))
            ->firstOrFail();

        $sub2 = $unit2->subAccounts()
            ->whereHas('account', fn($q) => $q->where('name', 'その他の預金'))
            ->firstOrFail();

        $plan1 = $unit1->createRecurringTransactionPlan([
            'business_unit_id' => $unit1->id,
            'name' => '重複チェック',
            'interval' => 'monthly',
            'day_of_month' => 1,
            'is_income' => false,
            'debit_sub_account_id' => $sub1->id,
            'credit_sub_account_id' => $sub1->id,
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
            'debit_sub_account_id' => $sub2->id,
            'credit_sub_account_id' => $sub2->id,
            'amount' => 1000,
        ]);
        $this->assertNotNull($plan2);
    }


    #[Test]
    public function tax付きのmonthlyプランで1年分の予定取引が生成される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業'])->refresh();
        $fiscalYear = $unit->createFiscalYear(2025)->refresh();

        $debitSub = $unit->subAccounts()->whereHas('account', fn($q) => $q->where('name', '水道光熱費'))->first();
        $creditSub = $unit->subAccounts()->whereHas('account', fn($q) => $q->where('name', 'その他の預金'))->first();

        $plan = $unit->createRecurringTransactionPlan([
            'business_unit_id' => $unit->id,
            'name' => '税込月次プラン',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'is_income' => false,
            'debit_sub_account_id' => $debitSub->id,
            'credit_sub_account_id' => $creditSub->id,
            'amount' => 10000,
            'tax_amount' => 1000,
            'tax_type' => 'taxable_purchases_10',
        ]);

        $transactions = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear);

        $this->assertCount(12, $transactions);
        $this->assertTrue($transactions->every(fn($t) => $t->is_planned));

        foreach ($transactions as $transaction) {
            $entries = $transaction->journalEntries;

            $this->assertEquals(2, $entries->count());
            $this->assertTrue($entries->contains(fn($e) => $e->tax_amount === 1000));
            $this->assertTrue($entries->contains(fn($e) => $e->tax_type === 'taxable_purchases_10'));
        }
    }

    #[Test]
    public function day_of_monthが月末より大きい場合はその月の末日に調整される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '末日テスト事業']);
        $fiscalYear = $unit->createFiscalYear(2025);

        $sub = $unit->subAccounts()->whereHas('account', fn($q) => $q->where('name', '水道光熱費'))->first();

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '末日補正プラン',
            'interval' => 'monthly',
            'day_of_month' => 31,
            'is_income' => false,
            'debit_sub_account_id' => $sub->id,
            'credit_sub_account_id' => $sub->id,
            'amount' => 10000,
            'tax_amount' => 1000,
        ]);

        $transactions = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear);

        $this->assertCount(12, $transactions);

        $dates = $transactions->pluck('date')->sort()->values();

        $this->assertEquals('2025-02-28', $dates[1]->toDateString());
        $this->assertEquals('2025-03-31', $dates[2]->toDateString());
        $this->assertEquals('2025-04-30', $dates[3]->toDateString());
    }

    #[Test]
    public function bimonthlyプランでは年に6件の予定取引が生成される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '隔月プラン事業']);
        $fiscalYear = $unit->createFiscalYear(2025);

        $sub = $unit->subAccounts()->whereHas('account', fn($q) => $q->where('name', '水道光熱費'))->first();

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '隔月プラン',
            'interval' => 'bimonthly',
            'day_of_month' => 15,
            'is_income' => false,
            'debit_sub_account_id' => $sub->id,
            'credit_sub_account_id' => $sub->id,
            'amount' => 5000,
            'tax_amount' => 500,
        ]);

        $transactions = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear);

        $this->assertCount(6, $transactions);

        $dates = $transactions->pluck('date')->sort()->values()->map(fn($d) => $d->toDateString());

        $this->assertEquals([
            '2025-01-15',
            '2025-03-15',
            '2025-05-15',
            '2025-07-15',
            '2025-09-15',
            '2025-11-15',
        ], $dates->toArray());
    }

    #[Test]
    public function is_activeがfalseのプランでは予定取引が作成されない()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '非アクティブ事業']);
        $fiscalYear = $unit->createFiscalYear(2025);
        $sub = $unit->subAccounts()->whereHas('account', fn($q) => $q->where('name', '水道光熱費'))->first();

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '非アクティブプラン',
            'interval' => 'monthly',
            'day_of_month' => 1,
            'is_income' => false,
            'debit_sub_account_id' => $sub->id,
            'credit_sub_account_id' => $sub->id,
            'amount' => 3000,
            'tax_amount' => 300,
            'is_active' => false,
        ]);

        $transactions = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear);

        $this->assertCount(0, $transactions);
    }

    #[Test]
    public function 生成された取引にはrecurring_transaction_plan_idが設定される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'リンクテスト事業']);
        $fiscalYear = $unit->createFiscalYear(2025);
        $sub = $unit->subAccounts()->whereHas('account', fn($q) => $q->where('name', '水道光熱費'))->first();

        $plan = $unit->createRecurringTransactionPlan([
            'name' => 'リンク付きプラン',
            'interval' => 'monthly',
            'day_of_month' => 1,
            'is_income' => false,
            'debit_sub_account_id' => $sub->id,
            'credit_sub_account_id' => $sub->id,
            'amount' => 8000,
            'tax_amount' => 800,
        ]);

        $transactions = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear);

        $this->assertCount(12, $transactions);

        foreach ($transactions as $transaction) {
            $this->assertTrue($transaction->is_planned);
            $this->assertEquals($plan->id, $transaction->recurring_transaction_plan_id);
        }
    }

    #[Test]
    public function 同じ日付の予定取引が既に存在する場合は作成されない()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '重複防止テスト事業']);
        $fiscalYear = $unit->createFiscalYear(2025);
        $sub = $unit->subAccounts()->whereHas('account', fn($q) => $q->where('name', '水道光熱費'))->first();

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '重複チェックプラン',
            'interval' => 'monthly',
            'day_of_month' => 1,
            'is_income' => false,
            'debit_sub_account_id' => $sub->id,
            'credit_sub_account_id' => $sub->id,
            'amount' => 5000,
            'tax_amount' => 500,
        ]);

        // 初回：すべて作成される（12件）
        $firstRun = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear);
        $this->assertCount(12, $firstRun);

        // 2回目：すでに存在しているためスキップ（0件作成）
        $secondRun = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear);
        $this->assertCount(0, $secondRun);

        // DB上も12件のままで増えていないこと
        $this->assertEquals(
            12,
            $plan->transactions()
                ->whereBetween('date', [$fiscalYear->start_date, $fiscalYear->end_date])
                ->where('is_planned', true)
                ->count()
        );
    }

    #[Test]
    public function 他のプランが同じ日に作成していても生成される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '同日許可テスト']);
        $fiscalYear = $unit->createFiscalYear(2025);
        $sub = $unit->subAccounts()->whereHas('account', fn($q) => $q->where('name', '水道光熱費'))->first();

        $plan1 = $unit->createRecurringTransactionPlan([
            'name' => 'プランA',
            'interval' => 'monthly',
            'day_of_month' => 1,
            'is_income' => false,
            'debit_sub_account_id' => $sub->id,
            'credit_sub_account_id' => $sub->id,
            'amount' => 3000,
            'tax_amount' => 300,
        ]);

        $plan2 = $unit->createRecurringTransactionPlan([
            'name' => 'プランB',
            'interval' => 'monthly',
            'day_of_month' => 1, // 同じ日付
            'is_income' => false,
            'debit_sub_account_id' => $sub->id,
            'credit_sub_account_id' => $sub->id,
            'amount' => 7000,
            'tax_amount' => 700,
        ]);

        // 両方のプランで生成
        $transactions1 = $unit->generatePlannedTransactionsForPlan($plan1, $fiscalYear);
        $transactions2 = $unit->generatePlannedTransactionsForPlan($plan2, $fiscalYear);

        // 両者とも12件ずつ生成されていること
        $this->assertCount(12, $transactions1);
        $this->assertCount(12, $transactions2);

        // 各取引に適切な plan_id が設定されていること
        $this->assertTrue($transactions1->every(fn($t) => $t->recurring_transaction_plan_id === $plan1->id));
        $this->assertTrue($transactions2->every(fn($t) => $t->recurring_transaction_plan_id === $plan2->id));

        // 合計24件生成されていること
        $this->assertEquals(
            24,
            $fiscalYear->transactions()
                ->where('is_planned', true)
                ->whereBetween('date', [$fiscalYear->start_date, $fiscalYear->end_date])
                ->count()
        );
    }
}
