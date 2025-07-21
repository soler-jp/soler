<?php

namespace Tests\Feature\Livewire\Recurring;

use App\Livewire\Recurring\Form;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Livewire\Livewire;
use Tests\TestCase;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;

class FormTest extends TestCase
{

    use RefreshDatabase;

    #[Test]
    public function 定期支出を登録できる()
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
}
