<?php

namespace Tests\Feature;

use App\Models\Counterparty;
use App\Models\JournalEntry;
use App\Models\RecurringTransactionPlan;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
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
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $debit->id,
            'credit_sub_account_id' => $credit->id,
            'amount' => 4000,
            'tax_amount' => 400,
            'tax_type' => 'taxable_10',
        ];

        $plan = $unit->createRecurringTransactionPlan($data, $user);

        $this->assertDatabaseHas('recurring_transaction_plans', $data);
        $this->assertSame(4400, $plan->gross_amount);
    }

    #[Test]
    public function 確定時に事業割合を上書きできる()
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業',
        ]);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $debit = $unit->subAccounts()->whereHas('account', function ($q) {
            $q->where('name', '消耗品費');
        })->firstOrFail();

        $credit = $unit->subAccounts()->whereHas('account', function ($q) {
            $q->where('name', '現金');
        })->firstOrFail();

        $plan = $unit->createRecurringTransactionPlan([
            'business_unit_id' => $unit->id,
            'name' => '按分あり固定費',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $debit->id,
            'credit_sub_account_id' => $credit->id,
            'amount' => 5678,
            'tax_amount' => 0,
            'business_ratio' => 60,
        ], $user);

        $transaction = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user)->firstOrFail();

        $confirmed = $plan->confirmTransaction($transaction->id, [
            'date' => '2025-12-10',
            'amount' => 1400,
            'business_ratio' => 80,
            'credit_sub_account_id' => $unit->getAccountByName('事業主借')->subAccounts()->firstOrFail()->id,
        ], $user);

        $this->assertNotNull($confirmed);
        $this->assertFalse($confirmed->is_planned);
        $this->assertCount(3, $confirmed->journalEntries);

        $businessDebit = $confirmed->journalEntries
            ->where('type', JournalEntry::TYPE_DEBIT)
            ->firstWhere('business_ratio', 80);
        $householdDebit = $confirmed->journalEntries
            ->where('type', JournalEntry::TYPE_DEBIT)
            ->firstWhere('business_ratio', null);
        $creditEntry = $confirmed->journalEntries->where('type', JournalEntry::TYPE_CREDIT)->first();

        $this->assertSame(1120, $businessDebit?->net_amount);
        $this->assertSame(280, $householdDebit?->net_amount);
        $this->assertSame(1400, $creditEntry?->net_amount);
    }

    #[Test]
    public function 必須項目がなければ保存に失敗する()
    {
        $this->expectException(ValidationException::class);

        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業',
        ]);

        $unit->createRecurringTransactionPlan([], $user);
    }

    #[Test]
    public function typeとis_activeが適切にキャストされる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業',
        ]);

        $subAccount = $unit->subAccounts()
            ->whereHas('account', fn ($q) => $q->where('name', 'その他の預金'))
            ->firstOrFail();

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '定期収入',
            'interval' => 'monthly',
            'day_of_month' => 5,
            'type' => RecurringTransactionPlan::TYPE_INCOME,
            'debit_sub_account_id' => $subAccount->id,
            'credit_sub_account_id' => $subAccount->id,
            'amount' => 30000,
        ], $user);

        $this->assertSame(RecurringTransactionPlan::TYPE_INCOME, $plan->type);
        $this->assertTrue($plan->is_active);
    }

    #[Test]
    public function 課税収入プランは貸方に売上税区分を持つ予定取引を生成できる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '定期収入テスト']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $depositSubAccount = $unit->getSubAccountByName('その他の預金', 'その他の預金');
        $salesSubAccount = $unit->getSubAccountByName('売上高', '売上高');

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '月次売上',
            'interval' => 'monthly',
            'day_of_month' => 15,
            'type' => RecurringTransactionPlan::TYPE_INCOME,
            'debit_sub_account_id' => $depositSubAccount->id,
            'credit_sub_account_id' => $salesSubAccount->id,
            'amount' => 10000,
            'tax_amount' => 1000,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_SALES_10,
        ], $user);

        $transaction = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user)->firstOrFail();

        $debitEntry = $transaction->journalEntries->firstWhere('type', JournalEntry::TYPE_DEBIT);
        $creditEntry = $transaction->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT);

        $this->assertSame(11000, $debitEntry?->net_amount);
        $this->assertSame(0, $debitEntry?->tax_amount);
        $this->assertSame(JournalEntry::TAX_TYPE_OUT_OF_SCOPE, $debitEntry?->tax_type);
        $this->assertSame(10000, $creditEntry?->net_amount);
        $this->assertSame(1000, $creditEntry?->tax_amount);
        $this->assertSame(JournalEntry::TAX_TYPE_TAXABLE_SALES_10, $creditEntry?->tax_type);
    }

    #[Test]
    public function monthlyの収入プランは指定した日に予定取引が生成される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '月初収入テスト']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $depositSubAccount = $unit->getSubAccountByName('その他の預金', 'その他の預金');
        $salesSubAccount = $unit->getSubAccountByName('売上高', '売上高');

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '月次報酬',
            'interval' => 'monthly',
            'day_of_month' => 15,
            'type' => RecurringTransactionPlan::TYPE_INCOME,
            'debit_sub_account_id' => $depositSubAccount->id,
            'credit_sub_account_id' => $salesSubAccount->id,
            'amount' => 10000,
            'tax_amount' => 0,
        ], $user);

        $transactions = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user);
        $dates = $transactions->pluck('date')->sort()->values()->map(fn ($date) => $date->toDateString());

        $this->assertCount(12, $transactions);
        $this->assertSame([
            '2025-01-15',
            '2025-02-15',
            '2025-03-15',
            '2025-04-15',
            '2025-05-15',
            '2025-06-15',
            '2025-07-15',
            '2025-08-15',
            '2025-09-15',
            '2025-10-15',
            '2025-11-15',
            '2025-12-15',
        ], $dates->toArray());
    }

    #[Test]
    public function yearlyの収入プランでは年に1件の予定取引が生成される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '年次収入テスト']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $depositSubAccount = $unit->getSubAccountByName('現金', '現金');
        $salesSubAccount = $unit->getSubAccountByName('売上高', '売上高');

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '年次報酬',
            'interval' => 'yearly',
            'month_of_year' => 6,
            'day_of_month' => 20,
            'type' => RecurringTransactionPlan::TYPE_INCOME,
            'debit_sub_account_id' => $depositSubAccount->id,
            'credit_sub_account_id' => $salesSubAccount->id,
            'amount' => 50000,
            'tax_amount' => 0,
        ], $user);

        $transactions = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user);

        $this->assertCount(1, $transactions);
        $this->assertSame('2025-06-20', $transactions->firstOrFail()->date->toDateString());
        $this->assertSame('2025年分 年次報酬', $transactions->firstOrFail()->description);
    }

    #[Test]
    public function b株式会社の_h_p保守費用年払い収入プランから年1件の予定取引を生成できる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '年払い保守収入テスト']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $counterparty = Counterparty::factory()->create([
            'business_unit_id' => $unit->id,
            'name' => 'B株式会社',
        ]);

        $depositSubAccount = $unit->getSubAccountByName('その他の預金', 'その他の預金');
        $salesSubAccount = $unit->getSubAccountByName('売上高', '売上高');

        $plan = $unit->createRecurringTransactionPlan([
            'name' => 'HP保守費用',
            'interval' => 'yearly',
            'month_of_year' => 4,
            'day_of_month' => 30,
            'type' => RecurringTransactionPlan::TYPE_INCOME,
            'counterparty_id' => $counterparty->id,
            'debit_sub_account_id' => $depositSubAccount->id,
            'credit_sub_account_id' => $salesSubAccount->id,
            'amount' => 240000,
            'tax_amount' => 24000,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_SALES_10,
        ], $user);

        $transactions = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user);

        $this->assertCount(1, $transactions);

        $transaction = $transactions->firstOrFail()->fresh('journalEntries');
        $debitEntry = $transaction->journalEntries->firstWhere('type', JournalEntry::TYPE_DEBIT);
        $creditEntry = $transaction->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT);

        $this->assertSame('2025-04-30', $transaction->date->toDateString());
        $this->assertSame('2025年分 HP保守費用', $transaction->description);
        $this->assertTrue($transaction->is_planned);
        $this->assertSame($counterparty->id, $transaction->counterparty_id);
        $this->assertSame(264000, $debitEntry?->net_amount);
        $this->assertSame($depositSubAccount->id, $debitEntry?->sub_account_id);
        $this->assertSame(240000, $creditEntry?->net_amount);
        $this->assertSame(24000, $creditEntry?->tax_amount);
        $this->assertSame(JournalEntry::TAX_TYPE_TAXABLE_SALES_10, $creditEntry?->tax_type);
    }

    #[Test]
    public function 源泉徴収付き収入プランは借方2行の予定取引を生成できる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '源泉収入テスト']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $depositSubAccount = $unit->getSubAccountByName('その他の預金', 'その他の預金');
        $salesSubAccount = $unit->getSubAccountByName('売上高', '売上高');
        $withholdingSubAccount = $unit->getSubAccountByName('事業主貸', RecurringTransactionPlan::WITHHOLDING_SUB_ACCOUNT_NAME);

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '月次報酬',
            'interval' => 'monthly',
            'day_of_month' => 25,
            'type' => RecurringTransactionPlan::TYPE_INCOME,
            'is_withholding' => true,
            'debit_sub_account_id' => $depositSubAccount->id,
            'credit_sub_account_id' => $salesSubAccount->id,
            'amount' => 100000,
            'tax_amount' => 0,
            'withholding_tax_amount' => 10210,
            'withholding_sub_account_id' => $withholdingSubAccount->id,
        ], $user);

        $transaction = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user)->firstOrFail();

        $debitEntries = $transaction->journalEntries->where('type', JournalEntry::TYPE_DEBIT)->values();
        $creditEntry = $transaction->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT);

        $this->assertCount(2, $debitEntries);
        $this->assertSame(89790, $debitEntries[0]->net_amount);
        $this->assertSame($depositSubAccount->id, $debitEntries[0]->sub_account_id);
        $this->assertSame(10210, $debitEntries[1]->net_amount);
        $this->assertSame($withholdingSubAccount->id, $debitEntries[1]->sub_account_id);
        $this->assertSame(100000, $creditEntry?->net_amount);
        $this->assertSame(0, $creditEntry?->tax_amount);
    }

    #[Test]
    public function aスポーツクラブの月次業務委託収入プランから1年分の予定取引を生成できる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '月次委託収入テスト']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $counterparty = Counterparty::factory()->create([
            'business_unit_id' => $unit->id,
            'name' => 'Aスポーツクラブ',
        ]);

        $depositSubAccount = $unit->getSubAccountByName('その他の預金', 'その他の預金');
        $salesSubAccount = $unit->getSubAccountByName('売上高', '売上高');
        $withholdingSubAccount = $unit->getSubAccountByName('事業主貸', RecurringTransactionPlan::WITHHOLDING_SUB_ACCOUNT_NAME);

        $plan = $unit->createRecurringTransactionPlan([
            'name' => 'インストラクター業務委託',
            'interval' => 'monthly',
            'day_of_month' => 25,
            'type' => RecurringTransactionPlan::TYPE_INCOME,
            'counterparty_id' => $counterparty->id,
            'is_withholding' => true,
            'debit_sub_account_id' => $depositSubAccount->id,
            'credit_sub_account_id' => $salesSubAccount->id,
            'amount' => 100000,
            'tax_amount' => 10000,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_SALES_10,
            'withholding_tax_amount' => 10210,
            'withholding_sub_account_id' => $withholdingSubAccount->id,
        ], $user);

        $transactions = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user);

        $this->assertCount(12, $transactions);
        $this->assertSame('2025-01-25', $transactions->firstOrFail()->date->toDateString());
        $this->assertTrue($transactions->every(fn ($transaction) => $transaction->is_planned));
        $this->assertTrue($transactions->every(fn ($transaction) => $transaction->counterparty_id === $counterparty->id));
        $this->assertSame([
            '1月分 インストラクター業務委託',
            '2月分 インストラクター業務委託',
            '3月分 インストラクター業務委託',
            '4月分 インストラクター業務委託',
            '5月分 インストラクター業務委託',
            '6月分 インストラクター業務委託',
            '7月分 インストラクター業務委託',
            '8月分 インストラクター業務委託',
            '9月分 インストラクター業務委託',
            '10月分 インストラクター業務委託',
            '11月分 インストラクター業務委託',
            '12月分 インストラクター業務委託',
        ], $transactions->pluck('description')->all());

        $firstTransaction = $transactions->firstOrFail()->fresh('journalEntries');
        $debitEntries = $firstTransaction->journalEntries
            ->where('type', JournalEntry::TYPE_DEBIT)
            ->values();
        $creditEntry = $firstTransaction->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT);

        $this->assertCount(2, $debitEntries);
        $this->assertSame(99790, $debitEntries[0]->net_amount);
        $this->assertSame($depositSubAccount->id, $debitEntries[0]->sub_account_id);
        $this->assertSame(10210, $debitEntries[1]->net_amount);
        $this->assertSame($withholdingSubAccount->id, $debitEntries[1]->sub_account_id);
        $this->assertSame(100000, $creditEntry?->net_amount);
        $this->assertSame(10000, $creditEntry?->tax_amount);
        $this->assertSame(JournalEntry::TAX_TYPE_TAXABLE_SALES_10, $creditEntry?->tax_type);
    }

    #[Test]
    public function business_unitとのリレーションが正しく動作する()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業',
        ]);

        $subAccount = $unit->subAccounts()
            ->whereHas('account', fn ($q) => $q->where('name', 'その他の預金'))
            ->firstOrFail();

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '家賃',
            'interval' => 'monthly',
            'day_of_month' => 1,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $subAccount->id,
            'credit_sub_account_id' => $subAccount->id,
            'amount' => 50000,
        ], $user);

        $this->assertEquals($unit->id, $plan->businessUnit->id);
    }

    #[Test]
    public function 他ユーザーは定期取引プランを作成できない()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => '認可テスト事業',
        ]);

        $subAccount = $unit->subAccounts()
            ->whereHas('account', fn ($q) => $q->where('name', 'その他の預金'))
            ->firstOrFail();

        $this->expectException(AuthorizationException::class);

        $unit->createRecurringTransactionPlan([
            'name' => '他ユーザー作成',
            'interval' => 'monthly',
            'day_of_month' => 1,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $subAccount->id,
            'credit_sub_account_id' => $subAccount->id,
            'amount' => 1000,
        ], $otherUser);
    }

    // バリデーションテスト
    #[Test]
    public function 必須項目が全てあればバリデーションに成功する()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業']);

        $debit = $unit->subAccounts()
            ->whereHas('account', fn ($q) => $q->where('name', '水道光熱費'))
            ->firstOrFail();

        $credit = $unit->subAccounts()
            ->whereHas('account', fn ($q) => $q->where('name', 'その他の預金'))
            ->firstOrFail();

        $validated = RecurringTransactionPlan::validate([
            'name' => '水道代',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
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
            ->whereHas('account', fn ($q) => $q->where('name', 'その他の預金'))
            ->firstOrFail();

        RecurringTransactionPlan::validate([
            'business_unit_id' => $unit->id,
            'name' => '誤った間隔',
            'interval' => 'weekly',
            'day_of_month' => 1,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
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
            ->whereHas('account', fn ($q) => $q->where('name', 'その他の預金'))
            ->firstOrFail();

        $unit->createRecurringTransactionPlan([
            'business_unit_id' => $unit->id,
            'name' => '重複チェック',
            'interval' => 'monthly',
            'day_of_month' => 1,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $subAccount->id,
            'credit_sub_account_id' => $subAccount->id,
            'amount' => 1000,
        ], $user);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('【重複チェック】はすでに使われているので使用できません');

        RecurringTransactionPlan::validate([
            'business_unit_id' => $unit->id,
            'name' => '重複チェック',
            'interval' => 'monthly',
            'day_of_month' => 1,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
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
            ->whereHas('account', fn ($q) => $q->where('name', 'その他の預金'))
            ->firstOrFail();

        $sub2 = $unit2->subAccounts()
            ->whereHas('account', fn ($q) => $q->where('name', 'その他の預金'))
            ->firstOrFail();

        $plan1 = $unit1->createRecurringTransactionPlan([
            'business_unit_id' => $unit1->id,
            'name' => '重複チェック',
            'interval' => 'monthly',
            'day_of_month' => 1,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $sub1->id,
            'credit_sub_account_id' => $sub1->id,
            'amount' => 1000,
        ], $user);
        $this->assertNotNull($plan1);

        // 異なる事業単位で同じnameを使用してもエラーにならないことを確認
        $plan2 = $unit2->createRecurringTransactionPlan([
            'business_unit_id' => $unit2->id,
            'name' => '重複チェック',
            'interval' => 'monthly',
            'day_of_month' => 1,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $sub2->id,
            'credit_sub_account_id' => $sub2->id,
            'amount' => 1000,
        ], $user);
        $this->assertNotNull($plan2);
    }

    #[Test]
    public function 収入計画に事業割合を指定するとバリデーションエラーになる()
    {
        $this->expectException(ValidationException::class);

        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '収入バリデーション']);
        $depositSubAccount = $unit->getSubAccountByName('現金', '現金');
        $salesSubAccount = $unit->getSubAccountByName('売上高', '売上高');

        RecurringTransactionPlan::validate([
            'business_unit_id' => $unit->id,
            'name' => '按分付き収入',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'type' => RecurringTransactionPlan::TYPE_INCOME,
            'debit_sub_account_id' => $depositSubAccount->id,
            'credit_sub_account_id' => $salesSubAccount->id,
            'amount' => 10000,
            'business_ratio' => 80,
        ]);
    }

    #[Test]
    public function 支出計画で源泉徴収は指定できない()
    {
        $this->expectException(ValidationException::class);

        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '源泉支出バリデーション']);
        $expenseSubAccount = $unit->getSubAccountByName('消耗品費', '消耗品費');
        $cashSubAccount = $unit->getSubAccountByName('現金', '現金');
        $withholdingSubAccount = $unit->getSubAccountByName('事業主貸', RecurringTransactionPlan::WITHHOLDING_SUB_ACCOUNT_NAME);

        RecurringTransactionPlan::validate([
            'business_unit_id' => $unit->id,
            'name' => '不正な源泉支出',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'is_withholding' => true,
            'debit_sub_account_id' => $expenseSubAccount->id,
            'credit_sub_account_id' => $cashSubAccount->id,
            'amount' => 10000,
            'withholding_tax_amount' => 1000,
            'withholding_sub_account_id' => $withholdingSubAccount->id,
        ]);
    }

    #[Test]
    public function 源泉徴収税額が0だとバリデーションエラーになる()
    {
        $this->expectException(ValidationException::class);

        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '源泉税額0バリデーション']);
        $depositSubAccount = $unit->getSubAccountByName('現金', '現金');
        $salesSubAccount = $unit->getSubAccountByName('売上高', '売上高');
        $withholdingSubAccount = $unit->getSubAccountByName('事業主貸', RecurringTransactionPlan::WITHHOLDING_SUB_ACCOUNT_NAME);

        RecurringTransactionPlan::validate([
            'business_unit_id' => $unit->id,
            'name' => '源泉税0',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'type' => RecurringTransactionPlan::TYPE_INCOME,
            'is_withholding' => true,
            'debit_sub_account_id' => $depositSubAccount->id,
            'credit_sub_account_id' => $salesSubAccount->id,
            'amount' => 10000,
            'withholding_tax_amount' => 0,
            'withholding_sub_account_id' => $withholdingSubAccount->id,
        ]);
    }

    #[Test]
    public function 源泉徴収税額が税込金額以上だとバリデーションエラーになる()
    {
        $this->expectException(ValidationException::class);

        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '源泉超過バリデーション']);
        $depositSubAccount = $unit->getSubAccountByName('現金', '現金');
        $salesSubAccount = $unit->getSubAccountByName('売上高', '売上高');
        $withholdingSubAccount = $unit->getSubAccountByName('事業主貸', RecurringTransactionPlan::WITHHOLDING_SUB_ACCOUNT_NAME);

        RecurringTransactionPlan::validate([
            'business_unit_id' => $unit->id,
            'name' => '源泉超過',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'type' => RecurringTransactionPlan::TYPE_INCOME,
            'is_withholding' => true,
            'debit_sub_account_id' => $depositSubAccount->id,
            'credit_sub_account_id' => $salesSubAccount->id,
            'amount' => 10000,
            'tax_amount' => 0,
            'withholding_tax_amount' => 10000,
            'withholding_sub_account_id' => $withholdingSubAccount->id,
        ]);
    }

    #[Test]
    public function tax付きのmonthlyプランで1年分の予定取引が生成される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業'])->refresh();
        $fiscalYear = $unit->createFiscalYear(2025, $user)->refresh();

        $debitSub = $unit->subAccounts()->whereHas('account', fn ($q) => $q->where('name', '水道光熱費'))->first();
        $creditSub = $unit->subAccounts()->whereHas('account', fn ($q) => $q->where('name', 'その他の預金'))->first();

        $plan = $unit->createRecurringTransactionPlan([
            'business_unit_id' => $unit->id,
            'name' => '税込月次プラン',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $debitSub->id,
            'credit_sub_account_id' => $creditSub->id,
            'amount' => 10000,
            'tax_amount' => 1000,
            'tax_type' => 'taxable_purchases_10',
        ], $user);

        $transactions = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user);

        $this->assertCount(12, $transactions);
        $this->assertTrue($transactions->every(fn ($t) => $t->is_planned));

        foreach ($transactions as $transaction) {
            $entries = $transaction->journalEntries;

            $this->assertEquals(2, $entries->count());
            $this->assertTrue($entries->contains(fn ($e) => $e->tax_amount === 1000));
            $this->assertTrue($entries->contains(fn ($e) => $e->tax_type === 'taxable_purchases_10'));
        }
    }

    #[Test]
    public function day_of_monthが月末より大きい場合はその月の末日に調整される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '末日テスト事業']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $sub = $unit->subAccounts()->whereHas('account', fn ($q) => $q->where('name', '水道光熱費'))->first();

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '末日補正プラン',
            'interval' => 'monthly',
            'day_of_month' => 31,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $sub->id,
            'credit_sub_account_id' => $sub->id,
            'amount' => 10000,
            'tax_amount' => 1000,
        ], $user);

        $transactions = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user);

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
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $sub = $unit->subAccounts()->whereHas('account', fn ($q) => $q->where('name', '水道光熱費'))->first();

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '隔月プラン',
            'interval' => 'bimonthly',
            'day_of_month' => 15,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $sub->id,
            'credit_sub_account_id' => $sub->id,
            'amount' => 5000,
            'tax_amount' => 500,
        ], $user);

        $transactions = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user);

        $this->assertCount(6, $transactions);

        $dates = $transactions->pluck('date')->sort()->values()->map(fn ($d) => $d->toDateString());

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
    public function yearlyプランでは年に1件の予定取引が生成される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '年一プラン事業']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $sub = $unit->subAccounts()->whereHas('account', fn ($q) => $q->where('name', '水道光熱費'))->first();

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '年1プラン',
            'interval' => 'yearly',
            'month_of_year' => 6,
            'day_of_month' => 15,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $sub->id,
            'credit_sub_account_id' => $sub->id,
            'amount' => 5000,
            'tax_amount' => 500,
        ], $user);

        $transactions = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user);

        $this->assertCount(1, $transactions);

        $dates = $transactions->pluck('date')->sort()->values()->map(fn ($d) => $d->toDateString());

        $this->assertEquals([
            '2025-06-15',
        ], $dates->toArray());
    }

    #[Test]
    public function is_activeがfalseのプランでは予定取引が作成されない()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '非アクティブ事業']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);
        $sub = $unit->subAccounts()->whereHas('account', fn ($q) => $q->where('name', '水道光熱費'))->first();

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '非アクティブプラン',
            'interval' => 'monthly',
            'day_of_month' => 1,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $sub->id,
            'credit_sub_account_id' => $sub->id,
            'amount' => 3000,
            'tax_amount' => 300,
            'is_active' => false,
        ], $user);

        $transactions = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user);

        $this->assertCount(0, $transactions);
    }

    #[Test]
    public function 生成された取引にはrecurring_transaction_plan_idが設定される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'リンクテスト事業']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);
        $sub = $unit->subAccounts()->whereHas('account', fn ($q) => $q->where('name', '水道光熱費'))->first();

        $plan = $unit->createRecurringTransactionPlan([
            'name' => 'リンク付きプラン',
            'interval' => 'monthly',
            'day_of_month' => 1,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $sub->id,
            'credit_sub_account_id' => $sub->id,
            'amount' => 8000,
            'tax_amount' => 800,
        ], $user);

        $transactions = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user);

        $this->assertCount(12, $transactions);

        foreach ($transactions as $transaction) {
            $this->assertTrue($transaction->is_planned);
            $this->assertEquals($plan->id, $transaction->recurring_transaction_plan_id);
        }
    }

    #[Test]
    public function 他ユーザーは予定取引を生成できない()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '認可テスト事業']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);
        $sub = $unit->subAccounts()->whereHas('account', fn ($q) => $q->where('name', '水道光熱費'))->first();

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '認可テストプラン',
            'interval' => 'monthly',
            'day_of_month' => 1,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $sub->id,
            'credit_sub_account_id' => $sub->id,
            'amount' => 3000,
            'tax_amount' => 300,
        ], $user);

        $this->expectException(AuthorizationException::class);

        $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $otherUser);
    }

    #[Test]
    public function 他ユーザーは定期取引の予定取引を確定できない()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '確定認可テスト事業']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);
        $sub = $unit->subAccounts()->whereHas('account', fn ($q) => $q->where('name', '水道光熱費'))->firstOrFail();

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '確定認可テストプラン',
            'interval' => 'monthly',
            'day_of_month' => 1,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $sub->id,
            'credit_sub_account_id' => $sub->id,
            'amount' => 3000,
            'tax_amount' => 0,
        ], $user);

        $transaction = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user)->firstOrFail();

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('この定期取引を確定する権限がありません。');

        $plan->confirmTransaction($transaction->id, [
            'date' => '2025-12-10',
            'amount' => 3000,
            'credit_sub_account_id' => $sub->id,
        ], $otherUser);
    }

    #[Test]
    #[Group('mysql')]
    public function 同じ日付の予定取引が既に存在する場合は作成されない()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '重複防止テスト事業']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);
        $sub = $unit->subAccounts()->whereHas('account', fn ($q) => $q->where('name', '水道光熱費'))->first();

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '重複チェックプラン',
            'interval' => 'monthly',
            'day_of_month' => 1,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $sub->id,
            'credit_sub_account_id' => $sub->id,
            'amount' => 5000,
            'tax_amount' => 500,
        ], $user);

        // 初回：すべて作成される（12件）
        $firstRun = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user);
        $this->assertCount(12, $firstRun);

        // 2回目：すでに存在しているためスキップ（0件作成）
        $secondRun = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user);
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
        $fiscalYear = $unit->createFiscalYear(2025, $user);
        $sub = $unit->subAccounts()->whereHas('account', fn ($q) => $q->where('name', '水道光熱費'))->first();

        $plan1 = $unit->createRecurringTransactionPlan([
            'name' => 'プランA',
            'interval' => 'monthly',
            'day_of_month' => 1,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $sub->id,
            'credit_sub_account_id' => $sub->id,
            'amount' => 3000,
            'tax_amount' => 300,
        ], $user);

        $plan2 = $unit->createRecurringTransactionPlan([
            'name' => 'プランB',
            'interval' => 'monthly',
            'day_of_month' => 1, // 同じ日付
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $sub->id,
            'credit_sub_account_id' => $sub->id,
            'amount' => 7000,
            'tax_amount' => 700,
        ], $user);

        // 両方のプランで生成
        $transactions1 = $unit->generatePlannedTransactionsForPlan($plan1, $fiscalYear, $user);
        $transactions2 = $unit->generatePlannedTransactionsForPlan($plan2, $fiscalYear, $user);

        // 両者とも12件ずつ生成されていること
        $this->assertCount(12, $transactions1);
        $this->assertCount(12, $transactions2);

        // 各取引に適切な plan_id が設定されていること
        $this->assertTrue($transactions1->every(fn ($t) => $t->recurring_transaction_plan_id === $plan1->id));
        $this->assertTrue($transactions2->every(fn ($t) => $t->recurring_transaction_plan_id === $plan2->id));

        // 合計24件生成されていること
        $this->assertEquals(
            24,
            $fiscalYear->transactions()
                ->where('is_planned', true)
                ->whereBetween('date', [$fiscalYear->start_date, $fiscalYear->end_date])
                ->count()
        );
    }

    #[Test]
    public function start_monthが1の隔月プランは奇数月にのみ取引を生成する()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '隔月奇数月テスト']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);
        $subAccount = $unit->getAccountByName('水道光熱費')->subAccounts()->firstOrFail();

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '隔月奇数月プラン',
            'interval' => 'bimonthly',
            'day_of_month' => 15,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $subAccount->id,
            'credit_sub_account_id' => $subAccount->id,
            'amount' => 6000,
            'tax_amount' => 600,
            'start_month' => 1, // 1月から開始
        ], $user);

        $transactions = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user);

        // 奇数月にのみ取引が生成されることを確認
        $this->assertCount(6, $transactions);
        $dates = $transactions->pluck('date')->sort()->values()->map(fn ($d) => $d->toDateString());

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
    public function start_monthが2の隔月プランは偶数月にのみ取引を生成する()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '隔月偶数月テスト']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);
        $subAccount = $unit->getAccountByName('水道光熱費')->subAccounts()->firstOrFail();

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '隔月偶数月プラン',
            'interval' => 'bimonthly',
            'day_of_month' => 15,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $subAccount->id,
            'credit_sub_account_id' => $subAccount->id,
            'amount' => 6000,
            'tax_amount' => 600,
            'start_month' => 2, // 2月から開始
        ], $user);

        $transactions = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user);

        // 偶数月にのみ取引が生成されることを確認
        $this->assertCount(6, $transactions);
        $dates = $transactions->pluck('date')->sort()->values()->map(fn ($d) => $d->toDateString());

        $this->assertEquals([
            '2025-02-15',
            '2025-04-15',
            '2025-06-15',
            '2025-08-15',
            '2025-10-15',
            '2025-12-15',
        ], $dates->toArray());
    }

    #[Test]
    public function start_monthがnullの場合は奇数月で取引が生成される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '隔月nullテスト']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);
        $subAccount = $unit->getAccountByName('水道光熱費')->subAccounts()->firstOrFail();

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '隔月nullプラン',
            'interval' => 'bimonthly',
            'day_of_month' => 15,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $subAccount->id,
            'credit_sub_account_id' => $subAccount->id,
            'amount' => 6000,
            'tax_amount' => 600,
            'start_month' => null, // start_month が null
        ], $user);

        $transactions = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user);

        // 奇数月にのみ取引が生成されることを確認
        $this->assertCount(6, $transactions);
        $dates = $transactions->pluck('date')->sort()->values()->map(fn ($d) => $d->toDateString());

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
    public function 収入プラン確定時に入金先補助科目を変更できる()
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $unit = $user->createBusinessUnitWithDefaults(['name' => '収入確定テスト']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $cashSubAccount = $unit->getSubAccountByName('現金', '現金');
        $bankSubAccount = $unit->getSubAccountByName('その他の預金', 'その他の預金');
        $salesSubAccount = $unit->getSubAccountByName('売上高', '売上高');

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '定期売上',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'type' => RecurringTransactionPlan::TYPE_INCOME,
            'debit_sub_account_id' => $cashSubAccount->id,
            'credit_sub_account_id' => $salesSubAccount->id,
            'amount' => 1100,
            'tax_amount' => 0,
        ], $user);

        $transaction = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user)->firstOrFail();

        $plan->confirmTransaction($transaction->id, [
            'date' => '2025-12-10',
            'amount' => 1400,
            'debit_sub_account_id' => $bankSubAccount->id,
        ], $user);

        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $transaction->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 1400,
            'sub_account_id' => $bankSubAccount->id,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $transaction->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 1400,
            'sub_account_id' => $salesSubAccount->id,
        ]);
    }

    #[Test]
    public function 課税収入プラン確定時に売上税区分と税額が保持される()
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $unit = $user->createBusinessUnitWithDefaults(['name' => '課税収入確定テスト']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $depositSubAccount = $unit->getSubAccountByName('現金', '現金');
        $salesSubAccount = $unit->getSubAccountByName('売上高', '売上高');

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '課税売上',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'type' => RecurringTransactionPlan::TYPE_INCOME,
            'debit_sub_account_id' => $depositSubAccount->id,
            'credit_sub_account_id' => $salesSubAccount->id,
            'amount' => 10000,
            'tax_amount' => 1000,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_SALES_10,
        ], $user);

        $transaction = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user)->firstOrFail();

        $plan->confirmTransaction($transaction->id, [
            'date' => '2025-12-10',
            'amount' => 22000,
            'debit_sub_account_id' => $depositSubAccount->id,
        ], $user);

        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $transaction->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 22000,
            'sub_account_id' => $depositSubAccount->id,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $transaction->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 20000,
            'tax_amount' => 2000,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_SALES_10,
            'sub_account_id' => $salesSubAccount->id,
        ]);
    }

    #[Test]
    public function 源泉徴収付き収入プラン確定時に借方2行が保持される()
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $unit = $user->createBusinessUnitWithDefaults(['name' => '源泉収入確定テスト']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $cashSubAccount = $unit->getSubAccountByName('現金', '現金');
        $bankSubAccount = $unit->getSubAccountByName('その他の預金', 'その他の預金');
        $salesSubAccount = $unit->getSubAccountByName('売上高', '売上高');
        $withholdingSubAccount = $unit->getSubAccountByName('事業主貸', RecurringTransactionPlan::WITHHOLDING_SUB_ACCOUNT_NAME);

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '源泉付き報酬',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'type' => RecurringTransactionPlan::TYPE_INCOME,
            'is_withholding' => true,
            'debit_sub_account_id' => $cashSubAccount->id,
            'credit_sub_account_id' => $salesSubAccount->id,
            'amount' => 100000,
            'tax_amount' => 0,
            'withholding_tax_amount' => 10210,
            'withholding_sub_account_id' => $withholdingSubAccount->id,
        ], $user);

        $transaction = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user)->firstOrFail();

        $plan->confirmTransaction($transaction->id, [
            'date' => '2025-12-10',
            'amount' => 100000,
            'debit_sub_account_id' => $bankSubAccount->id,
        ], $user);

        $debitEntries = $transaction->fresh('journalEntries')->journalEntries
            ->where('type', JournalEntry::TYPE_DEBIT)
            ->values();

        $this->assertCount(2, $debitEntries);
        $this->assertSame(89790, $debitEntries[0]->net_amount);
        $this->assertSame($bankSubAccount->id, $debitEntries[0]->sub_account_id);
        $this->assertSame(10210, $debitEntries[1]->net_amount);
        $this->assertSame($withholdingSubAccount->id, $debitEntries[1]->sub_account_id);
    }

    #[Test]
    public function 源泉徴収付き収入プラン確定時に税込金額が源泉徴収税額以下だとバリデーションエラーになる()
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $unit = $user->createBusinessUnitWithDefaults(['name' => '源泉収入確定バリデーション']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $cashSubAccount = $unit->getSubAccountByName('現金', '現金');
        $salesSubAccount = $unit->getSubAccountByName('売上高', '売上高');
        $withholdingSubAccount = $unit->getSubAccountByName('事業主貸', RecurringTransactionPlan::WITHHOLDING_SUB_ACCOUNT_NAME);

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '源泉付き報酬',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'type' => RecurringTransactionPlan::TYPE_INCOME,
            'is_withholding' => true,
            'debit_sub_account_id' => $cashSubAccount->id,
            'credit_sub_account_id' => $salesSubAccount->id,
            'amount' => 100000,
            'tax_amount' => 0,
            'withholding_tax_amount' => 10210,
            'withholding_sub_account_id' => $withholdingSubAccount->id,
        ], $user);

        $transaction = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user)->firstOrFail();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('源泉徴収税額より大きい税込金額を指定してください。');

        $plan->confirmTransaction($transaction->id, [
            'date' => '2025-12-10',
            'amount' => 10210,
            'debit_sub_account_id' => $cashSubAccount->id,
        ], $user);
    }

    #[Test]
    public function planned_transactionを確定できる()
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $unit = $user->createBusinessUnitWithDefaults(['name' => '確認テスト事業']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $debitSubAccount = $unit->getAccountByName('水道光熱費')->subAccounts()->firstOrFail();
        $creditSubAccount = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $newCreditSubAccount = $unit->getAccountByName('事業主借')->subAccounts()->firstOrFail();

        $plan = $unit->createRecurringTransactionPlan([
            'name' => 'サーバー代',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $debitSubAccount->id,
            'credit_sub_account_id' => $creditSubAccount->id,
            'amount' => 1100,
            'tax_amount' => 0,
        ], $user);

        $transaction = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user)->firstOrFail();

        $confirmed = $plan->confirmTransaction($transaction->id, [
            'date' => '2025-12-10',
            'amount' => 1400,
            'credit_sub_account_id' => $newCreditSubAccount->id,
        ], $user);

        $this->assertNotNull($confirmed);
        $this->assertFalse($confirmed->is_planned);
        $this->assertSame('2025-12-10', $confirmed->date->toDateString());

        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $transaction->id,
            'type' => 'debit',
            'net_amount' => 1400,
            'sub_account_id' => $debitSubAccount->id,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $transaction->id,
            'type' => 'credit',
            'net_amount' => 1400,
            'sub_account_id' => $newCreditSubAccount->id,
        ]);
    }

    #[Test]
    public function 事業割合つきの定期取引は按分された予定仕訳を生成できる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '按分定期テスト事業']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $debitSubAccount = $unit->getAccountByName('水道光熱費')->subAccounts()->firstOrFail();
        $creditSubAccount = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $householdSubAccount = $unit->getSubAccountByName('事業主貸', '家事按分');

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '按分あり固定費',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $debitSubAccount->id,
            'credit_sub_account_id' => $creditSubAccount->id,
            'amount' => 10000,
            'tax_amount' => 0,
            'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            'business_ratio' => 60,
        ], $user);

        $transaction = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user)->firstOrFail();
        $householdSubAccount = $unit->getSubAccountByName('事業主貸', '家事按分');

        $this->assertTrue($transaction->is_planned);
        $this->assertSame(60, $transaction->business_ratio);
        $this->assertCount(3, $transaction->journalEntries);
        $this->assertSame(6000, $transaction->journalEntries->firstWhere('business_ratio', 60)?->net_amount);
        $this->assertNotNull($householdSubAccount);
        $this->assertSame(4000, $transaction->journalEntries->firstWhere('sub_account_id', $householdSubAccount->id)?->net_amount);
    }

    #[Test]
    public function tax_type未指定の定期取引はcomputed_from_grossとして予定仕訳を生成できる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '定期税区分既定値テスト事業']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $debitSubAccount = $unit->getAccountByName('水道光熱費')->subAccounts()->firstOrFail();
        $creditSubAccount = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '税区分既定値プラン',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $debitSubAccount->id,
            'credit_sub_account_id' => $creditSubAccount->id,
            'amount' => 10000,
            'tax_amount' => 0,
        ], $user);

        $transaction = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user)->firstOrFail();
        $debitEntry = $transaction->journalEntries->firstWhere('type', JournalEntry::TYPE_DEBIT);

        $this->assertNotNull($debitEntry);
        $this->assertSame(JournalEntry::TAX_TYPE_OUT_OF_SCOPE, $debitEntry->tax_type);
        $this->assertSame(JournalEntry::TAX_AMOUNT_SOURCE_COMPUTED_FROM_GROSS, $debitEntry->tax_amount_source);
    }

    #[Test]
    public function planned_transaction確定時に貸方補助科目を変更できる()
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $unit = $user->createBusinessUnitWithDefaults(['name' => '貸方変更テスト事業']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $debitSubAccount = $unit->getAccountByName('水道光熱費')->subAccounts()->firstOrFail();
        $originalCreditSubAccount = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $newCreditSubAccount = $unit->getAccountByName('事業主借')->subAccounts()->firstOrFail();

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '貸方変更プラン',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $debitSubAccount->id,
            'credit_sub_account_id' => $originalCreditSubAccount->id,
            'amount' => 1100,
            'tax_amount' => 0,
        ], $user);

        $transaction = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user)->firstOrFail();

        $plan->confirmTransaction($transaction->id, [
            'date' => $transaction->date->toDateString(),
            'amount' => 1100,
            'credit_sub_account_id' => $newCreditSubAccount->id,
        ], $user);

        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $transaction->id,
            'type' => 'credit',
            'net_amount' => 1100,
            'sub_account_id' => $newCreditSubAccount->id,
        ]);

        $this->assertDatabaseMissing('journal_entries', [
            'transaction_id' => $transaction->id,
            'type' => 'credit',
            'net_amount' => 1100,
            'sub_account_id' => $originalCreditSubAccount->id,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $transaction->id,
            'type' => 'debit',
            'net_amount' => 1100,
            'sub_account_id' => $debitSubAccount->id,
        ]);
    }

    #[Test]
    public function 他のプランのtransactionは確定できない()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '確認テスト事業']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);
        $subAccount = $unit->getAccountByName('水道光熱費')->subAccounts()->firstOrFail();

        $plan1 = $unit->createRecurringTransactionPlan([
            'name' => 'プランA',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $subAccount->id,
            'credit_sub_account_id' => $subAccount->id,
            'amount' => 1100,
            'tax_amount' => 0,
        ], $user);

        $plan2 = $unit->createRecurringTransactionPlan([
            'name' => 'プランB',
            'interval' => 'monthly',
            'day_of_month' => 15,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $subAccount->id,
            'credit_sub_account_id' => $subAccount->id,
            'amount' => 2200,
            'tax_amount' => 0,
        ], $user);

        $transaction = $unit->generatePlannedTransactionsForPlan($plan2, $fiscalYear, $user)->firstOrFail();

        $result = $plan1->confirmTransaction($transaction->id, [
            'date' => '2025-12-10',
            'amount' => 1400,
            'credit_sub_account_id' => $subAccount->id,
        ], $user);

        $this->assertNull($result);
        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'is_planned' => true,
        ]);
    }

    #[Test]
    public function 他事業体の貸方補助科目では確定できない()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $unit = $user->createBusinessUnitWithDefaults(['name' => '自分の事業体']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);
        $otherUnit = $otherUser->createBusinessUnitWithDefaults(['name' => '他人の事業体']);
        $otherUnit->createFiscalYear(2025, $otherUser);

        $debitSubAccount = $unit->getAccountByName('水道光熱費')->subAccounts()->firstOrFail();
        $creditSubAccount = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $foreignCreditSubAccount = $otherUnit->getAccountByName('現金')->subAccounts()->firstOrFail();

        $plan = $unit->createRecurringTransactionPlan([
            'name' => 'サーバー代',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $debitSubAccount->id,
            'credit_sub_account_id' => $creditSubAccount->id,
            'amount' => 1100,
            'tax_amount' => 0,
        ], $user);

        $transaction = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user)->firstOrFail();

        try {
            $plan->confirmTransaction($transaction->id, [
                'date' => '2025-12-10',
                'amount' => 1400,
                'credit_sub_account_id' => $foreignCreditSubAccount->id,
            ], $user);

            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('credit_sub_account_id', $e->errors());
        }

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'is_planned' => true,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $transaction->id,
            'type' => 'credit',
            'net_amount' => 1100,
            'sub_account_id' => $creditSubAccount->id,
        ]);
    }

    #[Test]
    public function 決算済み年度の予定取引は確定できない()
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $unit = $user->createBusinessUnitWithDefaults(['name' => '決算後確定不可']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);
        $debitSubAccount = $unit->getAccountByName('水道光熱費')->subAccounts()->firstOrFail();
        $creditSubAccount = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '締め後プラン',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $debitSubAccount->id,
            'credit_sub_account_id' => $creditSubAccount->id,
            'amount' => 1100,
            'tax_amount' => 0,
        ], $user);

        $transaction = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user)->firstOrFail();
        $fiscalYear->update(['is_closed' => true]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('決算済みの会計年度に属する予定取引は確定できません。');

        $plan->confirmTransaction($transaction->id, [
            'date' => '2025-12-10',
            'amount' => 1100,
            'credit_sub_account_id' => $creditSubAccount->id,
        ], $user);
    }

    #[Test]
    public function counterparty_idを指定した計画から生成した予定取引に取引先が引き継がれる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '取引先付き定期取引']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);
        $counterparty = Counterparty::factory()->create([
            'business_unit_id' => $unit->id,
        ]);
        $expenseSubAccount = $unit->getSubAccountByName('通信費', '通信費');
        $cashSubAccount = $unit->getSubAccountByName('現金', '現金');

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '回線利用料',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'counterparty_id' => $counterparty->id,
            'debit_sub_account_id' => $expenseSubAccount->id,
            'credit_sub_account_id' => $cashSubAccount->id,
            'amount' => 5500,
            'tax_amount' => 0,
        ], $user);

        $transaction = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user)->firstOrFail();

        $this->assertSame($counterparty->id, $plan->counterparty?->id);
        $this->assertSame($counterparty->id, $transaction->counterparty_id);
        $this->assertTrue($counterparty->recurringTransactionPlans->contains($plan));
    }

    #[Test]
    public function counterparty_idを指定しない計画からは取引先なしの予定取引が生成される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '取引先なし定期取引']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);
        $expenseSubAccount = $unit->getSubAccountByName('通信費', '通信費');
        $cashSubAccount = $unit->getSubAccountByName('現金', '現金');

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '回線利用料',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $expenseSubAccount->id,
            'credit_sub_account_id' => $cashSubAccount->id,
            'amount' => 5500,
            'tax_amount' => 0,
        ], $user);

        $transaction = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user)->firstOrFail();

        $this->assertNull($plan->counterparty_id);
        $this->assertNull($transaction->counterparty_id);
    }

    #[Test]
    public function 別事業体のcounterparty_idを指定するとバリデーションエラーになる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '自分の事業体']);
        $otherUser = User::factory()->create();
        $otherUnit = $otherUser->createBusinessUnitWithDefaults(['name' => '他人の事業体']);
        $foreignCounterparty = Counterparty::factory()->create([
            'business_unit_id' => $otherUnit->id,
        ]);
        $expenseSubAccount = $unit->getSubAccountByName('通信費', '通信費');
        $cashSubAccount = $unit->getSubAccountByName('現金', '現金');

        $this->expectException(ValidationException::class);

        $unit->createRecurringTransactionPlan([
            'name' => '回線利用料',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'counterparty_id' => $foreignCounterparty->id,
            'debit_sub_account_id' => $expenseSubAccount->id,
            'credit_sub_account_id' => $cashSubAccount->id,
            'amount' => 5500,
            'tax_amount' => 0,
        ], $user);
    }

    #[Test]
    #[Group('mysql')]
    public function counterparty_idを持つ計画由来の取引が取引先集計に含まれる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '取引先集計付き定期取引']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);
        $counterparty = Counterparty::factory()->create([
            'business_unit_id' => $unit->id,
        ]);
        $expenseSubAccount = $unit->getSubAccountByName('通信費', '通信費');
        $cashSubAccount = $unit->getSubAccountByName('現金', '現金');

        $plan = $unit->createRecurringTransactionPlan([
            'name' => '回線利用料',
            'interval' => 'yearly',
            'month_of_year' => 5,
            'day_of_month' => 10,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'counterparty_id' => $counterparty->id,
            'debit_sub_account_id' => $expenseSubAccount->id,
            'credit_sub_account_id' => $cashSubAccount->id,
            'amount' => 5000,
            'tax_amount' => 500,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
        ], $user);

        $transaction = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user)->firstOrFail();
        $summary = $counterparty->calculateAmountSummaryForFiscalYear(2025);

        $this->assertSame($counterparty->id, $transaction->counterparty_id);
        $this->assertSame([
            'expense' => [
                'accounts' => [
                    [
                        'account_id' => $expenseSubAccount->account_id,
                        'account_name' => '通信費',
                        'amount' => 5500,
                    ],
                ],
                'total_amount' => 5500,
            ],
            'income' => [
                'accounts' => [],
                'total_amount' => 0,
            ],
        ], $summary);
    }
}
