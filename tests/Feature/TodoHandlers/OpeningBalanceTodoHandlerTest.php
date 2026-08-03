<?php

namespace Tests\Feature\TodoHandlers;

use App\Models\JournalEntry;
use App\Models\Todo;
use App\Models\User;
use App\TodoHandlers\OpeningBalanceTodoHandler;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpeningBalanceTodoHandlerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function validateは空の自由入力行を除外する(): void
    {
        $todo = Todo::factory()->make([
            'todo_type' => Todo::TODO_TYPE_WIZARD_OPENING_BALANCE,
        ]);

        $validated = app(OpeningBalanceTodoHandler::class)->validate($todo, [
            'asset_accounts' => [
                ['account_name' => '受取手形', 'amount' => 10000],
            ],
            'custom_asset_accounts' => [
                ['account_name' => '  敷金 ', 'amount' => 30000],
                ['account_name' => '   ', 'amount' => null],
            ],
            'liability_accounts' => [
                ['account_name' => '借入金', 'amount' => 5000],
            ],
            'custom_liability_accounts' => [
                ['account_name' => '', 'amount' => ''],
            ],
        ]);

        $this->assertSame([
            'asset_accounts' => [
                ['account_name' => '受取手形', 'amount' => 10000],
            ],
            'custom_asset_accounts' => [
                ['account_name' => '敷金', 'amount' => 30000],
            ],
            'liability_accounts' => [
                ['account_name' => '借入金', 'amount' => 5000],
            ],
            'custom_liability_accounts' => [],
        ], $validated);
    }

    #[Test]
    public function validateは重複する自由入力科目を拒否する(): void
    {
        $todo = Todo::factory()->make([
            'todo_type' => Todo::TODO_TYPE_WIZARD_OPENING_BALANCE,
        ]);

        $this->expectException(ValidationException::class);

        app(OpeningBalanceTodoHandler::class)->validate($todo, [
            'asset_accounts' => [],
            'custom_asset_accounts' => [
                ['account_name' => '敷金', 'amount' => 30000],
                ['account_name' => '敷金', 'amount' => 20000],
            ],
            'liability_accounts' => [],
            'custom_liability_accounts' => [],
        ]);
    }

    #[Test]
    public function validateはその他の資産負債が空配列でも受け付ける(): void
    {
        $todo = Todo::factory()->make([
            'todo_type' => Todo::TODO_TYPE_WIZARD_OPENING_BALANCE,
        ]);

        $validated = app(OpeningBalanceTodoHandler::class)->validate($todo, [
            'asset_accounts' => [
                ['account_name' => '受取手形', 'amount' => 10000],
            ],
            'custom_asset_accounts' => [],
            'liability_accounts' => [
                ['account_name' => '借入金', 'amount' => 5000],
            ],
            'custom_liability_accounts' => [],
        ]);

        $this->assertSame([
            'asset_accounts' => [
                ['account_name' => '受取手形', 'amount' => 10000],
            ],
            'custom_asset_accounts' => [],
            'liability_accounts' => [
                ['account_name' => '借入金', 'amount' => 5000],
            ],
            'custom_liability_accounts' => [],
        ], $validated);
    }

    #[Test]
    public function executeは開始残高を登録してtodoを完了する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '開始残高登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'fiscal_year_id' => $fiscalYear->id,
            'todo_type' => Todo::TODO_TYPE_WIZARD_OPENING_BALANCE,
            'status' => Todo::STATUS_PENDING,
        ]);

        Carbon::setTestNow('2026-08-01 13:00:00');

        try {
            app(OpeningBalanceTodoHandler::class)->execute($todo, [
                'asset_accounts' => [
                    ['account_name' => '売掛金', 'amount' => 120000],
                    ['account_name' => '棚卸資産', 'amount' => 30000],
                ],
                'custom_asset_accounts' => [
                    ['account_name' => '敷金', 'amount' => 50000],
                ],
                'liability_accounts' => [
                    ['account_name' => '借入金', 'amount' => 70000],
                ],
                'custom_liability_accounts' => [
                    ['account_name' => '未払費用', 'amount' => 10000],
                ],
            ], $user);
        } finally {
            Carbon::setTestNow();
        }

        $todo->refresh();

        $this->assertSame(Todo::STATUS_COMPLETED, $todo->status);
        $this->assertSame('2026-08-01 13:00:00', $todo->completed_at?->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('accounts', [
            'business_unit_id' => $businessUnit->id,
            'name' => '敷金',
            'type' => 'asset',
        ]);
        $this->assertDatabaseHas('accounts', [
            'business_unit_id' => $businessUnit->id,
            'name' => '未払費用',
            'type' => 'liability',
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $businessUnit->getSubAccountByName('売掛金', '売掛金')?->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 120000,
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $businessUnit->getSubAccountByName('借入金', '借入金')?->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 70000,
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $businessUnit->getSubAccountByName('元入金', '元入金')?->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 120000,
        ]);
    }

    #[Test]
    public function executeは会計年度なしtodoを拒否する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '開始残高登録テスト']);
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'fiscal_year_id' => null,
            'todo_type' => Todo::TODO_TYPE_WIZARD_OPENING_BALANCE,
            'status' => Todo::STATUS_PENDING,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('会計年度に紐づかない Todo では開始残高を登録できません。');

        app(OpeningBalanceTodoHandler::class)->execute($todo, [
            'asset_accounts' => [],
            'custom_asset_accounts' => [],
            'liability_accounts' => [],
            'custom_liability_accounts' => [],
        ], $user);
    }

    #[Test]
    public function executeは権限のないユーザーを拒否する(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '開始残高登録テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'fiscal_year_id' => $fiscalYear->id,
            'todo_type' => Todo::TODO_TYPE_WIZARD_OPENING_BALANCE,
            'status' => Todo::STATUS_PENDING,
        ]);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('この Todo を実行する権限がありません。');

        app(OpeningBalanceTodoHandler::class)->execute($todo, [
            'asset_accounts' => [],
            'custom_asset_accounts' => [],
            'liability_accounts' => [],
            'custom_liability_accounts' => [],
        ], $otherUser);
    }
}
