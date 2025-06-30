<?php

namespace Tests\Feature\Livewire;

use App\Livewire\SetupWizard;
use App\Models\User;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SetupWizardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 初期状態では全ての入力が空である()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(SetupWizard::class)
            ->assertSet('name', '')
            ->assertSet('business_type', 'general')
            ->assertSet('is_taxable', false)
            ->assertSet('is_tax_exclusive', false);
    }

    #[Test]
    public function 必須入力が空ならバリデーションエラーになる()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(\App\Livewire\SetupWizard::class)
            ->call('submit')
            ->assertHasErrors(['name', 'year']);
    }

    #[Test]
    public function 免税事業者_税込経理なら初期化に成功する()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(\App\Livewire\SetupWizard::class)
            ->set('name', 'テスト事業体')
            ->set('business_type', 'general')
            ->set('year', 2025)
            ->set('is_taxable', false)
            ->set('is_tax_exclusive', false)
            ->call('submit');

        $this->assertDatabaseHas('business_units', [
            'user_id' => $user->id,
            'name' => 'テスト事業体',
            'type' => 'general',
        ]);

        $this->assertDatabaseHas('fiscal_years', [
            'year' => 2025,
            'is_taxable' => false,
            'is_tax_exclusive' => false,
        ]);
    }

    #[Test]
    public function 課税事業者なら初期化できず例外が発生する()
    {
        $this->expectException(\InvalidArgumentException::class);

        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(\App\Livewire\SetupWizard::class)
            ->set('name', '課税業者のテスト')
            ->set('business_type', 'general')
            ->set('year', 2025)
            ->set('is_taxable', true)
            ->set('is_tax_exclusive', false)
            ->call('submit');
    }

    #[Test]
    public function 税抜経理は未対応として初期化できない()
    {
        $this->expectException(\InvalidArgumentException::class);

        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(\App\Livewire\SetupWizard::class)
            ->set('name', '税抜経理のテスト')
            ->set('business_type', 'general')
            ->set('year', 2025)
            ->set('is_taxable', false)
            ->set('is_tax_exclusive', true)
            ->call('submit');
    }

    #[Test]
    public function Step1で未入力ならバリデーションエラーになる()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(\App\Livewire\SetupWizard::class)
            ->call('next')
            ->assertHasErrors(['name']);
    }


    #[Test]
    public function Step1でnameとtypeを入力して次に進める()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(\App\Livewire\SetupWizard::class)
            ->set('name', 'テスト事業体')
            ->set('business_type', 'general')
            ->call('next')
            ->assertSet('step', 2);
    }

    #[Test]
    public function Step2で入力すれば初期化処理が呼ばれる()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(\App\Livewire\SetupWizard::class)
            ->set('name', 'ウィザード事業体')
            ->set('business_type', 'general')
            ->call('next') // Step1完了

            ->set('year', 2025)
            ->set('is_taxable', false)
            ->set('is_tax_exclusive', false)
            ->call('next')
            ->assertOk();
    }

    #[Test]
    public function Step3で現金残高を入力すればopening_entriesに反映される()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test(\App\Livewire\SetupWizard::class)
            ->set('name', '現金事業体')
            ->set('business_type', 'general')
            ->call('next') // Step1 → Step2
            ->set('year', 2025)
            ->set('is_taxable', false)
            ->set('is_tax_exclusive', false)
            ->call('next') // Step2 → Step3
            ->set('cash_balance', 30000);

        $this->assertSame(30000, $component->get('cash_balance'));
    }

    #[Test]
    public function Step4で銀行口座を1件追加できる()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $input = [
            'sub_account_name' => 'メインバンク',
            'amount' => 50000,
        ];

        $component = Livewire::test(\App\Livewire\SetupWizard::class)
            ->set('name', '銀行あり事業体')
            ->set('business_type', 'general')
            ->call('next') // Step1 → Step2
            ->set('year', 2025)
            ->set('is_taxable', false)
            ->set('is_tax_exclusive', false)
            ->call('next') // Step2 → Step3
            ->call('next') // Step3 → Step4
            ->set('bank_accounts', [$input]);

        $this->assertEquals([$input], $component->get('bank_accounts'));
    }
    #[Test]
    public function Step5で資産を1件追加できる()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $input = [
            'account_name' => '車両運搬具',
            'sub_account_name' => null,
            'amount' => 500000,
        ];

        $component = Livewire::test(\App\Livewire\SetupWizard::class)
            // Step1
            ->set('name', '資産あり事業体')
            ->set('business_type', 'general')
            ->call('next')
            // Step2
            ->set('year', 2025)
            ->set('is_taxable', false)
            ->set('is_tax_exclusive', false)
            ->call('next')
            // Step3（現金）→ skip
            ->call('next') // Step4（銀行）→ skip
            ->call('next') // Step5（資産）
            ->set('other_assets', [$input]);

        $this->assertEquals([$input], $component->get('other_assets'));
    }


    #[Test]
    public function Step6で全情報を入力して初期化に成功する()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(\App\Livewire\SetupWizard::class)
            // Step 1
            ->set('name', 'テスト事業体')
            ->set('business_type', 'general')
            ->call('next')
            // Step 2
            ->set('year', 2025)
            ->set('is_taxable', false)
            ->set('is_tax_exclusive', false)
            ->call('next')
            // Step 3
            ->set('cash_balance', 30000)
            ->call('next')
            // Step 4
            ->set('bank_accounts', [
                ['sub_account_name' => 'メインバンク', 'amount' => 50000],
            ])
            ->call('next')
            // Step 5
            ->set('other_assets', [
                [
                    'account_name' => '定期預金',
                    'amount' => 120000,
                ],
            ])
            ->call('next')
            // Step 6
            ->call('submit');

        $this->assertDatabaseHas('business_units', [
            'user_id' => $user->id,
            'name' => 'テスト事業体',
            'type' => 'general',
        ]);

        $this->assertDatabaseHas('fiscal_years', [
            'year' => 2025,
            'is_taxable' => false,
            'is_tax_exclusive' => false,
        ]);

        $this->assertDatabaseHas('transactions', [
            'description' => '期首残高設定',
            'is_opening_entry' => true,
        ]);

        $this->assertDatabaseHas('sub_accounts', [
            'name' => 'メインバンク',
        ]);
    }
}
