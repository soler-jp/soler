<?php

namespace Tests\Feature\Livewire\Recurring;

use App\Livewire\Recurring\IncomeForm;
use App\Models\Counterparty;
use App\Models\RecurringTransactionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IncomeFormTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 定期収入プランを登録できる(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '定期収入フォーム']);
        $unit->createFiscalYear(2026, $user);

        $counterparty = Counterparty::factory()->create([
            'business_unit_id' => $unit->id,
            'name' => 'Aスポーツクラブ',
        ]);

        $depositSubAccount = $unit->getSubAccountByName('その他の預金', 'その他の預金');

        $this->actingAs($user);

        Livewire::test(IncomeForm::class)
            ->set('form.counterparty_id', $counterparty->id)
            ->set('form.name', 'インストラクター業務委託')
            ->set('form.interval', 'monthly')
            ->set('form.debit_sub_account_id', $depositSubAccount->id)
            ->set('form.gross_amount', '110000')
            ->set('form.tax_option', '10')
            ->set('form.is_withholding', true)
            ->set('form.withholding_tax_amount', '10210')
            ->call('submit')
            ->assertSet('confirming', true)
            ->assertSee('Aスポーツクラブ')
            ->assertSee('毎月 1日')
            ->assertSee('110,000円')
            ->assertSee('10,210円')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee(__('recurring_income_form.messages.created'));

        $plan = RecurringTransactionPlan::query()->firstOrFail();

        $this->assertSame(RecurringTransactionPlan::TYPE_INCOME, $plan->type);
        $this->assertSame($counterparty->id, $plan->counterparty_id);
        $this->assertSame('monthly', $plan->interval);
        $this->assertSame(1, $plan->day_of_month);
        $this->assertSame($depositSubAccount->id, $plan->debit_sub_account_id);
        $this->assertSame(100000, $plan->amount);
        $this->assertSame(10000, $plan->tax_amount);
        $this->assertTrue($plan->is_withholding);
        $this->assertSame(10210, $plan->withholding_tax_amount);
        $this->assertGreaterThan(0, $plan->transactions()->count());
    }

    #[Test]
    public function 毎年を選んだ場合は予定月日を登録できる(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '年払い定期収入フォーム']);
        $unit->createFiscalYear(2026, $user);

        $counterparty = Counterparty::factory()->create([
            'business_unit_id' => $unit->id,
            'name' => 'B株式会社',
        ]);

        $depositSubAccount = $unit->getSubAccountByName('その他の預金', 'その他の預金');

        $this->actingAs($user);

        Livewire::test(IncomeForm::class)
            ->set('form.counterparty_id', $counterparty->id)
            ->set('form.name', 'HP保守費用')
            ->set('form.interval', 'yearly')
            ->set('form.month_of_year', '4')
            ->set('form.day_of_month', '30')
            ->set('form.debit_sub_account_id', $depositSubAccount->id)
            ->set('form.gross_amount', '264000')
            ->set('form.tax_option', '10')
            ->call('submit')
            ->assertSet('confirming', true)
            ->assertSee('毎年 4/30');
    }

    #[Test]
    public function 相手先は必須で日本語メッセージを表示する(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '相手先必須テスト']);
        $unit->createFiscalYear(2026, $user);

        $depositSubAccount = $unit->getSubAccountByName('その他の預金', 'その他の預金');

        $this->actingAs($user);

        Livewire::test(IncomeForm::class)
            ->set('form.name', 'インストラクター業務委託')
            ->set('form.interval', 'monthly')
            ->set('form.debit_sub_account_id', $depositSubAccount->id)
            ->set('form.gross_amount', '110000')
            ->set('form.tax_option', '10')
            ->call('submit')
            ->assertHasErrors(['form.counterparty_id'])
            ->assertSee('相手先を選択してください。');
    }
}
