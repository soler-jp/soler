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
            'body' => "**通帳**と帳簿を見比べる\n\n<script>alert(\"xss\")</script>",
            'status' => Todo::STATUS_PENDING,
            'todo_type' => null,
        ]);

        Livewire::actingAs($user)
            ->test(TodoCard::class, ['todo' => $todo])
            ->assertSee('売上の確認')
            ->assertSee('帳簿を見比べる')
            ->assertSeeHtml('<strong>通帳</strong>')
            ->assertDontSeeHtml('<script>alert("xss")</script>')
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
            'body' => "口座を**まとめて**登録します\n\n- 銀行名\n- 期首残高",
            'todo_type' => Todo::TODO_TYPE_WIZARD_BANK_ACCOUNT,
            'status' => Todo::STATUS_PENDING,
        ]);

        Livewire::actingAs($user)
            ->test(TodoCard::class, ['todo' => $todo])
            ->assertSee('銀行口座を登録する')
            ->assertSeeHtml('<strong>まとめて</strong>')
            ->assertSeeHtml('<li>銀行名</li>')
            ->assertSee('普通預金')
            ->assertSee('銀行名')
            ->assertSee('残高')
            ->assertSee('口座を追加')
            ->assertSee('登録しない')
            ->assertSee('後で追加する場合は、サイドメニューの[銀行口座]から追加できます');
    }

    #[Test]
    public function cash_on_hand_todoは銀行口座カードと揃えた専用フォームを表示する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '事業用現金表示事業体']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'fiscal_year_id' => $fiscalYear->id,
            'title' => '事業用現金の管理場所を登録する',
            'body' => "現金を**まとめて**登録します\n\n- 表示名\n- その年の期首残高",
            'todo_type' => Todo::TODO_TYPE_WIZARD_CASH_ON_HAND,
            'status' => Todo::STATUS_PENDING,
        ]);

        Livewire::actingAs($user)
            ->test(TodoCard::class, ['todo' => $todo])
            ->assertSee('事業用現金の管理場所を登録する')
            ->assertSeeHtml('<strong>まとめて</strong>')
            ->assertSeeHtml('<li>表示名</li>')
            ->assertSee('事業用の現金を管理する場所')
            ->assertSee('場所の名前')
            ->assertSee('金額')
            ->assertSee('現金を管理する場所を追加')
            ->assertSee('登録しない')
            ->assertSee('事業用現金の管理場所を登録する')
            ->assertSee('後で追加する場合は、サイドメニューの[勘定科目]から現金の補助科目を追加できます。');
    }

    #[Test]
    public function bank_account_todoは登録せずに完了できる(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '銀行口座スキップ事業体']);
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
            ->call('complete')
            ->assertRedirect(route('dashboard'));

        $todo->refresh();

        $this->assertSame(Todo::STATUS_COMPLETED, $todo->status);
        $this->assertNotNull($todo->completed_at);
    }

    #[Test]
    public function executable_todoのsubmitでhandlerが実行される(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '取引先登録事業体']);
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'title' => '取引先を登録する',
            'body' => '`note` を確認して登録する',
            'todo_type' => Todo::TODO_TYPE_WIZARD_COUNTERPARTY,
            'status' => Todo::STATUS_PENDING,
        ]);

        Livewire::actingAs($user)
            ->test(TodoCard::class, ['todo' => $todo])
            ->assertSeeHtml('<code>note</code>')
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
