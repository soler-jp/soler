<?php

namespace Tests\Feature\TodoHandlers;

use App\Models\Todo;
use App\Models\User;
use App\TodoHandlers\BankAccountTodoHandler;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BankAccountTodoHandlerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function validateは銀行口座行をtrimし0円を許可する(): void
    {
        $todo = Todo::factory()->make([
            'todo_type' => Todo::TODO_TYPE_WIZARD_BANK_ACCOUNT,
        ]);

        $validated = app(BankAccountTodoHandler::class)->validate($todo, [
            'bank_accounts' => [
                ['label' => '  ひかり青空銀行  ', 'opening_balance' => 0],
                ['label' => '  みらい星銀行  ', 'opening_balance' => 120000],
            ],
        ]);

        $this->assertSame([
            'bank_accounts' => [
                ['label' => 'ひかり青空銀行', 'opening_balance' => 0],
                ['label' => 'みらい星銀行', 'opening_balance' => 120000],
            ],
        ], $validated);
    }

    #[Test]
    public function validateは不正な入力を拒否する(): void
    {
        $todo = Todo::factory()->make([
            'todo_type' => Todo::TODO_TYPE_WIZARD_BANK_ACCOUNT,
        ]);

        $this->expectException(ValidationException::class);

        app(BankAccountTodoHandler::class)->validate($todo, [
            'bank_accounts' => [
                ['label' => '', 'opening_balance' => -1],
            ],
        ]);
    }

    #[Test]
    public function executeは会計年度なしtodoを拒否する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '銀行口座登録テスト']);
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'fiscal_year_id' => null,
            'todo_type' => Todo::TODO_TYPE_WIZARD_BANK_ACCOUNT,
            'status' => Todo::STATUS_PENDING,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('会計年度に紐づかない Todo では銀行口座を登録できません。');

        app(BankAccountTodoHandler::class)->execute($todo, [
            'bank_accounts' => [
                ['label' => 'ひかり青空銀行', 'opening_balance' => 1000],
            ],
        ], $user);
    }

    #[Test]
    public function executeは銀行口座を登録してtodoを完了する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '銀行口座登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'fiscal_year_id' => $fiscalYear->id,
            'todo_type' => Todo::TODO_TYPE_WIZARD_BANK_ACCOUNT,
            'status' => Todo::STATUS_PENDING,
        ]);

        Carbon::setTestNow('2026-08-01 13:00:00');

        try {
            app(BankAccountTodoHandler::class)->execute($todo, [
                'bank_accounts' => [
                    ['label' => 'ひかり青空銀行', 'opening_balance' => 120000],
                    ['label' => 'みらい星銀行', 'opening_balance' => 80000],
                ],
            ], $user);
        } finally {
            Carbon::setTestNow();
        }

        $todo->refresh();
        $bankAccount = $businessUnit->getAccountByName('その他の預金');

        $this->assertSame(Todo::STATUS_COMPLETED, $todo->status);
        $this->assertSame('2026-08-01 13:00:00', $todo->completed_at?->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('sub_accounts', [
            'account_id' => $bankAccount?->id,
            'name' => 'ひかり青空銀行',
        ]);
        $this->assertDatabaseHas('sub_accounts', [
            'account_id' => $bankAccount?->id,
            'name' => 'みらい星銀行',
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
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '銀行口座登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'fiscal_year_id' => $fiscalYear->id,
            'todo_type' => Todo::TODO_TYPE_WIZARD_BANK_ACCOUNT,
            'status' => Todo::STATUS_PENDING,
        ]);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('この Todo を実行する権限がありません。');

        app(BankAccountTodoHandler::class)->execute($todo, [
            'bank_accounts' => [
                ['label' => 'ひかり青空銀行', 'opening_balance' => 1000],
            ],
        ], $otherUser);
    }
}
