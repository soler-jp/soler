<?php

namespace Tests\Feature\Livewire;

use App\Livewire\SetupWizard;
use App\Livewire\SolerUi\TransactionEntry\ExpenseForm\Standard;
use App\Livewire\SolerUi\TransactionEntry\PurchaseForm\Standard as PurchaseStandard;
use App\Models\InitialSetupData;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SetupWizardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 初期状態では仕様どおりのデフォルト値が入っている(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->travelTo('2026-07-31');

        Livewire::test(SetupWizard::class)
            ->assertSet('step', 1)
            ->assertSet('name', '個人事業')
            ->assertSet('year', 2026)
            ->assertSet('opening_context', InitialSetupData::OPENING_CONTEXT_FIRST_YEAR)
            ->assertSet('bank_account_answer', '')
            ->assertSet('is_taxable', false);
    }

    #[Test]
    public function progressから入力済みstepへ直接移動できる(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(SetupWizard::class)
            ->set('name', 'テスト事業')
            ->call('next')
            ->assertSet('step', 2)
            ->set('year', 2026)
            ->call('next')
            ->call('goToStep', 3)
            ->assertSet('step', 3)
            ->call('goToStep', 1)
            ->assertSet('step', 1);
    }

    #[Test]
    public function 未到達のstepへはprogressから直接移動できない(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(SetupWizard::class)
            ->call('goToStep', 6)
            ->assertSet('step', 1)
            ->set('name', 'テスト事業')
            ->call('goToStep', 3)
            ->assertSet('step', 1);
    }

    #[Test]
    public function step1で事業名が空ならバリデーションエラーになる(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(SetupWizard::class)
            ->set('name', '')
            ->call('next')
            ->assertHasErrors(['name']);
    }

    #[Test]
    public function step2で2022以前は選べない(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(SetupWizard::class)
            ->set('step', 2)
            ->set('year', 2022)
            ->call('next')
            ->assertHasErrors(['year']);
    }

    #[Test]
    public function step4では未選択のまま進めない(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(SetupWizard::class)
            ->set('step', 4)
            ->call('next')
            ->assertHasErrors([
                'bank_account_answer',
                'cash_on_hand_answer',
                'fixed_asset_answer',
                'recurring_expense_answer',
                'recurring_income_answer',
                'counterparty_answer',
            ]);
    }

    #[Test]
    public function step4で全て選べば次に進める(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(SetupWizard::class)
            ->set('step', 4)
            ->set('bank_account_answer', InitialSetupData::ANSWER_YES)
            ->set('cash_on_hand_answer', InitialSetupData::ANSWER_NO)
            ->set('fixed_asset_answer', InitialSetupData::ANSWER_NO)
            ->set('recurring_expense_answer', InitialSetupData::ANSWER_YES)
            ->set('recurring_income_answer', InitialSetupData::ANSWER_NO)
            ->set('counterparty_answer', InitialSetupData::ANSWER_YES)
            ->call('next')
            ->assertSet('step', 5);
    }

    #[Test]
    public function 初回セットアップ完了時に年度設定と回答が保存される(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(SetupWizard::class)
            ->set('name', 'テスト事業')
            ->call('next')
            ->set('year', 2026)
            ->call('next')
            ->set('opening_context', InitialSetupData::OPENING_CONTEXT_CARRY_FORWARD)
            ->call('next')
            ->set('bank_account_answer', InitialSetupData::ANSWER_YES)
            ->set('cash_on_hand_answer', InitialSetupData::ANSWER_NO)
            ->set('fixed_asset_answer', InitialSetupData::ANSWER_NO)
            ->set('recurring_expense_answer', InitialSetupData::ANSWER_YES)
            ->set('recurring_income_answer', InitialSetupData::ANSWER_NO)
            ->set('counterparty_answer', InitialSetupData::ANSWER_YES)
            ->call('next')
            ->set('is_taxable', true)
            ->call('next')
            ->call('submit');

        $this->assertDatabaseHas('business_units', [
            'user_id' => $user->id,
            'name' => 'テスト事業',
            'type' => 'general',
        ]);

        $this->assertDatabaseHas('fiscal_years', [
            'year' => 2026,
            'is_taxable' => true,
            'is_tax_exclusive' => false,
            'opening_context' => InitialSetupData::OPENING_CONTEXT_CARRY_FORWARD,
        ]);

        $businessUnitId = $user->fresh()->selectedBusinessUnit->id;

        $this->assertDatabaseHas('initial_setup_data', [
            'business_unit_id' => $businessUnitId,
            'year' => 2026,
            'opening_context' => InitialSetupData::OPENING_CONTEXT_CARRY_FORWARD,
            'is_taxable' => true,
            'bank_account_answer' => InitialSetupData::ANSWER_YES,
            'cash_on_hand_answer' => InitialSetupData::ANSWER_NO,
            'fixed_asset_answer' => InitialSetupData::ANSWER_NO,
            'recurring_expense_answer' => InitialSetupData::ANSWER_YES,
            'recurring_income_answer' => InitialSetupData::ANSWER_NO,
            'counterparty_answer' => InitialSetupData::ANSWER_YES,
        ]);
    }

    #[Test]
    public function solerを始めるを押すとdashboardへリダイレクトされる(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(SetupWizard::class)
            ->set('name', 'テスト事業')
            ->call('next')
            ->set('year', 2026)
            ->call('next')
            ->set('opening_context', InitialSetupData::OPENING_CONTEXT_FIRST_YEAR)
            ->call('next')
            ->set('bank_account_answer', InitialSetupData::ANSWER_NO)
            ->set('cash_on_hand_answer', InitialSetupData::ANSWER_NO)
            ->set('fixed_asset_answer', InitialSetupData::ANSWER_NO)
            ->set('recurring_expense_answer', InitialSetupData::ANSWER_NO)
            ->set('recurring_income_answer', InitialSetupData::ANSWER_NO)
            ->set('counterparty_answer', InitialSetupData::ANSWER_NO)
            ->call('next')
            ->set('is_taxable', false)
            ->call('next')
            ->call('submit')
            ->assertRedirect(route('dashboard'));
    }

    #[Test]
    public function 初回セットアップ完了後はdashboardを開ける(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(SetupWizard::class)
            ->set('name', 'テスト事業')
            ->call('next')
            ->set('year', 2026)
            ->call('next')
            ->set('opening_context', InitialSetupData::OPENING_CONTEXT_CARRY_FORWARD)
            ->call('next')
            ->set('bank_account_answer', InitialSetupData::ANSWER_YES)
            ->set('cash_on_hand_answer', InitialSetupData::ANSWER_NO)
            ->set('fixed_asset_answer', InitialSetupData::ANSWER_NO)
            ->set('recurring_expense_answer', InitialSetupData::ANSWER_YES)
            ->set('recurring_income_answer', InitialSetupData::ANSWER_NO)
            ->set('counterparty_answer', InitialSetupData::ANSWER_YES)
            ->call('next')
            ->set('is_taxable', false)
            ->call('submit');

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSeeLivewire(Standard::class)
            ->assertSeeLivewire(PurchaseStandard::class)
            ->assertSeeLivewire(\App\Livewire\SolerUi\TransactionEntry\RevenueForm\Standard::class);
    }
}
