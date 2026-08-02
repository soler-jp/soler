<?php

use App\Models\InitialSetupData;
use App\Models\SubAccount;
use App\Models\Todo;
use App\Models\User;
use App\Setup\Initializers\GeneralBusinessInitializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GeneralBusinessInitializerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function business_unitが作成される()
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

        $initializer = new GeneralBusinessInitializer;
        $unit = $initializer->initialize($user, $inputs);

        $this->assertDatabaseHas('business_units', [
            'id' => $unit->id,
            'name' => 'テスト事業体',
            'type' => 'general',
        ]);

        $this->assertDatabaseHas('initial_setup_data', [
            'business_unit_id' => $unit->id,
            'year' => 2025,
            'opening_context' => InitialSetupData::OPENING_CONTEXT_FIRST_YEAR,
            'is_taxable' => false,
            'bank_account_answer' => InitialSetupData::ANSWER_NO,
            'cash_on_hand_answer' => InitialSetupData::ANSWER_NO,
            'fixed_asset_answer' => InitialSetupData::ANSWER_NO,
            'recurring_expense_answer' => InitialSetupData::ANSWER_NO,
            'recurring_income_answer' => InitialSetupData::ANSWER_NO,
            'counterparty_answer' => InitialSetupData::ANSWER_NO,
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
            'opening_context' => InitialSetupData::OPENING_CONTEXT_FIRST_YEAR,
            'bank_account_answer' => InitialSetupData::ANSWER_NO,
            'cash_on_hand_answer' => InitialSetupData::ANSWER_NO,
            'fixed_asset_answer' => InitialSetupData::ANSWER_NO,
            'recurring_expense_answer' => InitialSetupData::ANSWER_NO,
            'recurring_income_answer' => InitialSetupData::ANSWER_NO,
            'counterparty_answer' => InitialSetupData::ANSWER_NO,
            'cash_balance' => null,
            'bank_accounts' => [],
            'fixed_assets' => [],
            'recurring_templates' => [],
        ];

        $initializer = new GeneralBusinessInitializer;
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
            ],
        ];

        $initializer = new GeneralBusinessInitializer;
        $unit = $initializer->initialize($user, $inputs);

        $fiscalYear = $unit->currentFiscalYear;

        $bankAccount = $unit->accounts()->where('name', 'その他の預金')->first();

        $bankSubAccount = SubAccount::where('name', 'メインバンク')
            ->whereHas('account', function ($query) use ($unit) {
                $query->where('business_unit_id', $unit->id)
                    ->where('name', 'その他の預金');
            })
            ->first();

        $equitySubAccount = $unit->subAccounts()
            ->whereHas('account', function ($query) {
                $query->where('name', '元入金');
            })
            ->first();

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $bankSubAccount->id,
            'type' => 'debit',
            'net_amount' => 30000,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $equitySubAccount->id,
            'type' => 'credit',
            'net_amount' => 30000,
        ]);

        // メインバンクのサブアカウントが作成されていることを確認
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
            'net_amount' => 30000,
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

        $initializer = new GeneralBusinessInitializer;

        $this->expectException(InvalidArgumentException::class);
        $initializer->initialize($user, $inputs);
    }

    #[Test]
    public function 課税業者で税込経理の事業体を作成できる()
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

        $initializer = new GeneralBusinessInitializer;
        $unit = $initializer->initialize($user, $inputs);

        $this->assertDatabaseHas('fiscal_years', [
            'business_unit_id' => $unit->id,
            'year' => 2025,
            'is_taxable' => true,
            'is_tax_exclusive' => false,
        ]);
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

        $initializer = new GeneralBusinessInitializer;

        $this->expectException(InvalidArgumentException::class);
        $initializer->initialize($user, $inputs);
    }

    #[Test]
    public function 源泉徴収の_sub_accountが生成される()
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

        $drawAccount = $unit->accounts()->where('name', '事業主貸')->first();

        $this->assertDatabaseHas('sub_accounts', [
            'account_id' => $drawAccount->id,
            'name' => '源泉徴収',
        ]);
    }

    #[Test]
    public function 初期設定で現金の期首残高がある場合は指定された現金_sub_accountのみを作る()
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
            'opening_entries' => [
                [
                    'account_name' => '現金',
                    'sub_account_name' => '手持ち現金',
                    'amount' => 12000,
                ],
            ],
        ]);

        $cashAccount = $unit->accounts()->where('name', '現金')->firstOrFail();

        $this->assertDatabaseHas('sub_accounts', [
            'account_id' => $cashAccount->id,
            'name' => '手持ち現金',
        ]);

        $this->assertDatabaseMissing('sub_accounts', [
            'account_id' => $cashAccount->id,
            'name' => 'レジ現金',
        ]);

        $this->assertDatabaseMissing('sub_accounts', [
            'account_id' => $cashAccount->id,
            'name' => 'その他現金',
        ]);
    }

    #[Test]
    public function 売上高_sub_accountは指定がなければ売上高になる()
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

        $salesAccount = $unit->accounts()->where('name', '売上高')->firstOrFail();

        $this->assertDatabaseHas('sub_accounts', [
            'account_id' => $salesAccount->id,
            'name' => '売上高',
        ]);

        $this->assertDatabaseMissing('sub_accounts', [
            'account_id' => $salesAccount->id,
            'name' => '一般売上',
        ]);
    }

    #[Test]
    public function 売上高_sub_accountが指定されている場合は指定されたもののみ生成される()
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
            'revenue_sub_accounts' => [
                ['name' => '株式会社aaa'],
                ['name' => 'bbb商事'],
            ],
        ]);

        $salesAccount = $unit->accounts()->where('name', '売上高')->first();

        $this->assertDatabaseHas('sub_accounts', [
            'account_id' => $salesAccount->id,
            'name' => '株式会社aaa',
        ]);

        $this->assertDatabaseHas('sub_accounts', [
            'account_id' => $salesAccount->id,
            'name' => 'bbb商事',
        ]);

        $this->assertDatabaseMissing('sub_accounts', [
            'account_id' => $salesAccount->id,
            'name' => '一般売上',
        ]);
    }

    #[Test]
    public function 初期設定で銀行口座ありを選ぶと銀行口座todoが作成される(): void
    {
        $user = User::factory()->create();
        $initializer = new GeneralBusinessInitializer;
        $unit = $initializer->initialize($user, [
            'name' => 'テスト事業体',
            'type' => 'general',
            'year' => 2023,
            'is_taxable' => false,
            'is_tax_exclusive' => false,
            'opening_context' => InitialSetupData::OPENING_CONTEXT_FIRST_YEAR,
            'bank_account_answer' => InitialSetupData::ANSWER_YES,
            'cash_on_hand_answer' => InitialSetupData::ANSWER_NO,
            'fixed_asset_answer' => InitialSetupData::ANSWER_NO,
            'recurring_expense_answer' => InitialSetupData::ANSWER_NO,
            'recurring_income_answer' => InitialSetupData::ANSWER_NO,
            'counterparty_answer' => InitialSetupData::ANSWER_NO,
        ]);

        $this->assertDatabaseHas('todos', [
            'business_unit_id' => $unit->id,
            'fiscal_year_id' => $unit->currentFiscalYear->id,
            'title' => '銀行口座を登録する',
            'body' => "事業専用の銀行口座の銀行名と、2023/1/1時点での残高を入力してください。\n銀行名のところは`〇〇銀行(1234)`のように、銀行口座の下4桁を入れておくと、同じ銀行で複数の口座がある時に見分けやすいのでオススメです。",
            'source_type' => Todo::SOURCE_TYPE_SYSTEM,
            'todo_type' => Todo::TODO_TYPE_WIZARD_BANK_ACCOUNT,
            'status' => Todo::STATUS_PENDING,
        ]);
    }

    #[Test]
    public function 初期設定で銀行口座なしなら銀行口座todoは作成されない(): void
    {
        $user = User::factory()->create();
        $initializer = new GeneralBusinessInitializer;
        $unit = $initializer->initialize($user, [
            'name' => 'テスト事業体',
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => false,
            'is_tax_exclusive' => false,
            'opening_context' => InitialSetupData::OPENING_CONTEXT_FIRST_YEAR,
            'bank_account_answer' => InitialSetupData::ANSWER_NO,
            'cash_on_hand_answer' => InitialSetupData::ANSWER_NO,
            'fixed_asset_answer' => InitialSetupData::ANSWER_NO,
            'recurring_expense_answer' => InitialSetupData::ANSWER_NO,
            'recurring_income_answer' => InitialSetupData::ANSWER_NO,
            'counterparty_answer' => InitialSetupData::ANSWER_NO,
        ]);

        $this->assertDatabaseMissing('todos', [
            'business_unit_id' => $unit->id,
            'title' => '銀行口座を登録する',
            'todo_type' => Todo::TODO_TYPE_WIZARD_BANK_ACCOUNT,
        ]);
    }

    #[Test]
    public function 初期設定で事業用現金ありを選ぶと現金todoが作成される(): void
    {
        $user = User::factory()->create();
        $initializer = new GeneralBusinessInitializer;
        $unit = $initializer->initialize($user, [
            'name' => 'テスト事業体',
            'type' => 'general',
            'year' => 2023,
            'is_taxable' => false,
            'is_tax_exclusive' => false,
            'opening_context' => InitialSetupData::OPENING_CONTEXT_FIRST_YEAR,
            'bank_account_answer' => InitialSetupData::ANSWER_NO,
            'cash_on_hand_answer' => InitialSetupData::ANSWER_YES,
            'fixed_asset_answer' => InitialSetupData::ANSWER_NO,
            'recurring_expense_answer' => InitialSetupData::ANSWER_NO,
            'recurring_income_answer' => InitialSetupData::ANSWER_NO,
            'counterparty_answer' => InitialSetupData::ANSWER_NO,
        ]);

        $this->assertDatabaseHas('todos', [
            'business_unit_id' => $unit->id,
            'fiscal_year_id' => $unit->currentFiscalYear->id,
            'title' => '事業用現金の管理場所を登録する',
            'body' => '事業専用の現金を管理する場所がある場合は、`メインレジ` `バックヤードの金庫` など、場所の名前と、2023/1/1 時点での金額を入力してください',
            'source_type' => Todo::SOURCE_TYPE_SYSTEM,
            'todo_type' => Todo::TODO_TYPE_WIZARD_CASH_ON_HAND,
            'status' => Todo::STATUS_PENDING,
        ]);
    }

    #[Test]
    public function 初期設定で定期支出ありを選ぶと固定費todoが作成される(): void
    {
        $user = User::factory()->create();
        $initializer = new GeneralBusinessInitializer;
        $unit = $initializer->initialize($user, [
            'name' => 'テスト事業体',
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => false,
            'is_tax_exclusive' => false,
            'opening_context' => InitialSetupData::OPENING_CONTEXT_FIRST_YEAR,
            'bank_account_answer' => InitialSetupData::ANSWER_NO,
            'cash_on_hand_answer' => InitialSetupData::ANSWER_NO,
            'fixed_asset_answer' => InitialSetupData::ANSWER_NO,
            'recurring_expense_answer' => InitialSetupData::ANSWER_YES,
            'recurring_income_answer' => InitialSetupData::ANSWER_NO,
            'counterparty_answer' => InitialSetupData::ANSWER_NO,
        ]);

        $this->assertDatabaseHas('todos', [
            'business_unit_id' => $unit->id,
            'fiscal_year_id' => $unit->currentFiscalYear->id,
            'title' => '固定費を登録する',
            'source_type' => Todo::SOURCE_TYPE_SYSTEM,
            'todo_type' => Todo::TODO_TYPE_WIZARD_RECURRING_EXPENSES,
            'status' => Todo::STATUS_PENDING,
        ]);
    }

    #[Test]
    public function 初期設定で定期支出なしなら固定費todoは作成されない(): void
    {
        $user = User::factory()->create();
        $initializer = new GeneralBusinessInitializer;
        $unit = $initializer->initialize($user, [
            'name' => 'テスト事業体',
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => false,
            'is_tax_exclusive' => false,
            'opening_context' => InitialSetupData::OPENING_CONTEXT_FIRST_YEAR,
            'bank_account_answer' => InitialSetupData::ANSWER_NO,
            'cash_on_hand_answer' => InitialSetupData::ANSWER_NO,
            'fixed_asset_answer' => InitialSetupData::ANSWER_NO,
            'recurring_expense_answer' => InitialSetupData::ANSWER_NO,
            'recurring_income_answer' => InitialSetupData::ANSWER_NO,
            'counterparty_answer' => InitialSetupData::ANSWER_NO,
        ]);

        $this->assertDatabaseMissing('todos', [
            'business_unit_id' => $unit->id,
            'title' => '固定費を登録する',
            'todo_type' => Todo::TODO_TYPE_WIZARD_RECURRING_EXPENSES,
        ]);
    }
}
