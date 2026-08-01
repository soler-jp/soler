<?php

namespace Tests\Feature\TodoHandlers;

use App\Models\JournalEntry;
use App\Models\RecurringTransactionPlan;
use App\Models\Todo;
use App\Models\User;
use App\TodoHandlers\RecurringExpenseTodoHandler;
use App\TodoHandlers\RecurringIncomeTodoHandler;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecurringTransactionPlanTodoHandlerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function todo_typeは定期支出用handlerを返す(): void
    {
        $this->assertSame(
            Todo::TODO_TYPE_WIZARD_RECURRING_EXPENSES,
            app(RecurringExpenseTodoHandler::class)->todoType(),
        );
    }

    #[Test]
    public function todo_typeは定期収入用handlerを返す(): void
    {
        $this->assertSame(
            Todo::TODO_TYPE_WIZARD_RECURRING_INCOMES,
            app(RecurringIncomeTodoHandler::class)->todoType(),
        );
    }

    #[Test]
    public function validateは定期支出行をtrimして正規化する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '定期支出検証']);
        $expenseSubAccount = $businessUnit->getSubAccountByName('消耗品費', '消耗品費');
        $cashSubAccount = $businessUnit->getSubAccountByName('現金', '現金');
        $todo = Todo::factory()->make([
            'business_unit_id' => $businessUnit->id,
            'todo_type' => Todo::TODO_TYPE_WIZARD_RECURRING_EXPENSES,
        ]);

        $validated = app(RecurringExpenseTodoHandler::class)->validate($todo, [
            'plans' => [
                [
                    'name' => '  サーバー代  ',
                    'interval' => 'bimonthly',
                    'day_of_month' => '15',
                    'start_month_type' => 'even',
                    'debit_sub_account_id' => (string) $expenseSubAccount->id,
                    'credit_sub_account_id' => (string) $cashSubAccount->id,
                    'amount' => '1100',
                    'tax_amount' => '100',
                    'business_ratio' => '80',
                ],
            ],
        ]);

        $this->assertSame([
            'plans' => [
                [
                    'business_unit_id' => $businessUnit->id,
                    'name' => 'サーバー代',
                    'interval' => 'bimonthly',
                    'day_of_month' => 15,
                    'month_of_year' => null,
                    'start_month' => 2,
                    'type' => RecurringTransactionPlan::TYPE_EXPENSE,
                    'debit_sub_account_id' => $expenseSubAccount->id,
                    'credit_sub_account_id' => $cashSubAccount->id,
                    'amount' => 1100,
                    'tax_amount' => 100,
                    'tax_type' => null,
                    'counterparty_id' => null,
                    'is_active' => true,
                    'business_ratio' => 80,
                ],
            ],
        ], $validated);
    }

    #[Test]
    public function validateは定期収入行をtrimして正規化する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '定期収入検証']);
        $depositSubAccount = $businessUnit->getSubAccountByName('その他の預金', 'その他の預金');
        $salesSubAccount = $businessUnit->getSubAccountByName('売上高', '売上高');
        $withholdingSubAccount = $businessUnit->getSubAccountByName('事業主貸', RecurringTransactionPlan::WITHHOLDING_SUB_ACCOUNT_NAME);
        $todo = Todo::factory()->make([
            'business_unit_id' => $businessUnit->id,
            'todo_type' => Todo::TODO_TYPE_WIZARD_RECURRING_INCOMES,
        ]);

        $validated = app(RecurringIncomeTodoHandler::class)->validate($todo, [
            'plans' => [
                [
                    'name' => '  顧問料  ',
                    'interval' => 'monthly',
                    'day_of_month' => '10',
                    'debit_sub_account_id' => (string) $depositSubAccount->id,
                    'credit_sub_account_id' => (string) $salesSubAccount->id,
                    'amount' => '100000',
                    'tax_amount' => '0',
                    'is_withholding' => true,
                    'withholding_tax_amount' => '10210',
                    'withholding_sub_account_id' => (string) $withholdingSubAccount->id,
                ],
            ],
        ]);

        $this->assertSame([
            'plans' => [
                [
                    'business_unit_id' => $businessUnit->id,
                    'name' => '顧問料',
                    'interval' => 'monthly',
                    'day_of_month' => 10,
                    'month_of_year' => null,
                    'start_month' => null,
                    'type' => RecurringTransactionPlan::TYPE_INCOME,
                    'debit_sub_account_id' => $depositSubAccount->id,
                    'credit_sub_account_id' => $salesSubAccount->id,
                    'amount' => 100000,
                    'tax_amount' => 0,
                    'tax_type' => null,
                    'counterparty_id' => null,
                    'is_active' => true,
                    'is_withholding' => true,
                    'withholding_tax_amount' => 10210,
                    'withholding_sub_account_id' => $withholdingSubAccount->id,
                ],
            ],
        ], $validated);
    }

    #[Test]
    public function validateはstart_monthを直接受け付ける(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '開始月検証']);
        $expenseSubAccount = $businessUnit->getSubAccountByName('消耗品費', '消耗品費');
        $cashSubAccount = $businessUnit->getSubAccountByName('現金', '現金');
        $todo = Todo::factory()->make([
            'business_unit_id' => $businessUnit->id,
            'todo_type' => Todo::TODO_TYPE_WIZARD_RECURRING_EXPENSES,
        ]);

        $validated = app(RecurringExpenseTodoHandler::class)->validate($todo, [
            'plans' => [
                [
                    'name' => '四半期ごと支払い',
                    'interval' => 'bimonthly',
                    'day_of_month' => 15,
                    'start_month' => 3,
                    'debit_sub_account_id' => $expenseSubAccount->id,
                    'credit_sub_account_id' => $cashSubAccount->id,
                    'amount' => 5000,
                ],
            ],
        ]);

        $this->assertSame(3, $validated['plans'][0]['start_month']);
    }

    #[Test]
    public function validateは同名プランの2件目以降に重複エラーを付ける(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '重複検証']);
        $expenseSubAccount = $businessUnit->getSubAccountByName('消耗品費', '消耗品費');
        $cashSubAccount = $businessUnit->getSubAccountByName('現金', '現金');
        $todo = Todo::factory()->make([
            'business_unit_id' => $businessUnit->id,
            'todo_type' => Todo::TODO_TYPE_WIZARD_RECURRING_EXPENSES,
        ]);

        try {
            app(RecurringExpenseTodoHandler::class)->validate($todo, [
                'plans' => [
                    [
                        'name' => '  サーバー代  ',
                        'interval' => 'monthly',
                        'day_of_month' => 5,
                        'debit_sub_account_id' => $expenseSubAccount->id,
                        'credit_sub_account_id' => $cashSubAccount->id,
                        'amount' => 3300,
                    ],
                    [
                        'name' => 'サーバー代',
                        'interval' => 'monthly',
                        'day_of_month' => 20,
                        'debit_sub_account_id' => $expenseSubAccount->id,
                        'credit_sub_account_id' => $cashSubAccount->id,
                        'amount' => 4400,
                    ],
                ],
            ]);

            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['同じ名前が入力内で重複しています。'],
                $exception->errors()['plans.1.name'] ?? [],
            );
        }
    }

    #[Test]
    public function validateは開始月と開始月種別の同時指定を拒否する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '開始月競合検証']);
        $expenseSubAccount = $businessUnit->getSubAccountByName('消耗品費', '消耗品費');
        $cashSubAccount = $businessUnit->getSubAccountByName('現金', '現金');
        $todo = Todo::factory()->make([
            'business_unit_id' => $businessUnit->id,
            'todo_type' => Todo::TODO_TYPE_WIZARD_RECURRING_EXPENSES,
        ]);

        try {
            app(RecurringExpenseTodoHandler::class)->validate($todo, [
                'plans' => [
                    [
                        'name' => 'サーバー代',
                        'interval' => 'bimonthly',
                        'day_of_month' => 5,
                        'start_month' => 3,
                        'start_month_type' => 'odd',
                        'debit_sub_account_id' => $expenseSubAccount->id,
                        'credit_sub_account_id' => $cashSubAccount->id,
                        'amount' => 3300,
                    ],
                ],
            ]);

            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['開始月と開始月種別は同時に指定できません。'],
                $exception->errors()['plans.0.start_month'] ?? [],
            );
            $this->assertSame(
                ['開始月と開始月種別は同時に指定できません。'],
                $exception->errors()['plans.0.start_month_type'] ?? [],
            );
        }
    }

    #[Test]
    public function validateは空白のみのnameに必須エラーを一つだけ返す(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '空白名検証']);
        $todo = Todo::factory()->make([
            'business_unit_id' => $businessUnit->id,
            'todo_type' => Todo::TODO_TYPE_WIZARD_RECURRING_EXPENSES,
        ]);

        try {
            app(RecurringExpenseTodoHandler::class)->validate($todo, [
                'plans' => [
                    [
                        'name' => '   ',
                        'interval' => 'monthly',
                        'day_of_month' => 0,
                        'debit_sub_account_id' => 999999,
                        'credit_sub_account_id' => 999998,
                        'amount' => 0,
                    ],
                ],
            ]);

            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['定期取引名を入力してください。'],
                $exception->errors()['plans.0.name'] ?? [],
            );
        }
    }

    #[Test]
    public function validateは不正な入力を拒否する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '定期取引不正検証']);
        $todo = Todo::factory()->make([
            'business_unit_id' => $businessUnit->id,
            'todo_type' => Todo::TODO_TYPE_WIZARD_RECURRING_EXPENSES,
        ]);

        $this->expectException(ValidationException::class);

        app(RecurringExpenseTodoHandler::class)->validate($todo, [
            'plans' => [
                [
                    'name' => '   ',
                    'interval' => 'monthly',
                    'day_of_month' => 0,
                    'debit_sub_account_id' => 999999,
                    'credit_sub_account_id' => 999998,
                    'amount' => 0,
                ],
            ],
        ]);
    }

    #[Test]
    public function executeは会計年度なしtodoを拒否する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '定期取引登録テスト']);
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'fiscal_year_id' => null,
            'todo_type' => Todo::TODO_TYPE_WIZARD_RECURRING_EXPENSES,
            'status' => Todo::STATUS_PENDING,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('会計年度に紐づかない Todo では定期取引を登録できません。');

        app(RecurringExpenseTodoHandler::class)->execute($todo, [
            'plans' => [],
        ], $user);
    }

    #[Test]
    public function executeは空のplansを拒否する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '定期取引空配列テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'fiscal_year_id' => $fiscalYear->id,
            'todo_type' => Todo::TODO_TYPE_WIZARD_RECURRING_EXPENSES,
            'status' => Todo::STATUS_PENDING,
        ]);

        try {
            app(RecurringExpenseTodoHandler::class)->execute($todo, [
                'plans' => [],
            ], $user);
            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['定期取引を1件以上入力してください。'],
                $exception->errors()['plans'] ?? [],
            );
        }

        $todo->refresh();
        $this->assertSame(Todo::STATUS_PENDING, $todo->status);
    }

    #[Test]
    public function executeは定期支出を複数登録してtodoを完了する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '定期支出登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);
        $expenseSubAccount = $businessUnit->getSubAccountByName('消耗品費', '消耗品費');
        $cashSubAccount = $businessUnit->getSubAccountByName('現金', '現金');
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'fiscal_year_id' => $fiscalYear->id,
            'todo_type' => Todo::TODO_TYPE_WIZARD_RECURRING_EXPENSES,
            'status' => Todo::STATUS_PENDING,
        ]);

        Carbon::setTestNow('2026-08-01 14:00:00');

        try {
            app(RecurringExpenseTodoHandler::class)->execute($todo, [
                'plans' => [
                    [
                        'name' => 'サーバー代',
                        'interval' => 'monthly',
                        'day_of_month' => 5,
                        'debit_sub_account_id' => $expenseSubAccount->id,
                        'credit_sub_account_id' => $cashSubAccount->id,
                        'amount' => 3300,
                        'tax_amount' => 300,
                        'business_ratio' => 80,
                    ],
                    [
                        'name' => '顧問契約',
                        'interval' => 'bimonthly',
                        'day_of_month' => 20,
                        'start_month' => 1,
                        'debit_sub_account_id' => $expenseSubAccount->id,
                        'credit_sub_account_id' => $cashSubAccount->id,
                        'amount' => 11000,
                        'tax_amount' => 1000,
                    ],
                ],
            ], $user);
        } finally {
            Carbon::setTestNow();
        }

        $todo->refresh();

        $this->assertSame(Todo::STATUS_COMPLETED, $todo->status);
        $this->assertSame('2026-08-01 14:00:00', $todo->completed_at?->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('recurring_transaction_plans', [
            'business_unit_id' => $businessUnit->id,
            'name' => 'サーバー代',
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'business_ratio' => 80,
        ]);
        $this->assertDatabaseHas('recurring_transaction_plans', [
            'business_unit_id' => $businessUnit->id,
            'name' => '顧問契約',
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'start_month' => 1,
        ]);
        $this->assertDatabaseHas('transactions', [
            'fiscal_year_id' => $fiscalYear->id,
            'description' => 'サーバー代',
            'is_planned' => true,
        ]);
        $this->assertDatabaseHas('transactions', [
            'fiscal_year_id' => $fiscalYear->id,
            'description' => '顧問契約',
            'is_planned' => true,
        ]);
    }

    #[Test]
    public function executeは定期収入を複数登録してtodoを完了する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '定期収入登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);
        $depositSubAccount = $businessUnit->getSubAccountByName('その他の預金', 'その他の預金');
        $salesSubAccount = $businessUnit->getSubAccountByName('売上高', '売上高');
        $withholdingSubAccount = $businessUnit->getSubAccountByName('事業主貸', RecurringTransactionPlan::WITHHOLDING_SUB_ACCOUNT_NAME);
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'fiscal_year_id' => $fiscalYear->id,
            'todo_type' => Todo::TODO_TYPE_WIZARD_RECURRING_INCOMES,
            'status' => Todo::STATUS_PENDING,
        ]);

        Carbon::setTestNow('2026-08-01 14:30:00');

        try {
            app(RecurringIncomeTodoHandler::class)->execute($todo, [
                'plans' => [
                    [
                        'name' => '月次売上',
                        'interval' => 'monthly',
                        'day_of_month' => 10,
                        'debit_sub_account_id' => $depositSubAccount->id,
                        'credit_sub_account_id' => $salesSubAccount->id,
                        'amount' => 11000,
                        'tax_amount' => 1000,
                        'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_SALES_10,
                    ],
                    [
                        'name' => '源泉付き報酬',
                        'interval' => 'monthly',
                        'day_of_month' => 25,
                        'debit_sub_account_id' => $depositSubAccount->id,
                        'credit_sub_account_id' => $salesSubAccount->id,
                        'amount' => 100000,
                        'tax_amount' => 0,
                        'is_withholding' => true,
                        'withholding_tax_amount' => 10210,
                        'withholding_sub_account_id' => $withholdingSubAccount->id,
                    ],
                ],
            ], $user);
        } finally {
            Carbon::setTestNow();
        }

        $todo->refresh();

        $this->assertSame(Todo::STATUS_COMPLETED, $todo->status);
        $this->assertSame('2026-08-01 14:30:00', $todo->completed_at?->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('recurring_transaction_plans', [
            'business_unit_id' => $businessUnit->id,
            'name' => '月次売上',
            'type' => RecurringTransactionPlan::TYPE_INCOME,
        ]);
        $this->assertDatabaseHas('recurring_transaction_plans', [
            'business_unit_id' => $businessUnit->id,
            'name' => '源泉付き報酬',
            'type' => RecurringTransactionPlan::TYPE_INCOME,
            'is_withholding' => true,
            'withholding_tax_amount' => 10210,
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 89790,
            'sub_account_id' => $depositSubAccount->id,
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 10210,
            'sub_account_id' => $withholdingSubAccount->id,
        ]);
    }

    #[Test]
    public function executeは権限のないユーザーを拒否する(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '定期取引権限テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'fiscal_year_id' => $fiscalYear->id,
            'todo_type' => Todo::TODO_TYPE_WIZARD_RECURRING_EXPENSES,
            'status' => Todo::STATUS_PENDING,
        ]);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('この Todo を実行する権限がありません。');

        app(RecurringExpenseTodoHandler::class)->execute($todo, [
            'plans' => [],
        ], $otherUser);
    }
}
