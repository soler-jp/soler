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

        $unit = $user->selectedBusinessUnit;
        $revenueAccount = $unit->getAccountByName('売上高');
        $cashAccount = $unit->accounts()->where('name', '現金')->first();

        Livewire::actingAs($user)
            ->test('dashboard-revenue-input')
            ->set('date', '2025-04-01')
            ->set('gross_amount', '10000')
            ->set('description', '通常売上テスト')
            ->set('revenueAccountId', $revenueAccount->id)
            ->set('receiptAccountId', $cashAccount->id)
            ->set('selectedReceiptId', 'Account:' . $cashAccount->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('売上を登録しました');

        $this->assertDatabaseHas('journal_entries', [
            'account_id' => $revenueAccount->id,
            'type' => 'credit',
            'amount' => 10000,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'account_id' => $cashAccount->id,
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

        $revenueAccount = $unit->getAccountByName('売上高');
        $cashAccount = $unit->getAccountByName('現金');
        $withheldTaxAccount = $unit->getAccountByName('事業主貸');
        $withheldTaxSubAccount = $unit->getSubAccountByName('事業主貸', '源泉徴収');

        Livewire::actingAs($user)
            ->test('dashboard-revenue-input')
            ->set('date', '2025-04-01')
            ->set('gross_amount', '10000')
            ->set('withholding', true)
            ->set('holding_amount', '1021') // 源泉徴収額          
            ->set('description', '源泉あり売上テスト')
            ->set('revenueAccountId', $revenueAccount->id)
            ->set('receiptAccountId', $cashAccount->id)
            ->set('withheldTaxAccountId', $withheldTaxAccount->id)
            ->set('withheldTaxSubAccountId', $withheldTaxSubAccount->id)
            ->set('selectedReceiptId', 'Account:' . $cashAccount->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('売上を登録しました');

        $this->assertDatabaseHas('journal_entries', [
            'account_id' => $revenueAccount->id,
            'type' => 'credit',
            'amount' => 10000,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'account_id' => $cashAccount->id,
            'type' => 'debit',
            'amount' => 8979,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'account_id' => $withheldTaxAccount->id,
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

        $revenueAccount = $unit->getAccountByName('売上高');
        $withheldAccount = $unit->getAccountByName('事業主貸');
        $withheldSubAccount = $unit->getSubAccountByName('事業主貸', '源泉徴収');

        Livewire::actingAs($user)
            ->test('dashboard-revenue-input')
            ->assertSet('revenueAccountId', $revenueAccount->id)
            ->assertSet('withheldTaxAccountId', $withheldAccount->id)
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

        $cashAccount = $user->selectedBusinessUnit->getAccountByName('現金');

        Livewire::actingAs($user)
            ->test('dashboard-revenue-input')
            ->set('date', 'yy') // 未入力
            ->set('gross_amount', 0) // 0円
            ->set('revenueAccountId', 999999) // 存在しないID
            ->set('selectedReceiptId', 'Account:' . $cashAccount->id) // 入金先は現金
            ->call('save')
            ->assertHasErrors([
                'date',
                'gross_amount' => 'min',
                'revenueAccountId' => 'exists',
            ]);
    }

    #[Test]
    public function 入金先としてsubAccountがある場合はsubAccountが表示され_ない場合はaccountが表示される()
    {
        $user = User::factory()->create();

        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
            'type' => 'general',
            'is_taxable_supplier' => false,
            'is_tax_exclusive' => false,
        ]);

        $cashAccount = $unit->getAccountByName('現金');
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
        $this->assertContainsOnlyInstancesOf(\App\Models\Account::class, $groups['cash']);
        $this->assertTrue(collect($groups['cash'])->contains(fn($c) => $c->id === $cashAccount->id));

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
        $cashAccount = $user->selectedBusinessUnit->getAccountByName('現金');

        Livewire::actingAs($user)
            ->test('dashboard-revenue-input')
            ->set('date', 'invalid-date') // required, date
            ->set('gross_amount', null) // required, integer, min:1
            ->set('revenueAccountId', null) // required
            ->set('receiptAccountId', null) // required
            ->set('selectedReceiptId', 'Account:' . $cashAccount->id) // 入金先は現金
            ->call('save')
            ->assertHasErrors([
                'date',
                'gross_amount',
                'revenueAccountId' => ['required'],
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

        $revenueAccount = $unit->getAccountByName('売上高');
        $cashAccount = $unit->getAccountByName('現金');

        Livewire::actingAs($user)
            ->test('dashboard-revenue-input')
            ->set('date', '2025-04-01')
            ->set('gross_amount', 12000)
            ->set('description', '現金売上テスト')
            ->set('selectedReceiptId', 'Account:' . $cashAccount->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('売上を登録しました');

        $this->assertDatabaseHas('journal_entries', [
            'account_id' => $cashAccount->id,
            'type' => 'debit',
            'amount' => 12000,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'account_id' => $revenueAccount->id,
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

        $revenueAccount = $unit->getAccountByName('売上高');
        $bankAccount = $unit->getAccountByName('その他の預金');
        $bankSubAccount = $bankAccount->subAccounts()->create([
            'name' => 'テスト銀行',
        ]);

        Livewire::actingAs($user)
            ->test('dashboard-revenue-input')
            ->set('date', '2025-04-02')
            ->set('gross_amount', 15000)
            ->set('description', '銀行売上テスト')
            ->set('selectedReceiptId', 'SubAccount:' . $bankSubAccount->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('売上を登録しました');

        $this->assertDatabaseHas('journal_entries', [
            'account_id' => $bankAccount->id,
            'sub_account_id' => $bankSubAccount->id,
            'type' => 'debit',
            'amount' => 15000,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'account_id' => $revenueAccount->id,
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
            ->set('selectedReceiptId', null) // 入金先未選択
            ->call('save')
            ->assertHasErrors(['selectedReceiptId' => 'required']);
    }
}
