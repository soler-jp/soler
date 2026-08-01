<?php

namespace Tests\Feature\TodoHandlers;

use App\Models\Todo;
use App\Models\User;
use App\TodoHandlers\CashOnHandTodoHandler;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CashOnHandTodoHandlerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function validateは現金行をtrimして正規化する(): void
    {
        $todo = Todo::factory()->make([
            'todo_type' => Todo::TODO_TYPE_WIZARD_CASH_ON_HAND,
        ]);

        $validated = app(CashOnHandTodoHandler::class)->validate($todo, [
            'cash_accounts' => [
                ['label' => '  レジ現金  ', 'opening_balance' => 0],
                ['label' => ' 金庫 ', 'opening_balance' => 120000],
            ],
        ]);

        $this->assertSame([
            'cash_accounts' => [
                ['label' => 'レジ現金', 'opening_balance' => 0],
                ['label' => '金庫', 'opening_balance' => 120000],
            ],
        ], $validated);
    }

    #[Test]
    public function validateは不正な入力を拒否する(): void
    {
        $todo = Todo::factory()->make([
            'todo_type' => Todo::TODO_TYPE_WIZARD_CASH_ON_HAND,
        ]);

        $this->expectException(ValidationException::class);

        app(CashOnHandTodoHandler::class)->validate($todo, [
            'cash_accounts' => [
                ['label' => '', 'opening_balance' => -1],
            ],
        ]);
    }

    #[Test]
    public function validateは空白のみの表示名を同じ文言で拒否する(): void
    {
        $todo = Todo::factory()->make([
            'todo_type' => Todo::TODO_TYPE_WIZARD_CASH_ON_HAND,
        ]);

        try {
            app(CashOnHandTodoHandler::class)->validate($todo, [
                'cash_accounts' => [
                    ['label' => '   ', 'opening_balance' => 0],
                ],
            ]);
            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['現金の表示名を入力してください。'],
                $exception->errors()['cash_accounts.0.label'] ?? [],
            );
        }
    }

    #[Test]
    public function executeは会計年度なしtodoを拒否する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '事業用現金登録テスト']);
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'fiscal_year_id' => null,
            'todo_type' => Todo::TODO_TYPE_WIZARD_CASH_ON_HAND,
            'status' => Todo::STATUS_PENDING,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('会計年度に紐づかない Todo では事業用現金を登録できません。');

        app(CashOnHandTodoHandler::class)->execute($todo, [
            'cash_accounts' => [
                ['label' => 'レジ現金', 'opening_balance' => 1000],
            ],
        ], $user);
    }

    #[Test]
    public function executeは事業用現金を登録してtodoを完了する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '事業用現金登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'fiscal_year_id' => $fiscalYear->id,
            'todo_type' => Todo::TODO_TYPE_WIZARD_CASH_ON_HAND,
            'status' => Todo::STATUS_PENDING,
        ]);

        Carbon::setTestNow('2026-08-01 13:00:00');

        try {
            app(CashOnHandTodoHandler::class)->execute($todo, [
                'cash_accounts' => [
                    ['label' => 'レジ現金', 'opening_balance' => 120000],
                    ['label' => '金庫', 'opening_balance' => 80000],
                ],
            ], $user);
        } finally {
            Carbon::setTestNow();
        }

        $todo->refresh();
        $cashAccount = $businessUnit->getAccountByName('現金');

        $this->assertSame(Todo::STATUS_COMPLETED, $todo->status);
        $this->assertSame('2026-08-01 13:00:00', $todo->completed_at?->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('sub_accounts', [
            'account_id' => $cashAccount?->id,
            'name' => 'レジ現金',
        ]);
        $this->assertDatabaseHas('sub_accounts', [
            'account_id' => $cashAccount?->id,
            'name' => '金庫',
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'type' => 'debit',
            'net_amount' => 120000,
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'type' => 'debit',
            'net_amount' => 80000,
        ]);
    }

    #[Test]
    public function executeは権限のないユーザーを拒否する(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '事業用現金登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'fiscal_year_id' => $fiscalYear->id,
            'todo_type' => Todo::TODO_TYPE_WIZARD_CASH_ON_HAND,
            'status' => Todo::STATUS_PENDING,
        ]);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('この Todo を実行する権限がありません。');

        app(CashOnHandTodoHandler::class)->execute($todo, [
            'cash_accounts' => [
                ['label' => 'レジ現金', 'opening_balance' => 1000],
            ],
        ], $otherUser);
    }
}
