<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\RecurringTransactionPlan;
use App\Models\Todo;
use App\Models\User;
use App\Services\TodoService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TodoServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function actorが事業体にアクセスできない場合はtodo登録を拒否する(): void
    {
        [$user, $businessUnit, $fiscalYear] = $this->createBusinessUnitWithFiscalYear();
        $otherUser = User::factory()->create();

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('この事業体の Todo を登録する権限がありません。');

        (new TodoService)->register(
            $businessUnit,
            '権限なし登録',
            $otherUser,
            $fiscalYear,
        );
    }

    #[Test]
    public function manual_todoを登録できる(): void
    {
        [$user, $businessUnit, $fiscalYear] = $this->createBusinessUnitWithFiscalYear();

        $todo = (new TodoService)->register(
            $businessUnit,
            '月次入力を確認する',
            $user,
            $fiscalYear,
            '売上と経費の突合を行う',
            Carbon::parse('2026-08-20'),
            Todo::PRIORITY_HIGH,
        );

        $this->assertSame(Todo::STATUS_PENDING, $todo->status);
        $this->assertSame(Todo::SOURCE_TYPE_MANUAL, $todo->source_type);
        $this->assertNull($todo->todo_type);
        $this->assertTrue($todo->businessUnit->is($businessUnit));
        $this->assertTrue($todo->fiscalYear->is($fiscalYear));
        $this->assertNull($todo->source);

        $this->assertDatabaseHas('todos', [
            'id' => $todo->id,
            'business_unit_id' => $businessUnit->id,
            'fiscal_year_id' => $fiscalYear->id,
            'source_type' => Todo::SOURCE_TYPE_MANUAL,
            'todo_type' => null,
            'title' => '月次入力を確認する',
            'priority' => Todo::PRIORITY_HIGH,
            'status' => Todo::STATUS_PENDING,
        ]);
    }

    #[Test]
    public function handler付きtodoを登録できる(): void
    {
        [$user, $businessUnit, $fiscalYear] = $this->createBusinessUnitWithFiscalYear();

        $todo = (new TodoService)->register(
            $businessUnit,
            '銀行口座を登録する',
            $user,
            $fiscalYear,
            todoType: Todo::TODO_TYPE_WIZARD_BANK_ACCOUNT,
        );

        $this->assertSame(Todo::TODO_TYPE_WIZARD_BANK_ACCOUNT, $todo->todo_type);
        $this->assertTrue($todo->isExecutable());
    }

    #[Test]
    public function 現金handler付きtodoを登録できる(): void
    {
        [$user, $businessUnit, $fiscalYear] = $this->createBusinessUnitWithFiscalYear();

        $todo = (new TodoService)->register(
            $businessUnit,
            '事業用現金の管理場所を登録する',
            $user,
            $fiscalYear,
            todoType: Todo::TODO_TYPE_WIZARD_CASH_ON_HAND,
        );

        $this->assertSame(Todo::TODO_TYPE_WIZARD_CASH_ON_HAND, $todo->todo_type);
        $this->assertTrue($todo->isExecutable());
    }

    #[Test]
    public function 取引先handler付きtodoを登録できる(): void
    {
        [$user, $businessUnit, $fiscalYear] = $this->createBusinessUnitWithFiscalYear();

        $todo = (new TodoService)->register(
            $businessUnit,
            '取引先を登録する',
            $user,
            $fiscalYear,
            todoType: Todo::TODO_TYPE_WIZARD_COUNTERPARTY,
        );

        $this->assertSame(Todo::TODO_TYPE_WIZARD_COUNTERPARTY, $todo->todo_type);
        $this->assertTrue($todo->isExecutable());
    }

    #[Test]
    public function 定期支出handler付きtodoを登録できる(): void
    {
        [$user, $businessUnit, $fiscalYear] = $this->createBusinessUnitWithFiscalYear();

        $todo = (new TodoService)->register(
            $businessUnit,
            '定期支出を登録する',
            $user,
            $fiscalYear,
            todoType: Todo::TODO_TYPE_WIZARD_RECURRING_EXPENSES,
        );

        $this->assertSame(Todo::TODO_TYPE_WIZARD_RECURRING_EXPENSES, $todo->todo_type);
        $this->assertTrue($todo->isExecutable());
    }

    #[Test]
    public function 定期収入handler付きtodoを登録できる(): void
    {
        [$user, $businessUnit, $fiscalYear] = $this->createBusinessUnitWithFiscalYear();

        $todo = (new TodoService)->register(
            $businessUnit,
            '定期収入を登録する',
            $user,
            $fiscalYear,
            todoType: Todo::TODO_TYPE_WIZARD_RECURRING_INCOMES,
        );

        $this->assertSame(Todo::TODO_TYPE_WIZARD_RECURRING_INCOMES, $todo->todo_type);
        $this->assertTrue($todo->isExecutable());
    }

    #[Test]
    public function handler未登録のtodo_typeで登録を拒否する(): void
    {
        [$user, $businessUnit] = $this->createBusinessUnitWithFiscalYear();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('この todo_type に対応する Handler が登録されていません。');

        (new TodoService)->register(
            $businessUnit,
            'handler 未登録',
            $user,
            todoType: 'unregistered_handler',
        );
    }

    #[Test]
    public function recurring_sourceのtodoを登録できる(): void
    {
        [$user, $businessUnit, $fiscalYear] = $this->createBusinessUnitWithFiscalYear();
        $plan = $this->createRecurringExpensePlan($businessUnit, $user, '家賃');

        $todo = (new TodoService)->register(
            $businessUnit,
            '家賃の予定を確認する',
            $user,
            $fiscalYear,
            sourceType: Todo::SOURCE_TYPE_RECURRING,
            sourceModel: $plan,
        );

        $this->assertSame(Todo::SOURCE_TYPE_RECURRING, $todo->source_type);
        $this->assertNotNull($todo->source);
        $this->assertTrue($todo->source->is($plan));
    }

    #[Test]
    public function system_source_typeでsource_modelなしでtodoを登録できる(): void
    {
        [$user, $businessUnit, $fiscalYear] = $this->createBusinessUnitWithFiscalYear();

        $todo = (new TodoService)->register(
            $businessUnit,
            '今月末の銀行残高を確認する',
            $user,
            $fiscalYear,
            sourceType: Todo::SOURCE_TYPE_SYSTEM,
        );

        $this->assertSame(Todo::SOURCE_TYPE_SYSTEM, $todo->source_type);
        $this->assertNull($todo->source_model_type);
        $this->assertNull($todo->source_model_id);
        $this->assertNull($todo->source);
    }

    #[Test]
    public function system_source_typeにsource_modelを渡すと拒否する(): void
    {
        [$user, $businessUnit] = $this->createBusinessUnitWithFiscalYear();
        $plan = $this->createRecurringExpensePlan($businessUnit, $user, 'ガス');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('この source_type では発生源モデルを指定できません。');

        (new TodoService)->register(
            $businessUnit,
            '不正な source 付き system',
            $user,
            sourceType: Todo::SOURCE_TYPE_SYSTEM,
            sourceModel: $plan,
        );
    }

    #[Test]
    public function 未保存のsource_modelを渡すと拒否する(): void
    {
        [$user, $businessUnit] = $this->createBusinessUnitWithFiscalYear();

        $unsavedPlan = new RecurringTransactionPlan([
            'business_unit_id' => $businessUnit->id,
            'name' => '未保存プラン',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'amount' => 1000,
            'tax_amount' => 0,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('発生源モデルは保存済みでなければなりません。');

        (new TodoService)->register(
            $businessUnit,
            '未保存 plan を紐づける',
            $user,
            sourceType: Todo::SOURCE_TYPE_RECURRING,
            sourceModel: $unsavedPlan,
        );
    }

    #[Test]
    public function manual_todoにsource_modelを渡すと拒否する(): void
    {
        [$user, $businessUnit] = $this->createBusinessUnitWithFiscalYear();
        $plan = $this->createRecurringExpensePlan($businessUnit, $user, '水道');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('この source_type では発生源モデルを指定できません。');

        (new TodoService)->register(
            $businessUnit,
            '不正な source 付き manual',
            $user,
            sourceModel: $plan,
        );
    }

    #[Test]
    public function recurring_todoにsource_modelがない場合は拒否する(): void
    {
        [$user, $businessUnit] = $this->createBusinessUnitWithFiscalYear();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('この source_type では発生源モデルが必須です。');

        (new TodoService)->register(
            $businessUnit,
            'source なし recurring',
            $user,
            sourceType: Todo::SOURCE_TYPE_RECURRING,
        );
    }

    #[Test]
    public function 他事業体のsource_modelを渡すと拒否する(): void
    {
        [$user, $businessUnit] = $this->createBusinessUnitWithFiscalYear();
        [$otherUser, $otherBusinessUnit] = $this->createBusinessUnitWithFiscalYear('別事業体');
        $otherPlan = $this->createRecurringExpensePlan($otherBusinessUnit, $otherUser, '外部プラン');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('指定された発生源モデルは対象の事業体に属していません。');

        (new TodoService)->register(
            $businessUnit,
            '他事業体の plan を紐づける',
            $user,
            sourceType: Todo::SOURCE_TYPE_RECURRING,
            sourceModel: $otherPlan,
        );
    }

    #[Test]
    public function 他事業体の会計年度を渡すと登録を拒否する(): void
    {
        [$user, $businessUnit] = $this->createBusinessUnitWithFiscalYear();
        [, , $otherFiscalYear] = $this->createBusinessUnitWithFiscalYear('別会計年度事業体');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('指定された会計年度は対象の事業体に属していません。');

        (new TodoService)->register(
            $businessUnit,
            '他事業体年度の Todo',
            $user,
            $otherFiscalYear,
        );
    }

    #[Test]
    public function completeはtodoを完了状態にする(): void
    {
        [$user, $businessUnit] = $this->createBusinessUnitWithFiscalYear();
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'status' => Todo::STATUS_PENDING,
        ]);

        Carbon::setTestNow('2026-07-31 15:00:00');

        try {
            (new TodoService)->complete($todo, $user);
        } finally {
            Carbon::setTestNow();
        }

        $todo->refresh();

        $this->assertSame(Todo::STATUS_COMPLETED, $todo->status);
        $this->assertSame('2026-07-31 15:00:00', $todo->completed_at?->format('Y-m-d H:i:s'));
        $this->assertNull($todo->dismissed_at);
    }

    #[Test]
    public function actorが事業体にアクセスできない場合はtodo完了を拒否する(): void
    {
        [$user, $businessUnit] = $this->createBusinessUnitWithFiscalYear();
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
        ]);
        $otherUser = User::factory()->create();

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('この Todo を完了する権限がありません。');

        (new TodoService)->complete($todo, $otherUser);
    }

    #[Test]
    public function pending以外のtodoは完了できない(): void
    {
        [$user, $businessUnit] = $this->createBusinessUnitWithFiscalYear();
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'status' => Todo::STATUS_DISMISSED,
            'dismissed_at' => Carbon::parse('2026-07-31 09:00:00'),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('pending の Todo のみ状態変更できます。');

        (new TodoService)->complete($todo, $user);
    }

    #[Test]
    public function dismissはtodoを却下状態にする(): void
    {
        [$user, $businessUnit] = $this->createBusinessUnitWithFiscalYear();
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'status' => Todo::STATUS_PENDING,
        ]);

        Carbon::setTestNow('2026-07-31 16:00:00');

        try {
            (new TodoService)->dismiss($todo, $user);
        } finally {
            Carbon::setTestNow();
        }

        $todo->refresh();

        $this->assertSame(Todo::STATUS_DISMISSED, $todo->status);
        $this->assertSame('2026-07-31 16:00:00', $todo->dismissed_at?->format('Y-m-d H:i:s'));
        $this->assertNull($todo->completed_at);
    }

    #[Test]
    public function actorが事業体にアクセスできない場合はtodo却下を拒否する(): void
    {
        [$user, $businessUnit] = $this->createBusinessUnitWithFiscalYear();
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
        ]);
        $otherUser = User::factory()->create();

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('この Todo を却下する権限がありません。');

        (new TodoService)->dismiss($todo, $otherUser);
    }

    #[Test]
    public function pending以外のtodoは却下できない(): void
    {
        [$user, $businessUnit] = $this->createBusinessUnitWithFiscalYear();
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'status' => Todo::STATUS_COMPLETED,
            'completed_at' => Carbon::parse('2026-07-31 10:00:00'),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('pending の Todo のみ状態変更できます。');

        (new TodoService)->dismiss($todo, $user);
    }

    #[Test]
    public function actorが事業体にアクセスできない場合はpending_todo一覧取得を拒否する(): void
    {
        [$user, $businessUnit] = $this->createBusinessUnitWithFiscalYear();
        $otherUser = User::factory()->create();

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('この事業体の Todo を参照する権限がありません。');

        (new TodoService)->listPending($businessUnit, $otherUser);
    }

    #[Test]
    public function actorが事業体にアクセスできない場合はtodoスキーマ取得を拒否する(): void
    {
        [$user, $businessUnit] = $this->createBusinessUnitWithFiscalYear();
        $otherUser = User::factory()->create();
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'todo_type' => Todo::TODO_TYPE_WIZARD_BANK_ACCOUNT,
        ]);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('この Todo の入力仕様を参照する権限がありません。');

        (new TodoService)->schemaFor($todo, $otherUser);
    }

    #[Test]
    public function schema_forはhandlerの入力スキーマを返す(): void
    {
        [$user, $businessUnit] = $this->createBusinessUnitWithFiscalYear();
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'todo_type' => Todo::TODO_TYPE_WIZARD_BANK_ACCOUNT,
        ]);

        $schema = (new TodoService)->schemaFor($todo, $user);

        $this->assertSame([
            'bank_accounts' => [
                'rules' => ['required', 'array', 'min:1'],
                'label' => '銀行口座',
                'type' => 'array',
                'item_schema' => [
                    'label' => [
                        'rules' => ['required', 'string', 'max:255'],
                        'label' => '銀行名',
                        'type' => 'text',
                    ],
                    'opening_balance' => [
                        'rules' => ['required', 'integer', 'min:0'],
                        'label' => '残高',
                        'type' => 'number',
                    ],
                ],
            ],
        ], $schema);
    }

    #[Test]
    public function schema_forは取引先todoの入力スキーマを返す(): void
    {
        [$user, $businessUnit] = $this->createBusinessUnitWithFiscalYear();
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'todo_type' => Todo::TODO_TYPE_WIZARD_COUNTERPARTY,
        ]);

        $schema = (new TodoService)->schemaFor($todo, $user);

        $this->assertSame([
            'counterparties' => [
                'rules' => ['required', 'array', 'min:1'],
                'label' => '取引先',
                'type' => 'array',
                'item_schema' => [
                    'name' => [
                        'rules' => ['required', 'string', 'max:255'],
                        'label' => '取引先名',
                        'type' => 'text',
                    ],
                    'notes' => [
                        'rules' => ['nullable', 'string'],
                        'label' => 'メモ',
                        'type' => 'textarea',
                    ],
                ],
            ],
        ], $schema);
    }

    #[Test]
    public function schema_forは定期支出todoの入力スキーマを返す(): void
    {
        [$user, $businessUnit] = $this->createBusinessUnitWithFiscalYear();
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'todo_type' => Todo::TODO_TYPE_WIZARD_RECURRING_EXPENSES,
        ]);

        $schema = (new TodoService)->schemaFor($todo, $user);

        $this->assertSame('定期支出', $schema['plans']['label'] ?? null);
        $this->assertArrayNotHasKey('help', $schema['plans']);
        $this->assertCount(7, $schema['plans']['default_items'] ?? []);
        $this->assertSame('家賃', $schema['plans']['default_items'][0]['name'] ?? null);
        $this->assertSame(
            JournalEntry::TAX_TYPE_EXEMPT,
            $schema['plans']['default_items'][0]['tax_type'] ?? null,
        );
        $this->assertSame(
            __('recurring_transaction_plans.todo_card.fields.credit_source'),
            $schema['plans']['item_schema']['credit_sub_account_id']['label'] ?? null,
        );
        $this->assertSame('radio', $schema['plans']['item_schema']['credit_sub_account_id']['type'] ?? null);
        $this->assertSame(
            __('recurring_transaction_plans.todo_card.fields.gross_amount'),
            $schema['plans']['item_schema']['amount']['label'] ?? null,
        );
        $this->assertArrayHasKey('business_ratio', $schema['plans']['item_schema'] ?? []);
        $this->assertSame(
            __('recurring_transaction_plans.todo_card.help.business_ratio'),
            $schema['plans']['item_schema']['business_ratio']['help'] ?? null,
        );
        $this->assertArrayNotHasKey('is_withholding', $schema['plans']['item_schema'] ?? []);
        $this->assertArrayHasKey('start_month', $schema['plans']['item_schema'] ?? []);
        $this->assertArrayHasKey('is_active', $schema['plans']['item_schema'] ?? []);
    }

    #[Test]
    public function schema_forは定期収入todoの入力スキーマを返す(): void
    {
        [$user, $businessUnit] = $this->createBusinessUnitWithFiscalYear();
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'todo_type' => Todo::TODO_TYPE_WIZARD_RECURRING_INCOMES,
        ]);

        $schema = (new TodoService)->schemaFor($todo, $user);

        $this->assertSame('定期収入', $schema['plans']['label'] ?? null);
        $this->assertArrayHasKey('is_withholding', $schema['plans']['item_schema'] ?? []);
        $this->assertArrayNotHasKey('business_ratio', $schema['plans']['item_schema'] ?? []);
        $this->assertArrayHasKey('start_month', $schema['plans']['item_schema'] ?? []);
        $this->assertArrayHasKey('is_active', $schema['plans']['item_schema'] ?? []);
    }

    #[Test]
    public function executeはhandlerを通して銀行口座を登録しtodoを完了する(): void
    {
        [$user, $businessUnit, $fiscalYear] = $this->createBusinessUnitWithFiscalYear();
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'fiscal_year_id' => $fiscalYear->id,
            'todo_type' => Todo::TODO_TYPE_WIZARD_BANK_ACCOUNT,
            'status' => Todo::STATUS_PENDING,
        ]);
        $bankAccount = $businessUnit->getAccountByName('その他の預金');

        Carbon::setTestNow('2026-08-01 12:00:00');

        try {
            $refreshedTodo = (new TodoService)->execute($todo, [
                'bank_accounts' => [
                    ['label' => 'ひかり青空銀行', 'opening_balance' => 120000],
                    ['label' => 'みらい星銀行', 'opening_balance' => 80000],
                ],
            ], $user);
        } finally {
            Carbon::setTestNow();
        }

        $capitalSubAccount = $businessUnit->getSubAccountByName('元入金', '元入金');
        $this->assertSame(Todo::STATUS_COMPLETED, $refreshedTodo->status);
        $this->assertSame('2026-08-01 12:00:00', $refreshedTodo->completed_at?->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('sub_accounts', [
            'account_id' => $bankAccount?->id,
            'name' => 'ひかり青空銀行',
        ]);
        $this->assertDatabaseHas('sub_accounts', [
            'account_id' => $bankAccount?->id,
            'name' => 'みらい星銀行',
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $capitalSubAccount?->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 200000,
        ]);
    }

    #[Test]
    public function executeはhandlerを通して取引先を登録しtodoを完了する(): void
    {
        [$user, $businessUnit] = $this->createBusinessUnitWithFiscalYear();
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'todo_type' => Todo::TODO_TYPE_WIZARD_COUNTERPARTY,
            'status' => Todo::STATUS_PENDING,
        ]);

        Carbon::setTestNow('2026-08-01 12:30:00');

        try {
            $refreshedTodo = (new TodoService)->execute($todo, [
                'counterparties' => [
                    ['name' => '株式会社ソレル', 'notes' => '定期請求あり'],
                    ['name' => '山田商店', 'notes' => null],
                ],
            ], $user);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(Todo::STATUS_COMPLETED, $refreshedTodo->status);
        $this->assertSame('2026-08-01 12:30:00', $refreshedTodo->completed_at?->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('counterparties', [
            'business_unit_id' => $businessUnit->id,
            'name' => '株式会社ソレル',
            'notes' => '定期請求あり',
        ]);
        $this->assertDatabaseHas('counterparties', [
            'business_unit_id' => $businessUnit->id,
            'name' => '山田商店',
            'notes' => null,
        ]);
    }

    #[Test]
    public function executeはhandler未登録のtodo_typeで拒否する(): void
    {
        [$user, $businessUnit] = $this->createBusinessUnitWithFiscalYear();
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'todo_type' => 'unregistered_handler',
            'status' => Todo::STATUS_PENDING,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('この Todo は実行可能な Handler を持ちません: todo_type=unregistered_handler');

        (new TodoService)->execute($todo, [
            'bank_accounts' => [
                ['label' => 'ひかり青空銀行', 'opening_balance' => 120000],
            ],
        ], $user);
    }

    #[Test]
    public function actorが事業体にアクセスできない場合はtodo実行を拒否する(): void
    {
        [$user, $businessUnit, $fiscalYear] = $this->createBusinessUnitWithFiscalYear();
        $otherUser = User::factory()->create();
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'fiscal_year_id' => $fiscalYear->id,
            'todo_type' => Todo::TODO_TYPE_WIZARD_BANK_ACCOUNT,
            'status' => Todo::STATUS_PENDING,
        ]);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('この Todo を実行する権限がありません。');

        (new TodoService)->execute($todo, [
            'bank_accounts' => [
                ['label' => 'ひかり青空銀行', 'opening_balance' => 120000],
            ],
        ], $otherUser);
    }

    #[Test]
    public function executeはhandlerのバリデーション例外を伝播する(): void
    {
        [$user, $businessUnit, $fiscalYear] = $this->createBusinessUnitWithFiscalYear();
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'fiscal_year_id' => $fiscalYear->id,
            'todo_type' => Todo::TODO_TYPE_WIZARD_BANK_ACCOUNT,
            'status' => Todo::STATUS_PENDING,
        ]);

        $this->expectException(ValidationException::class);

        (new TodoService)->execute($todo, [
            'bank_accounts' => [
                ['label' => '', 'opening_balance' => -1],
            ],
        ], $user);
    }

    #[Test]
    public function 他事業体の会計年度を一覧条件に渡すと拒否する(): void
    {
        [$user, $businessUnit] = $this->createBusinessUnitWithFiscalYear();
        [, , $otherFiscalYear] = $this->createBusinessUnitWithFiscalYear('別一覧事業体');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('指定された会計年度は対象の事業体に属していません。');

        (new TodoService)->listPending($businessUnit, $user, $otherFiscalYear);
    }

    #[Test]
    #[Group('mysql')]
    public function list_pendingは年度条件と優先度期日作成順で並び替える(): void
    {
        [$user, $businessUnit, $fiscalYear] = $this->createBusinessUnitWithFiscalYear();
        $otherYear = $businessUnit->createFiscalYear(2027, $user);

        $expectedFirst = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'fiscal_year_id' => $fiscalYear->id,
            'title' => 'high-old',
            'priority' => Todo::PRIORITY_HIGH,
            'status' => Todo::STATUS_PENDING,
            'due_on' => '2026-08-10',
            'created_at' => Carbon::parse('2026-07-31 09:00:00'),
            'updated_at' => Carbon::parse('2026-07-31 09:00:00'),
        ]);
        $expectedSecond = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'fiscal_year_id' => null,
            'title' => 'high-new',
            'priority' => Todo::PRIORITY_HIGH,
            'status' => Todo::STATUS_PENDING,
            'due_on' => '2026-08-10',
            'created_at' => Carbon::parse('2026-07-31 10:00:00'),
            'updated_at' => Carbon::parse('2026-07-31 10:00:00'),
        ]);
        $expectedThird = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'fiscal_year_id' => $fiscalYear->id,
            'title' => 'normal',
            'priority' => Todo::PRIORITY_NORMAL,
            'status' => Todo::STATUS_PENDING,
            'due_on' => '2026-08-01',
            'created_at' => Carbon::parse('2026-07-31 08:00:00'),
            'updated_at' => Carbon::parse('2026-07-31 08:00:00'),
        ]);
        $expectedFourth = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'fiscal_year_id' => null,
            'title' => 'low-null-due',
            'priority' => Todo::PRIORITY_LOW,
            'status' => Todo::STATUS_PENDING,
            'due_on' => null,
            'created_at' => Carbon::parse('2026-07-31 07:00:00'),
            'updated_at' => Carbon::parse('2026-07-31 07:00:00'),
        ]);

        Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'fiscal_year_id' => $otherYear->id,
            'title' => 'other-year',
            'priority' => Todo::PRIORITY_HIGH,
            'status' => Todo::STATUS_PENDING,
        ]);
        Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'fiscal_year_id' => $fiscalYear->id,
            'title' => 'dismissed',
            'priority' => Todo::PRIORITY_HIGH,
            'status' => Todo::STATUS_DISMISSED,
            'dismissed_at' => Carbon::parse('2026-07-31 12:00:00'),
        ]);
        Todo::factory()->create([
            'business_unit_id' => User::factory()->create()->createBusinessUnitWithDefaults(['name' => '別 owner'])->id,
            'title' => 'other-unit',
            'priority' => Todo::PRIORITY_HIGH,
            'status' => Todo::STATUS_PENDING,
        ]);

        $todos = (new TodoService)->listPending($businessUnit, $user, $fiscalYear);

        $this->assertSame(
            [$expectedFirst->id, $expectedSecond->id, $expectedThird->id, $expectedFourth->id],
            $todos->pluck('id')->all(),
        );
    }

    /**
     * @return array{0: User, 1: BusinessUnit, 2: FiscalYear}
     */
    private function createBusinessUnitWithFiscalYear(string $name = 'Todoテスト事業体'): array
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => $name]);

        return [$user, $businessUnit, $businessUnit->createFiscalYear(2026, $user)];
    }

    private function createRecurringExpensePlan(
        BusinessUnit $businessUnit,
        User $actor,
        string $name,
    ): RecurringTransactionPlan {
        $expenseSubAccount = $businessUnit->getSubAccountByName('消耗品費', '消耗品費');
        $cashSubAccount = $businessUnit->getSubAccountByName('現金', '現金');

        return $businessUnit->createRecurringTransactionPlan([
            'name' => $name,
            'interval' => 'monthly',
            'day_of_month' => 10,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $expenseSubAccount->id,
            'credit_sub_account_id' => $cashSubAccount->id,
            'amount' => 1000,
            'tax_amount' => 0,
        ], $actor);
    }
}
