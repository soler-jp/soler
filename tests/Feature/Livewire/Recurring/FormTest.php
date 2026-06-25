<?php

namespace Tests\Feature\Livewire\Recurring;

use App\Livewire\Recurring\Form;
use App\Models\User;
use App\Setup\Initializers\GeneralBusinessInitializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FormTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 定期支出を登録できる()
    {
        $user = User::factory()->create();

        $initializer = new GeneralBusinessInitializer;
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

        Livewire::actingAs($user)
            ->test(Form::class)
            ->set('form.name', 'サーバー代')
            ->set('form.debit_sub_account_id', $debit->id)
            ->set('form.credit_sub_account_id', $credit->id)
            ->set('form.amount', 1100)
            ->set('form.tax_amount', 0)
            ->set('form.tax_type', null)
            ->set('form.interval', 'monthly')
            ->set('form.day_of_month', 1)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('recurring_transaction_plans', [
            'business_unit_id' => $unit->id,
            'name' => 'サーバー代',
            'debit_sub_account_id' => $debit->id,
            'credit_sub_account_id' => $credit->id,
            'amount' => 1100,
            'interval' => 'monthly',
        ]);

        // Transactionデータも登録されることを確認
        $this->assertDatabaseHas('transactions', [
            'fiscal_year_id' => $unit->currentFiscalYear->id,
            'description' => 'サーバー代',
            'is_opening_entry' => false,
            'is_planned' => true,
        ]);
    }

    #[Test]
    public function 隔月支払いで「偶数月」を設定して登録ができる()
    {
        $user = User::factory()->create();

        $initializer = new GeneralBusinessInitializer;
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

        Livewire::actingAs($user)
            ->test(Form::class)
            ->set('form.name', '隔月支払い')
            ->set('form.debit_sub_account_id', $debit->id)
            ->set('form.credit_sub_account_id', $credit->id)
            ->set('form.amount', 2200)
            ->set('form.tax_amount', 0)
            ->set('form.tax_type', null)
            ->set('form.interval', 'bimonthly')
            ->set('form.day_of_month', 1)
            ->set('form.start_month_type', 'even') // 偶数月を選択
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('recurring_transaction_plans', [
            'business_unit_id' => $unit->id,
            'name' => '隔月支払い',
            'debit_sub_account_id' => $debit->id,
            'credit_sub_account_id' => $credit->id,
            'amount' => 2200,
            'interval' => 'bimonthly',
        ]);
    }

    #[Test]
    public function 他ユーザー事業体の補助科目は定期支出登録に使えない()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $initializer = new GeneralBusinessInitializer;
        $unit = $initializer->initialize($user, [
            'name' => '自分の事業体',
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => false,
            'is_tax_exclusive' => false,
            'cash_balance' => null,
            'bank_accounts' => [],
            'fixed_assets' => [],
            'recurring_templates' => [],
        ]);
        $otherUnit = $initializer->initialize($otherUser, [
            'name' => '他人の事業体',
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => false,
            'is_tax_exclusive' => false,
            'cash_balance' => null,
            'bank_accounts' => [],
            'fixed_assets' => [],
            'recurring_templates' => [],
        ]);

        $ownCredit = $unit->getAccountByName('現金')->subAccounts()->first();
        $foreignDebit = $otherUnit->getAccountByName('消耗品費')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Form::class)
            ->set('form.name', '不正な定期支出')
            ->set('form.debit_sub_account_id', $foreignDebit->id)
            ->set('form.credit_sub_account_id', $ownCredit->id)
            ->set('form.amount', 1100)
            ->set('form.tax_amount', 0)
            ->set('form.tax_type', null)
            ->set('form.interval', 'monthly')
            ->set('form.day_of_month', 1)
            ->call('save')
            ->assertHasErrors(['form.debit_sub_account_id']);

        $this->assertDatabaseMissing('recurring_transaction_plans', [
            'business_unit_id' => $unit->id,
            'name' => '不正な定期支出',
        ]);
    }
}
