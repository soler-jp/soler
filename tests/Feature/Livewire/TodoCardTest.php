<?php

namespace Tests\Feature\Livewire;

use App\Livewire\TodoCard;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TodoCardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function display_only_todoを表示して完了できる(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '表示専用 ToDo 事業体']);
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'title' => '売上の確認',
            'body' => '通帳と帳簿を見比べる',
            'status' => Todo::STATUS_PENDING,
            'todo_type' => null,
        ]);

        Livewire::actingAs($user)
            ->test(TodoCard::class, ['todo' => $todo])
            ->assertSee('売上の確認')
            ->assertSee('通帳と帳簿を見比べる')
            ->call('complete')
            ->assertRedirect(route('dashboard'));

        $todo->refresh();

        $this->assertSame(Todo::STATUS_COMPLETED, $todo->status);
        $this->assertNotNull($todo->completed_at);
    }

    #[Test]
    public function executable_todoは汎用フォームを表示する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => 'フォーム表示事業体']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'fiscal_year_id' => $fiscalYear->id,
            'title' => '銀行口座を登録する',
            'todo_type' => Todo::TODO_TYPE_WIZARD_BANK_ACCOUNT,
            'status' => Todo::STATUS_PENDING,
        ]);

        Livewire::actingAs($user)
            ->test(TodoCard::class, ['todo' => $todo])
            ->assertSee('銀行口座を登録する')
            ->assertSee('銀行名')
            ->assertSee('その年の期首残高')
            ->assertSee('行を追加');
    }

    #[Test]
    public function executable_todoのsubmitでhandlerが実行される(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '取引先登録事業体']);
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'title' => '取引先を登録する',
            'todo_type' => Todo::TODO_TYPE_WIZARD_COUNTERPARTY,
            'status' => Todo::STATUS_PENDING,
        ]);

        Livewire::actingAs($user)
            ->test(TodoCard::class, ['todo' => $todo])
            ->set('inputs.counterparties.0.name', '株式会社サンプル')
            ->set('inputs.counterparties.0.notes', '毎月請求する相手')
            ->call('submit')
            ->assertRedirect(route('dashboard'));

        $todo->refresh();

        $this->assertSame(Todo::STATUS_COMPLETED, $todo->status);
        $this->assertDatabaseHas('counterparties', [
            'business_unit_id' => $businessUnit->id,
            'name' => '株式会社サンプル',
            'notes' => '毎月請求する相手',
        ]);
    }
}
