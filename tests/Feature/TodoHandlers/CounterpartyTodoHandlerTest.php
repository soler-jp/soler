<?php

namespace Tests\Feature\TodoHandlers;

use App\Models\Counterparty;
use App\Models\Todo;
use App\Models\User;
use App\TodoHandlers\CounterpartyTodoHandler;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CounterpartyTodoHandlerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function validateは取引先行をtrimして正規化する(): void
    {
        $todo = Todo::factory()->make([
            'todo_type' => Todo::TODO_TYPE_WIZARD_COUNTERPARTY,
        ]);

        $validated = app(CounterpartyTodoHandler::class)->validate($todo, [
            'counterparties' => [
                ['name' => '  株式会社ソレル  ', 'notes' => '  定期請求あり  '],
                ['name' => '  山田商店  ', 'notes' => '   '],
            ],
        ]);

        $this->assertSame([
            'counterparties' => [
                ['name' => '株式会社ソレル', 'notes' => '定期請求あり'],
                ['name' => '山田商店', 'notes' => null],
            ],
        ], $validated);
    }

    #[Test]
    public function validateは不正な入力を拒否する(): void
    {
        $todo = Todo::factory()->make([
            'todo_type' => Todo::TODO_TYPE_WIZARD_COUNTERPARTY,
        ]);

        $this->expectException(ValidationException::class);

        app(CounterpartyTodoHandler::class)->validate($todo, [
            'counterparties' => [
                ['name' => ''],
            ],
        ]);
    }

    #[Test]
    public function validateは空白のみの取引先名を同じ文言で拒否する(): void
    {
        $todo = Todo::factory()->make([
            'todo_type' => Todo::TODO_TYPE_WIZARD_COUNTERPARTY,
        ]);

        try {
            app(CounterpartyTodoHandler::class)->validate($todo, [
                'counterparties' => [
                    ['name' => '   '],
                ],
            ]);
            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['取引先名を入力してください。'],
                $exception->errors()['counterparties.0.name'] ?? [],
            );
        }
    }

    #[Test]
    public function validateは同名取引先の2件目以降に重複エラーを付ける(): void
    {
        $todo = Todo::factory()->make([
            'todo_type' => Todo::TODO_TYPE_WIZARD_COUNTERPARTY,
        ]);

        try {
            app(CounterpartyTodoHandler::class)->validate($todo, [
                'counterparties' => [
                    ['name' => '株式会社ソレル', 'notes' => null],
                    ['name' => '  株式会社ソレル  ', 'notes' => '重複'],
                ],
            ]);
            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['同じ名前が入力内で重複しています。'],
                $exception->errors()['counterparties.1.name'] ?? [],
            );
        }
    }

    #[Test]
    public function executeは取引先を登録してtodoを完了する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '取引先登録テスト']);
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'fiscal_year_id' => null,
            'todo_type' => Todo::TODO_TYPE_WIZARD_COUNTERPARTY,
            'status' => Todo::STATUS_PENDING,
        ]);

        Carbon::setTestNow('2026-08-01 14:00:00');

        try {
            app(CounterpartyTodoHandler::class)->execute($todo, [
                'counterparties' => [
                    ['name' => '株式会社ソレル', 'notes' => '定期請求あり'],
                    ['name' => '山田商店', 'notes' => null],
                ],
            ], $user);
        } finally {
            Carbon::setTestNow();
        }

        $todo->refresh();
        $counterparties = Counterparty::query()
            ->where('business_unit_id', $businessUnit->id)
            ->orderBy('name')
            ->get();

        $this->assertSame(Todo::STATUS_COMPLETED, $todo->status);
        $this->assertSame('2026-08-01 14:00:00', $todo->completed_at?->format('Y-m-d H:i:s'));
        $this->assertSame(['山田商店', '株式会社ソレル'], $counterparties->pluck('name')->all());
        $this->assertSame([null, '定期請求あり'], $counterparties->pluck('notes')->all());
    }

    #[Test]
    public function executeは同名の既存取引先があると該当行にエラーを返しtodoを完了しない(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '取引先重複テスト']);
        Counterparty::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'name' => '株式会社ソレル',
        ]);
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'todo_type' => Todo::TODO_TYPE_WIZARD_COUNTERPARTY,
            'status' => Todo::STATUS_PENDING,
        ]);

        try {
            app(CounterpartyTodoHandler::class)->execute($todo, [
                'counterparties' => [
                    ['name' => '株式会社ソレル', 'notes' => null],
                    ['name' => '山田商店', 'notes' => null],
                ],
            ], $user);
            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['取引先「株式会社ソレル」はすでに登録されています。'],
                $exception->errors()['counterparties.0.name'] ?? [],
            );
        } finally {
            $this->assertSame(Todo::STATUS_PENDING, $todo->fresh()->status);
        }
    }

    #[Test]
    public function executeは権限のないユーザーを拒否する(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '取引先登録テスト']);
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'todo_type' => Todo::TODO_TYPE_WIZARD_COUNTERPARTY,
            'status' => Todo::STATUS_PENDING,
        ]);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('この Todo を実行する権限がありません。');

        app(CounterpartyTodoHandler::class)->execute($todo, [
            'counterparties' => [
                ['name' => '株式会社ソレル', 'notes' => null],
            ],
        ], $otherUser);
    }
}
