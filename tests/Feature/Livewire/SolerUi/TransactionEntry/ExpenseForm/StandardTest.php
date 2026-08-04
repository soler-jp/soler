<?php

namespace Tests\Feature\Livewire\SolerUi\TransactionEntry\ExpenseForm;

use App\Livewire\SolerUi\TransactionEntry\ExpenseForm\Standard;
use App\Models\JournalEntry;
use App\Models\User;
use App\Setup\Initializers\GeneralBusinessInitializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StandardTest extends TestCase
{
    use RefreshDatabase;

    protected function initializeUnit(User $user, string $name = 'テスト事業体', bool $isTaxable = false)
    {
        return (new GeneralBusinessInitializer)->initialize($user, [
            'name' => $name,
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => $isTaxable,
            'is_tax_exclusive' => false,
            'cash_balance' => null,
            'bank_accounts' => [],
            'fixed_assets' => [],
            'recurring_templates' => [],
        ]);
    }

    #[Test]
    public function 経費入力フォームがダッシュボードに表示される()
    {
        $user = User::factory()->create();
        $this->initializeUnit($user);

        $this->actingAs($user);

        $response = $this->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSeeLivewire(Standard::class);
    }

    #[Test]
    public function 経費を正しく入力すると仕訳が登録される()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $debit = $unit->getAccountByName('消耗品費')->subAccounts()->first();
        $credit = $unit->getAccountByName('現金')->subAccounts()->first();

        $this->actingAs($user);

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0510')
            ->set('note', '文房具購入')
            ->set('amount', 1100)
            ->set('tax_option', Standard::TAX_OPTION_10)
            ->set('debit_sub_account_id', $debit->id)
            ->set('credit_sub_account_id', $credit->id)
            ->call('submit')
            ->assertHasNoErrors();

        // 免税事業者・10%税込1100 → net=1000, tax=100
        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $debit->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 1000,
            'tax_amount' => 100,
            'tax_type' => JournalEntry::TAX_TYPE_DEEMED_TAXABLE_PURCHASES_10,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $credit->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 1100,
        ]);

        $this->assertDatabaseHas('transactions', [
            'date' => '2025-05-10 00:00:00',
            'description' => '文房具購入',
        ]);
    }

    #[Test]
    public function 非課税を選ぶと税額が計上されず税区分がexemptになる()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $debit = $unit->getAccountByName('消耗品費')->subAccounts()->first();
        $credit = $unit->getAccountByName('現金')->subAccounts()->first();

        $this->actingAs($user);

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0510')
            ->set('note', '印紙')
            ->set('amount', 1000)
            ->set('tax_option', Standard::TAX_OPTION_EXEMPT)
            ->set('debit_sub_account_id', $debit->id)
            ->set('credit_sub_account_id', $credit->id)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $debit->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 1000,
            'tax_amount' => 0,
            'tax_type' => JournalEntry::TAX_TYPE_EXEMPT,
        ]);
    }

    #[Test]
    public function 支払い先を入力すると_counterpartyが作成され取引に紐づく()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $debit = $unit->getAccountByName('消耗品費')->subAccounts()->first();
        $credit = $unit->getAccountByName('現金')->subAccounts()->first();

        $this->actingAs($user);

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0510')
            ->set('note', 'ノート')
            ->set('amount', 550)
            ->set('tax_option', Standard::TAX_OPTION_10)
            ->set('counterparty_name', 'ロフト')
            ->set('debit_sub_account_id', $debit->id)
            ->set('credit_sub_account_id', $credit->id)
            ->call('submit')
            ->assertHasNoErrors();

        $counterparty = $unit->counterparties()->where('name', 'ロフト')->firstOrFail();

        $this->assertDatabaseHas('transactions', [
            'counterparty_id' => $counterparty->id,
            'date' => '2025-05-10 00:00:00',
        ]);
    }

    #[Test]
    public function 支払い先未入力なら_counterpartyは作成されない()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $debit = $unit->getAccountByName('消耗品費')->subAccounts()->first();
        $credit = $unit->getAccountByName('現金')->subAccounts()->first();

        $this->actingAs($user);

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0510')
            ->set('note', 'ノート')
            ->set('amount', 550)
            ->set('debit_sub_account_id', $debit->id)
            ->set('credit_sub_account_id', $credit->id)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame(0, $unit->counterparties()->count());
    }

    #[Test]
    #[Group('mysql')]
    public function 貸方勘定科目が想定順で表示される()
    {
        $user = User::factory()->create();
        $this->initializeUnit($user);

        $creditAccountNames = Livewire::actingAs($user)
            ->test(Standard::class)
            ->instance()
            ->creditAccounts
            ->pluck('name')
            ->values()
            ->all();

        $this->assertSame(['現金', '事業主借'], $creditAccountNames);
    }

    #[Test]
    public function standardな補助科目のみがexpandedを開かなくても表示される()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $component = Livewire::actingAs($user)
            ->test(Standard::class);

        $standardNames = collect($component->instance()->expenseAccountsStandard)
            ->flatMap(fn ($account) => $account->subAccounts->pluck('name'))
            ->all();

        $expandedNames = collect($component->instance()->expenseAccountsExpanded)
            ->flatMap(fn ($account) => $account->subAccounts->pluck('name'))
            ->all();

        // 事業体初期化直後は「消耗品費」等が standard、それ以外の既定補助科目は expanded に降格
        $this->assertContains('消耗品費', $standardNames);
        $this->assertNotContains('消耗品費', $expandedNames);
    }

    #[Test]
    public function 未分類の補助科目は専用セクションに分けて表示される()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $component = Livewire::actingAs($user)
            ->test(Standard::class);

        $unclassifiedNames = collect($component->instance()->expenseAccountsUnclassified)
            ->flatMap(fn ($account) => $account->subAccounts->pluck('name'))
            ->all();

        $standardNames = collect($component->instance()->expenseAccountsStandard)
            ->flatMap(fn ($account) => $account->subAccounts->pluck('name'))
            ->all();

        $this->assertContains('未分類', $unclassifiedNames);
        $this->assertNotContains('未分類', $standardNames);
    }

    #[Test]
    public function toggle_expandedで折りたたみ状態が切り替わる()
    {
        $user = User::factory()->create();
        $this->initializeUnit($user);

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->assertSet('showExpanded', false)
            ->call('toggleExpanded')
            ->assertSet('showExpanded', true)
            ->call('toggleExpanded')
            ->assertSet('showExpanded', false);
    }

    #[Test]
    public function 他ユーザー事業体の補助科目は経費登録に使えない()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $unit = $this->initializeUnit($user, '自分の事業体');
        $otherUnit = $this->initializeUnit($otherUser, '他人の事業体');

        $ownCredit = $unit->getAccountByName('現金')->subAccounts()->first();
        $foreignDebit = $otherUnit->getAccountByName('消耗品費')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0510')
            ->set('note', '不正な経費登録')
            ->set('amount', 1500)
            ->set('debit_sub_account_id', $foreignDebit->id)
            ->set('credit_sub_account_id', $ownCredit->id)
            ->call('submit')
            ->assertHasErrors(['debit_sub_account_id']);

        $this->assertDatabaseMissing('journal_entries', [
            'sub_account_id' => $foreignDebit->id,
            'type' => JournalEntry::TYPE_DEBIT,
        ]);
    }

    #[Test]
    public function 日付が未入力だとバリデーションエラーになる()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $this->actingAs($user);

        $debit = $unit->getAccountByName('旅費交通費')->subAccounts()->first();
        $credit = $unit->getAccountByName('現金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '')
            ->set('note', '交通費')
            ->set('amount', 1000)
            ->set('debit_sub_account_id', $debit->id)
            ->set('credit_sub_account_id', $credit->id)
            ->call('submit')
            ->assertHasErrors(['date_input' => 'required']);
    }

    #[Test]
    public function 日付が不正な形式だとバリデーションエラーになる()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $this->actingAs($user);

        $debit = $unit->getAccountByName('旅費交通費')->subAccounts()->first();
        $credit = $unit->getAccountByName('現金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '12345')
            ->set('amount', 1000)
            ->set('debit_sub_account_id', $debit->id)
            ->set('credit_sub_account_id', $credit->id)
            ->call('submit')
            ->assertHasErrors(['date_input' => 'regex']);
    }

    #[Test]
    public function 日付が存在しない日だとバリデーションエラーになる()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $this->actingAs($user);

        $debit = $unit->getAccountByName('旅費交通費')->subAccounts()->first();
        $credit = $unit->getAccountByName('現金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0231')
            ->set('amount', 1000)
            ->set('debit_sub_account_id', $debit->id)
            ->set('credit_sub_account_id', $credit->id)
            ->call('submit')
            ->assertHasErrors(['date_input']);
    }

    #[Test]
    public function 日付が3桁でも登録できる()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $this->actingAs($user);

        $debit = $unit->getAccountByName('旅費交通費')->subAccounts()->first();
        $credit = $unit->getAccountByName('現金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '313')
            ->set('note', '交通費')
            ->set('amount', 1000)
            ->set('tax_option', Standard::TAX_OPTION_EXEMPT)
            ->set('debit_sub_account_id', $debit->id)
            ->set('credit_sub_account_id', $credit->id)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('transactions', [
            'date' => '2025-03-13 00:00:00',
        ]);
    }

    #[Test]
    public function メモが未入力でも登録できる()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $this->actingAs($user);

        $debit = $unit->getAccountByName('旅費交通費')->subAccounts()->first();
        $credit = $unit->getAccountByName('現金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0510')
            ->set('note', '')
            ->set('amount', 1000)
            ->set('tax_option', Standard::TAX_OPTION_EXEMPT)
            ->set('debit_sub_account_id', $debit->id)
            ->set('credit_sub_account_id', $credit->id)
            ->call('submit')
            ->assertHasNoErrors();

        // メモ空白時は補助科目名(または勘定科目 - 補助科目)を description に採用
        $this->assertDatabaseHas('transactions', [
            'date' => '2025-05-10 00:00:00',
            'description' => '旅費交通費',
        ]);
    }

    #[Test]
    public function 金額が未入力だとバリデーションエラーになる()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $this->actingAs($user);

        $debit = $unit->getAccountByName('旅費交通費')->subAccounts()->first();
        $credit = $unit->getAccountByName('現金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0510')
            ->set('note', '通信費')
            ->set('amount', null)
            ->set('debit_sub_account_id', $debit->id)
            ->set('credit_sub_account_id', $credit->id)
            ->call('submit')
            ->assertHasErrors(['amount' => 'required']);
    }

    #[Test]
    public function 金額が100万円を超えるとバリデーションエラーになる()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $this->actingAs($user);

        $debit = $unit->getAccountByName('旅費交通費')->subAccounts()->first();
        $credit = $unit->getAccountByName('現金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0510')
            ->set('amount', 1000001)
            ->set('debit_sub_account_id', $debit->id)
            ->set('credit_sub_account_id', $credit->id)
            ->call('submit')
            ->assertHasErrors(['amount' => 'max']);
    }

    #[Test]
    public function debit_sub_account_idが未選択だとバリデーションエラーになる()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $this->actingAs($user);

        $credit = $unit->getSubAccountByName('現金', '現金');

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0510')
            ->set('note', '水道光熱費')
            ->set('amount', 3000)
            ->set('debit_sub_account_id', null)
            ->set('credit_sub_account_id', $credit->id)
            ->call('submit')
            ->assertHasErrors(['debit_sub_account_id' => 'required']);
    }

    #[Test]
    public function credit_sub_account_idが未選択だとバリデーションエラーになる()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $this->actingAs($user);

        $expenseSubAccount = $unit->accounts()->where('type', 'expense')->first()->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0510')
            ->set('note', '備品購入')
            ->set('amount', 2000)
            ->set('debit_sub_account_id', $expenseSubAccount->id)
            ->set('credit_sub_account_id', null)
            ->call('submit')
            ->assertHasErrors(['credit_sub_account_id' => 'required']);
    }

    #[Test]
    public function 金額が負の値だとバリデーションエラーになる()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $this->actingAs($user);

        $debit = $unit->getAccountByName('通信費')->subAccounts()->first();
        $credit = $unit->getAccountByName('現金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0510')
            ->set('note', '交通費')
            ->set('amount', -100)
            ->set('debit_sub_account_id', $debit->id)
            ->set('credit_sub_account_id', $credit->id)
            ->call('submit')
            ->assertHasErrors(['amount' => 'min']);
    }

    #[Test]
    public function 存在しない勘定科目を指定するとバリデーションエラーになる()
    {
        $user = User::factory()->create();
        $this->initializeUnit($user);

        $this->actingAs($user);

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0510')
            ->set('note', '通信費')
            ->set('amount', 1000)
            ->set('debit_sub_account_id', 999999)
            ->set('credit_sub_account_id', 999998)
            ->call('submit')
            ->assertHasErrors([
                'debit_sub_account_id' => 'exists',
                'credit_sub_account_id' => 'exists',
            ]);
    }

    #[Test]
    public function 登録後にフォームが初期化される()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $this->actingAs($user);

        $expense = $unit->accounts()->where('type', 'expense')->first()->subAccounts()->first();
        $credit = $unit->accounts()->where('name', '現金')->first()->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0510')
            ->set('note', '備品購入')
            ->set('counterparty_name', '無印良品')
            ->set('amount', 500)
            ->set('tax_option', Standard::TAX_OPTION_8)
            ->set('debit_sub_account_id', $expense->id)
            ->set('credit_sub_account_id', $credit->id)
            ->call('submit')
            ->assertSet('note', '')
            ->assertSet('counterparty_name', '')
            ->assertSet('amount', null)
            ->assertSet('debit_sub_account_id', null)
            ->assertSet('credit_sub_account_id', null)
            ->assertSet('tax_option', Standard::TAX_OPTION_10);
    }

    #[Test]
    public function 登録後に確認メッセージが表示される()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $this->actingAs($user);

        $expense = $unit->accounts()->where('type', 'expense')->first()->subAccounts()->first();
        $credit = $unit->accounts()->where('name', '現金')->first()->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0510')
            ->set('note', '消耗品購入')
            ->set('amount', 800)
            ->set('debit_sub_account_id', $expense->id)
            ->set('credit_sub_account_id', $credit->id)
            ->call('submit')
            ->assertSee(__('transactions.expense_form.messages.registered'));
    }

    #[Test]
    public function 事業体が未選択なら経費フォーム表示を拒否する(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->current_business_unit_id);

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->assertForbidden();
    }
}
