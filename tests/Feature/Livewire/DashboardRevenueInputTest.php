<?php

namespace Tests\Feature\Livewire;

use Tests\TestCase;
use Livewire\Livewire;
use App\Models\User;
use App\Setup\Initializers\GeneralBusinessInitializer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;

class DashboardRevenueInputTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function 通常の売上が登録できる()
    {
        $user = User::factory()->create();

        $initializer = new GeneralBusinessInitializer();
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

        $revenue = $unit->getAccountByName('売上高');
        $revenueSub = $revenue->subAccounts()->first();
        $cash = $unit->getAccountByName('現金');
        $cashSub = $cash->subAccounts()->first();

        Livewire::actingAs($user)
            ->test('dashboard-revenue-input')
            ->set('date', '2025-04-01')
            ->set('gross_amount', 10000)
            ->set('description', '通常売上テスト')
            ->set('revenueSubAccountId', $revenueSub->id)
            ->set('receiptSubAccountId', $cashSub->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('売上を登録しました');

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $revenueSub->id,
            'type' => 'credit',
            'amount' => 10000,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $cashSub->id,
            'type' => 'debit',
            'amount' => 10000,
        ]);
    }

    #[Test]
    public function 源泉徴収ありの売上が即時入金で登録できる()
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

        $revenueSubAccount = $unit->getAccountByName('売上高')->subAccounts()->first();
        $cashSubAccount = $unit->getAccountByName('現金')->subAccounts()->first();
        $withheldTaxSubAccount = $unit->getSubAccountByName('事業主貸', '源泉徴収');

        Livewire::actingAs($user)
            ->test('dashboard-revenue-input')
            ->set('date', '2025-04-01')
            ->set('gross_amount', '10000')
            ->set('withholding', true)
            ->set('holding_amount', '1021') // 源泉徴収額          
            ->set('description', '源泉あり売上テスト')
            ->set('revenueSubAccountId', $revenueSubAccount->id)
            ->set('receiptSubAccountId', $cashSubAccount->id)
            ->set('withheldTaxSubAccountId', $withheldTaxSubAccount->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('売上を登録しました');

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $revenueSubAccount->id,
            'type' => 'credit',
            'amount' => 10000,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $cashSubAccount->id,
            'type' => 'debit',
            'amount' => 8979,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $withheldTaxSubAccount->id,
            'type' => 'debit',
            'amount' => 1021,
        ]);
    }

    #[Test]
    public function mount時に売上高と源泉徴収の科目を自動取得できる()
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

        $revenueSubAccount = $unit->getAccountByName('売上高')->subAccounts()->first();
        $withheldSubAccount = $unit->getSubAccountByName('事業主貸', '源泉徴収');

        Livewire::actingAs($user)
            ->test('dashboard-revenue-input')
            ->assertSet('revenueSubAccountId', $revenueSubAccount->id)
            ->assertSet('withheldTaxSubAccountId', $withheldSubAccount->id);
    }


    #[Test]
    public function 入力が不正な場合はバリデーションエラーになる()
    {
        $user = User::factory()->create();

        $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
            'type' => 'general',
            'is_taxable_supplier' => false,
            'is_tax_exclusive' => false,
        ]);

        $cashSubAccount = $user->selectedBusinessUnit->getSubAccountByName('現金', '現金');

        Livewire::actingAs($user)
            ->test('dashboard-revenue-input')
            ->set('date', 'yy') // 未入力
            ->set('gross_amount', 0) // 0円
            ->set('revenueSubAccountId', 999999) // 存在しないID
            ->set('receiptSubAccountId', $cashSubAccount->id) // 入金先は現金
            ->call('save')
            ->assertHasErrors([
                'date',
                'gross_amount' => 'min',
                'revenueSubAccountId' => 'exists',
            ]);
    }

    #[Test]
    public function 入金先としてsubAccountが表示される()
    {
        $user = User::factory()->create();

        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
            'type' => 'general',
            'is_taxable_supplier' => false,
            'is_tax_exclusive' => false,
        ]);

        $cashAccount = $unit->getAccountByName('現金');
        $cashSubAccount = $cashAccount->subAccounts()->first();

        $bankAccount = $unit->getAccountByName('その他の預金');

        $bankSubAccount_1 = $bankAccount->createSubAccount([
            'name' => 'テスト銀行1',
        ]);
        $bankSubAccount_2 = $bankAccount->createSubAccount([
            'name' => 'テスト銀行2',
        ]);

        $component = Livewire::actingAs($user)
            ->test('dashboard-revenue-input');

        $groups = $component->instance()->receiptGroups;

        // 現金（SubAccountなし → Accountで表示）
        $this->assertContainsOnlyInstancesOf(\App\Models\SubAccount::class, $groups['cash']);
        $this->assertTrue(collect($groups['cash'])->contains(fn($c) => $c->id === $cashSubAccount->id));

        // その他の預金（SubAccountが2つ → SubAccountで表示）
        $this->assertContainsOnlyInstancesOf(\App\Models\SubAccount::class, $groups['bank']);
        $this->assertTrue(collect($groups['bank'])->contains(fn($c) => $c->id === $bankSubAccount_1->id));
        $this->assertTrue(collect($groups['bank'])->contains(fn($c) => $c->id === $bankSubAccount_2->id));

        // 銀行本体は含まれない
        $this->assertFalse(collect($groups['bank'])->contains(fn($c) => $c instanceof \App\Models\Account && $c->id === $bankAccount->id));
    }

    #[Test]
    public function 源泉徴収ありにチェックすると金額入力欄が表示される()
    {
        $user = User::factory()->create();

        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
            'type' => 'general',
            'is_taxable_supplier' => false,
            'is_tax_exclusive' => false,
        ]);

        Livewire::actingAs($user)
            ->test('dashboard-revenue-input')
            ->set('withholding', true)
            ->assertSee('源泉徴収額');
    }



    #[Test]
    public function 必須入力が不足していると全てのバリデーションエラーが表示される()
    {
        $user = User::factory()->create();
        $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
            'type' => 'general',
            'is_taxable_supplier' => false,
            'is_tax_exclusive' => false,
        ]);
        $cashSubAccount = $user->selectedBusinessUnit->getAccountByName('現金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test('dashboard-revenue-input')
            ->set('date', 'invalid-date') // required, date
            ->set('gross_amount', null) // required, integer, min:1
            ->set('revenueSubAccountId', null) // required
            ->set('receiptSubAccountId', $cashSubAccount->id) // 入金先は現金
            ->call('save')
            ->assertHasErrors([
                'date',
                'gross_amount',
                'revenueSubAccountId' => ['required'],
            ]);
    }


    #[Test]
    public function 現金勘定に入金された売上が登録できる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
            'type' => 'general',
            'is_taxable_supplier' => false,
            'is_tax_exclusive' => false,
        ]);

        $fy = $unit->createFiscalYear(2025);
        $unit->setCurrentFiscalYear($fy);

        $revenueSubAccount = $unit->getSubAccountByName('売上高', '売上高');
        $cashSubAccount = $unit->getSubAccountByName('現金', '現金');

        Livewire::actingAs($user)
            ->test('dashboard-revenue-input')
            ->set('date', '2025-04-01')
            ->set('gross_amount', 12000)
            ->set('description', '現金売上テスト')
            ->set('receiptSubAccountId', $cashSubAccount->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('売上を登録しました');

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $cashSubAccount->id,
            'type' => 'debit',
            'amount' => 12000,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $revenueSubAccount->id,
            'type' => 'credit',
            'amount' => 12000,
        ]);
    }

    #[Test]
    public function 銀行サブアカウントに入金された売上が登録できる()
    {
        $user = User::factory()->create();

        $initializer = new \App\Setup\Initializers\GeneralBusinessInitializer();
        $unit = $initializer->initialize($user, [
            'name' => 'テスト事業体',
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => false,
            'is_tax_exclusive' => false,
        ]);

        $revenueSubAccount = $unit->getSubAccountByName('売上高', '売上高');
        $bankAccount = $unit->getAccountByName('その他の預金');
        $bankSubAccount = $bankAccount->subAccounts()->create([
            'name' => 'テスト銀行',
        ]);

        Livewire::actingAs($user)
            ->test('dashboard-revenue-input')
            ->set('date', '2025-04-02')
            ->set('gross_amount', 15000)
            ->set('description', '銀行売上テスト')
            ->set('receiptSubAccountId', $bankSubAccount->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('売上を登録しました');

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $bankSubAccount->id,
            'type' => 'debit',
            'amount' => 15000,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $revenueSubAccount->id,
            'type' => 'credit',
            'amount' => 15000,
        ]);
    }

    #[Test]
    public function 入金先が未選択だとバリデーションエラーが表示される()
    {
        $user = User::factory()->create();

        $initializer = new \App\Setup\Initializers\GeneralBusinessInitializer();
        $unit = $initializer->initialize($user, [
            'name' => 'テスト事業体',
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => false,
            'is_tax_exclusive' => false,
        ]);

        $component = Livewire::actingAs($user)
            ->test('dashboard-revenue-input')
            ->set('date', '2025-04-03')
            ->set('gross_amount', 10000)
            ->set('description', '入金先未選択テスト')
            ->set('receiptSubAccountId', null) // 入金先を未選択
            ->call('save')
            ->assertHasErrors(['receiptSubAccountId' => 'required']);
    }
}
