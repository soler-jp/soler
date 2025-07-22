<?php

namespace Tests\Feature\Livewire;

use App\Livewire\DashboardExpenseInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;

class DashboardExpenseInputTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 経費入力フォームがダッシュボードに表示される()
    {
        $user = User::factory()->create();
        $initializer = new \App\Setup\Initializers\GeneralBusinessInitializer();
        $initializer->initialize($user, [
            'name' => 'テスト事業体',
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => false,
            'is_tax_exclusive' => false,
            'cash_balance' => null,
            'bank_accounts' => [],
            'fixed_assets' => [],
            'recurring_templates' => [],
        ]);

        $this->actingAs($user);

        $response = $this->get(route('dashboard'));

        $response->assertStatus(200);
        $response->assertSeeLivewire('dashboard-expense-input');
    }

    #[Test]
    public function 経費を正しく入力すると仕訳が登録される()
    {
        $user = User::factory()->create();

        $initializer = new \App\Setup\Initializers\GeneralBusinessInitializer();
        $unit = $initializer->initialize($user, [
            'name' => 'テスト事業体',
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => false,
            'is_tax_exclusive' => false,
            'cash_balance' => null,
            'bank_accounts' => [],
            'fixed_assets' => [],
            'recurring_templates' => [],
        ]);

        $debit = $unit->getAccountByName('消耗品費')->subAccounts()->first();
        $credit = $unit->getAccountByName('現金')->subAccounts()->first();

        $this->actingAs($user);

        Livewire::actingAs($user)
            ->test('dashboard-expense-input')
            ->set('date', '2025-05-10')
            ->set('description', '文房具購入')
            ->set('amount', 1500)
            ->set('debit_sub_account_id', $debit->id)
            ->set('credit_sub_account_id', $credit->id)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $debit->id,
            'type' => 'debit',
            'amount' => 1500,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $credit->id,
            'type' => 'credit',
            'amount' => 1500,
        ]);
    }

    #[Test]
    public function 日付が未入力だとバリデーションエラーになる()
    {
        $user = User::factory()->create();

        $initializer = new \App\Setup\Initializers\GeneralBusinessInitializer();
        $unit = $initializer->initialize($user, [
            'name' => 'テスト事業体',
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => false,
            'is_tax_exclusive' => false,
            'cash_balance' => null,
            'bank_accounts' => [],
            'fixed_assets' => [],
            'recurring_templates' => [],
        ]);

        $this->actingAs($user);

        $debit = $unit->getAccountByName('旅費交通費')->subAccounts()->first();
        $credit = $unit->getAccountByName('現金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test('dashboard-expense-input')
            ->set('date', '')
            ->set('description', '交通費')
            ->set('amount', 1000)
            ->set('debit_sub_account_id', $debit->id)
            ->set('credit_sub_account_id', $credit->id)
            ->call('submit')
            ->assertHasErrors(['date' => 'required']);
    }

    #[Test]
    public function 摘要が未入力だとバリデーションエラーになる()
    {
        $user = User::factory()->create();

        $initializer = new \App\Setup\Initializers\GeneralBusinessInitializer();
        $unit = $initializer->initialize($user, [
            'name' => 'テスト事業体',
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => false,
            'is_tax_exclusive' => false,
            'cash_balance' => null,
            'bank_accounts' => [],
            'fixed_assets' => [],
            'recurring_templates' => [],
        ]);

        $this->actingAs($user);

        $debit = $unit->getAccountByName('旅費交通費')->subAccounts()->first();
        $credit = $unit->getAccountByName('現金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test('dashboard-expense-input')
            ->set('date', '2025-05-10')
            ->set('description', '')
            ->set('amount', 1000)
            ->set('debit_sub_account_id', $debit->id)
            ->set('credit_sub_account_id', $credit->id)
            ->call('submit')
            ->assertHasErrors(['description' => 'required']);
    }


    #[Test]
    public function 金額が未入力だとバリデーションエラーになる()
    {
        $user = User::factory()->create();

        $initializer = new \App\Setup\Initializers\GeneralBusinessInitializer();
        $unit = $initializer->initialize($user, [
            'name' => 'テスト事業体',
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => false,
            'is_tax_exclusive' => false,
            'cash_balance' => null,
            'bank_accounts' => [],
            'fixed_assets' => [],
            'recurring_templates' => [],
        ]);

        $this->actingAs($user);

        $debit = $unit->getAccountByName('旅費交通費')->subAccounts()->first();
        $credit = $unit->getAccountByName('現金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test('dashboard-expense-input')
            ->set('date', '2025-05-10')
            ->set('description', '通信費')
            ->set('amount', null)
            ->set('debit_sub_account_id', $debit->id)
            ->set('credit_sub_account_id', $credit->id)
            ->call('submit')
            ->assertHasErrors(['amount' => 'required']);
    }

    #[Test]
    public function debit_account_idが未選択だとバリデーションエラーになる()
    {
        $user = User::factory()->create();

        $initializer = new \App\Setup\Initializers\GeneralBusinessInitializer();
        $unit = $initializer->initialize($user, [
            'name' => 'テスト事業体',
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => false,
            'is_tax_exclusive' => false,
            'cash_balance' => null,
            'bank_accounts' => [],
            'fixed_assets' => [],
            'recurring_templates' => [],
        ]);

        $this->actingAs($user);

        $credit = $unit->getSubAccountByName('現金', 'レジ現金');

        Livewire::actingAs($user)
            ->test('dashboard-expense-input')
            ->set('date', '2025-05-10')
            ->set('description', '水道光熱費')
            ->set('amount', 3000)
            ->set('debit_sub_account_id', null)
            ->set('credit_sub_account_id', $credit->id)
            ->call('submit')
            ->assertHasErrors(['debit_sub_account_id' => 'required']);
    }

    #[Test]
    public function credit_account_idが未選択だとバリデーションエラーになる()
    {
        $user = User::factory()->create();

        $initializer = new \App\Setup\Initializers\GeneralBusinessInitializer();
        $unit = $initializer->initialize($user, [
            'name' => 'テスト事業体',
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => false,
            'is_tax_exclusive' => false,
            'cash_balance' => null,
            'bank_accounts' => [],
            'fixed_assets' => [],
            'recurring_templates' => [],
        ]);

        $this->actingAs($user);

        $expenseSubAccount = $unit->accounts()->where('type', 'expense')->first()->subAccounts()->first();

        Livewire::actingAs($user)
            ->test('dashboard-expense-input')
            ->set('date', '2025-05-10')
            ->set('description', '備品購入')
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
        $unit = (new \App\Setup\Initializers\GeneralBusinessInitializer())->initialize($user, [
            'name' => 'テスト事業体',
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => false,
            'is_tax_exclusive' => false,
            'cash_balance' => null,
            'bank_accounts' => [],
            'fixed_assets' => [],
            'recurring_templates' => [],
        ]);

        $this->actingAs($user);

        $debit = $unit->getAccountByName('通信費')->subAccounts()->first();
        $credit = $unit->getAccountByName('現金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test('dashboard-expense-input')
            ->set('date', '2025-05-10')
            ->set('description', '交通費')
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
        $unit = (new \App\Setup\Initializers\GeneralBusinessInitializer())->initialize($user, [
            'name' => 'テスト事業体',
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => false,
            'is_tax_exclusive' => false,
            'cash_balance' => null,
            'bank_accounts' => [],
            'fixed_assets' => [],
            'recurring_templates' => [],
        ]);

        $this->actingAs($user);

        Livewire::actingAs($user)
            ->test('dashboard-expense-input')
            ->set('date', '2025-05-10')
            ->set('description', '通信費')
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
        $unit = (new \App\Setup\Initializers\GeneralBusinessInitializer())->initialize($user, [
            'name' => 'テスト事業体',
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => false,
            'is_tax_exclusive' => false,
            'cash_balance' => null,
            'bank_accounts' => [],
            'fixed_assets' => [],
            'recurring_templates' => [],
        ]);

        $this->actingAs($user);

        $expense = $unit->accounts()->where('type', 'expense')->first()->subAccounts()->first();
        $credit = $unit->accounts()->where('name', '現金')->first()->subAccounts()->first();

        Livewire::actingAs($user)
            ->test('dashboard-expense-input')
            ->set('date', '2025-05-10')
            ->set('description', '備品購入')
            ->set('amount', 500)
            ->set('debit_sub_account_id', $expense->id)
            ->set('credit_sub_account_id', $credit->id)
            ->call('submit')
            ->assertSet('description', '')
            ->assertSet('amount', null)
            ->assertSet('debit_sub_account_id', null)
            ->assertSet('credit_sub_account_id', null);
    }


    #[Test]
    public function 登録後に確認メッセージが表示される()
    {
        $user = User::factory()->create();
        $unit = (new \App\Setup\Initializers\GeneralBusinessInitializer())->initialize($user, [
            'name' => 'テスト事業体',
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => false,
            'is_tax_exclusive' => false,
            'cash_balance' => null,
            'bank_accounts' => [],
            'fixed_assets' => [],
            'recurring_templates' => [],
        ]);

        $this->actingAs($user);

        $expense = $unit->accounts()->where('type', 'expense')->first()->subAccounts()->first();
        $credit = $unit->accounts()->where('name', '現金')->first()->subAccounts()->first();

        Livewire::actingAs($user)
            ->test('dashboard-expense-input')
            ->set('date', '2025-05-10')
            ->set('description', '消耗品購入')
            ->set('amount', 800)
            ->set('debit_sub_account_id', $expense->id)
            ->set('credit_sub_account_id', $credit->id)
            ->call('submit')
            ->assertSee('経費を登録しました');
    }
}
