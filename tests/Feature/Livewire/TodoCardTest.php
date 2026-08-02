<?php

namespace Tests\Feature\Livewire;

use App\Livewire\TodoCard;
use App\Livewire\TodoCards\RecurringExpenseCard;
use App\Models\JournalEntry;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
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
    public function recurring_expense_todoは専用コンポーネントでデフォルト固定費と補足文を表示する(): void
    {
        App::setLocale('ja');

        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '定期支出 ToDo 事業体']);
        $fiscalYear = $businessUnit->createFiscalYear(2026, $user);
        $todo = Todo::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'fiscal_year_id' => $fiscalYear->id,
            'title' => '定期支出を登録する',
            'todo_type' => Todo::TODO_TYPE_WIZARD_RECURRING_EXPENSES,
            'status' => Todo::STATUS_PENDING,
        ]);

        Livewire::actingAs($user)
            ->test(RecurringExpenseCard::class, ['todo' => $todo])
            ->assertSee('主な固定費をまとめて登録します。')
            ->assertSee('主な支払い元')
            ->assertSee('支払いの見込み金額(税込)')
            ->assertSee('消費税')
            ->assertSee('頻度')
            ->assertSee('毎月')
            ->assertSee('隔月')
            ->assertSee('毎年')
            ->assertSee('10%')
            ->assertSee('非課税')
            ->assertSee('住宅用として契約している家賃は非課税です。')
            ->assertSee('事業専用として契約している家賃は 10% を選んでください。')
            ->assertSee('国税庁 No.6226 住宅の貸付け')
            ->assertSee('家事按分がある固定費だけ入力してください。')
            ->assertSee('経費計上する割合')
            ->assertSee('プライベートの財布・クレジットで支払い')
            ->assertSee('登録しない')
            ->assertSet('inputs.plans.0.name', '家賃')
            ->assertSet('inputs.plans.0.interval', 'monthly')
            ->assertSet('inputs.plans.0.tax_type_locked', false)
            ->assertSet('inputs.plans.0.tax_type', JournalEntry::TAX_TYPE_EXEMPT)
            ->assertSet('inputs.plans.0.should_register', true)
            ->assertSet('inputs.plans.1.name', '電気代')
            ->assertSet('inputs.plans.1.tax_type', JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10)
            ->assertSet('inputs.plans.3.interval', 'bimonthly')
            ->assertSet('inputs.plans.3.name', '水道代')
            ->assertSet('inputs.plans.6.name', '車検代')
            ->assertSet('inputs.plans.6.interval', 'yearly')
            ->set('inputs.plans.0.should_register', false)
            ->assertSee('登録する');
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
            ->assertSee('継続して取引する相手')
            ->assertSee('取引先名')
            ->assertSee('メモ')
            ->assertSee('取引先を追加')
            ->assertSee('後で追加する場合は、サイドメニューの[取引先]から追加できます。')
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
