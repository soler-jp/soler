<?php

namespace Tests\Feature\Livewire;

use App\Livewire\SetupWizard;
use App\Models\User;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\BusinessUnit;
use App\Models\Account;

class SetupWizardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 初期状態ではnameが入っている()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        // テストの日付を変更
        $this->travelTo('2024-01-01');

        Livewire::test(SetupWizard::class)
            ->assertSet('name', '一般事業所')
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
            ->set('name', '')
            ->set('year', '')
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
            ->set('name', '')
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
            ->set('cash_accounts', [
                ['sub_account_name' => 'レジ現金', 'amount' => 5000],
                ['sub_account_name' => 'イベント用現金', 'amount' => 3000],
            ])
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
                    'sub_account_name' => 'xx銀行',
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

    #[Test]
    public function cashAccount画面で、デフォルトの「レジ現金」が表示される()
    {
        $user = User::factory()->create();
        $bu = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $this->actingAs($user);

        Livewire::test(\App\Livewire\SetupWizard::class)
            ->set('name', 'テスト事業体')
            ->set('business_type', 'general')
            ->call('next') // Step1 → Step2
            ->set('year', 2025)
            ->set('is_taxable', false)
            ->set('is_tax_exclusive', false)
            ->call('next') // Step2 → Step3
            ->assertSet('cash_accounts.0.sub_account_name', 'レジ現金');
    }

    #[Test]
    public function cashAccountに入力した内容がOpeningEntryとして渡される()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(\App\Livewire\SetupWizard::class)
            ->set('name', 'テスト事業所')
            ->set('business_type', 'general')
            ->call('next') // step1 → 2
            ->set('year', 2025)
            ->set('is_taxable', false)
            ->set('is_tax_exclusive', false)
            ->call('next') // step2 → 3
            ->set('cash_accounts', [
                ['sub_account_name' => 'レジ現金', 'amount' => 5000],
                ['sub_account_name' => 'イベント用現金', 'amount' => 3000],
            ])
            ->call('next') // step3 → 4
            ->call('next') // step4 → 5
            ->call('next') // step5 → 6
            ->call('submit');

        $this->assertDatabaseHas('transactions', [
            'description' => '期首残高設定',
            'is_opening_entry' => true,
        ]);

        $user->refresh();

        $bu = $user->selectedBusinessUnit;

        $cashSubAccount = $bu->getSubAccountByName('現金', 'レジ現金');
        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $cashSubAccount->id,
            'amount' => 5000, // レジ現金5000
        ]);
    }

    #[Test]
    public function 売上高の補助科目を追加できる()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(\App\Livewire\SetupWizard::class)
            ->set('name', '売上高補助科目テスト')
            ->set('business_type', 'general')
            ->call('next') // Step1 → Step2
            ->set('year', 2025)
            ->set('is_taxable', false)
            ->set('is_tax_exclusive', false)
            ->call('next') // Step2 → Step3
            ->call('next') // Step3 → Step4
            ->call('next') // Step4 → Step5
            ->set('revenue_sub_accounts', [
                [
                    'name' => '株式会社xxx',
                    'is_locked' => false,
                ],
            ])
            ->call('submit');

        $revenueAccount = Account::where('name', '売上高')->first();
        $this->assertDatabaseHas('sub_accounts', [
            'account_id' => $revenueAccount->id,
            'name' => '株式会社xxx',
        ]);
    }

    #[Test]
    public function 棚卸資産で名称が空白でも登録できる()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(\App\Livewire\SetupWizard::class)
            ->set('name', '棚卸資産空白テスト')
            ->set('business_type', 'general')
            ->call('next') // Step1 → Step2
            ->set('year', 2025)
            ->set('is_taxable', false)
            ->set('is_tax_exclusive', false)
            ->call('next') // Step2 → Step3
            ->call('next') // Step3 → Step4
            ->call('next') // Step4 → Step5
            ->set('other_assets', [
                [
                    'account_name' => '棚卸資産',
                    'sub_account_name' => '', // 空白の棚卸資産
                    'amount' => 100000,
                ],
            ])
            ->call('submit');

        $this->assertDatabaseHas('transactions', [
            'description' => '期首残高設定',
            'is_opening_entry' => true,
        ]);

        $user->refresh();
        $bu = $user->selectedBusinessUnit;

        $inventorySubAccount = $bu->getSubAccountByName('棚卸資産', '棚卸資産');
        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $inventorySubAccount->id,
            'amount' => 100000, // 棚卸資産100000
        ]);
    }
}
