<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\FiscalYear;
use App\Models\RecurringTransactionPlan;
use App\Models\Todo;
use App\Models\User;
use App\Services\TodoService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
        $this->assertTrue($todo->businessUnit->is($businessUnit));
        $this->assertTrue($todo->fiscalYear->is($fiscalYear));
        $this->assertNull($todo->source);

        $this->assertDatabaseHas('todos', [
            'id' => $todo->id,
            'business_unit_id' => $businessUnit->id,
            'fiscal_year_id' => $fiscalYear->id,
            'source_type' => Todo::SOURCE_TYPE_MANUAL,
            'title' => '月次入力を確認する',
            'priority' => Todo::PRIORITY_HIGH,
            'status' => Todo::STATUS_PENDING,
        ]);
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
