<?php

use App\Models\User;
use App\Setup\Initializers\GeneralBusinessInitializer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class GeneralBusinessInitializerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function BusinessUnitが作成される()
    {
        $user = User::factory()->create();

        $inputs = [
            'name' => 'テスト事業体',
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => false,
            'is_tax_exclusive' => false,
            'cash_balance' => null,
            'bank_accounts' => [],
            'fixed_assets' => [],
            'recurring_templates' => [],
        ];

        $initializer = new GeneralBusinessInitializer();
        $unit = $initializer->initialize($user, $inputs);

        $this->assertDatabaseHas('business_units', [
            'id' => $unit->id,
            'name' => 'テスト事業体',
            'type' => 'general',
        ]);
    }

    #[Test]
    public function 会計年度が作成される_免税業者_税込経理()
    {
        $user = User::factory()->create();

        $inputs = [
            'name' => 'テスト事業体',
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => false,
            'is_tax_exclusive' => false,
            'cash_balance' => null,
            'bank_accounts' => [],
            'fixed_assets' => [],
            'recurring_templates' => [],
        ];

        $initializer = new GeneralBusinessInitializer();
        $unit = $initializer->initialize($user, $inputs);

        $this->assertDatabaseHas('fiscal_years', [
            'business_unit_id' => $unit->id,
            'year' => 2025,
            'is_taxable' => false,
            'is_tax_exclusive' => false,
        ]);
    }

    #[Test]
    public function 現金残高がある場合は仕訳が作成される()
    {
        $user = User::factory()->create();

        $inputs = [
            'name' => 'テスト事業体',
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => false,
            'is_tax_exclusive' => false,
            'fixed_assets' => [],
            'recurring_templates' => [],
            'opening_entries' => [
                [
                    'account_name' => 'その他の預金',
                    'sub_account_name' => 'メインバンク',
                    'amount' => 30000,
                ],
            ]
        ];

        $initializer = new GeneralBusinessInitializer();
        $unit = $initializer->initialize($user, $inputs);

        $fiscalYear = $unit->currentFiscalYear;

        $bankAccount = $unit->accounts()->where('name', 'その他の預金')->first();
        $equityAccount = $unit->accounts()->where('name', '元入金')->first();

        $this->assertDatabaseHas('journal_entries', [
            'account_id' => $bankAccount->id,
            'type' => 'debit',
            'amount' => 30000,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'account_id' => $equityAccount->id,
            'type' => 'credit',
            'amount' => 30000,
        ]);

        //メインバンクのサブアカウントが作成されていることを確認
        $this->assertDatabaseHas('sub_accounts', [
            'account_id' => $bankAccount->id,
            'name' => 'メインバンク',
        ]);

        // 仕訳の説明が期首残高設定になっていることを確認
        $this->assertDatabaseHas('transactions', [
            'description' => '期首残高設定',
            'is_opening_entry' => true,
            'fiscal_year_id' => $unit->fiscalYears()->first()->id,
        ]);
        $this->assertCount(1, $fiscalYear->transactions()->where('is_opening_entry', true)->get());

        // SubAccount がメインバンク のJournalEntriesを持つことを確認
        $subAccount = $bankAccount->subAccounts()->where('name', 'メインバンク')->first();
        $this->assertNotNull($subAccount, 'サブアカウントが作成されていません。');
        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $subAccount->id,
            'type' => 'debit',
            'amount' => 30000,
        ]);
    }


    #[Test]
    public function 現段階では免税業者で税込経理の事業体以外は作成できない_免税業者_税別経理()
    {
        $user = User::factory()->create();

        $inputs = [
            'name' => 'テスト事業体',
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => false,   // 免税業者
            'is_tax_exclusive' => true, // 税別経理
            'cash_balance' => null,
            'bank_accounts' => [],
            'fixed_assets' => [],
            'recurring_templates' => [],
        ];

        $initializer = new GeneralBusinessInitializer();

        $this->expectException(\InvalidArgumentException::class);
        $initializer->initialize($user, $inputs);
    }

    #[Test]
    public function 現段階では免税業者で税込経理の事業体以外は作成できない_課税業者_税込経理()
    {
        $user = User::factory()->create();

        $inputs = [
            'name' => 'テスト事業体',
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => true,   // 課税業者
            'is_tax_exclusive' => false, // 税込経理
            'cash_balance' => null,
            'bank_accounts' => [],
            'fixed_assets' => [],
            'recurring_templates' => [],
        ];

        $initializer = new GeneralBusinessInitializer();

        $this->expectException(\InvalidArgumentException::class);
        $initializer->initialize($user, $inputs);
    }

    #[Test]
    public function 現段階では免税業者で税込経理の事業体以外は作成できない_課税業者_税別経理()
    {
        $user = User::factory()->create();

        $inputs = [
            'name' => 'テスト事業体',
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => true,   // 課税業者
            'is_tax_exclusive' => true, // 税別経理
            'cash_balance' => null,
            'bank_accounts' => [],
            'fixed_assets' => [],
            'recurring_templates' => [],
        ];

        $initializer = new GeneralBusinessInitializer();

        $this->expectException(\InvalidArgumentException::class);
        $initializer->initialize($user, $inputs);
    }

    #[Test]
    public function 源泉徴収のSubAccountが生成される()
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

        $drawAccount = $unit->accounts()->where('name', '事業主貸')->first();

        $this->assertDatabaseHas('sub_accounts', [
            'account_id' => $drawAccount->id,
            'name' => '源泉徴収',
        ]);
    }
}
