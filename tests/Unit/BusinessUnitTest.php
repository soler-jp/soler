<?php

namespace Tests\Unit;

use App\Models\Account;
use App\Models\BusinessUnit;
use App\Models\CreditCard;
use App\Models\FixedAsset;
use App\Models\SubAccount;
use App\Models\User;
use App\Services\TransactionRegistrar;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BusinessUnitTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 正常に作成できる()
    {
        $user = User::factory()->create();

        $unit = $user->createBusinessUnitWithDefaults([
            'name' => '農業事業',
            'type' => BusinessUnit::TYPE_AGRICULTURE,
        ]);

        $this->assertDatabaseHas('business_units', [
            'id' => $unit->id,
            'name' => '農業事業',
            'type' => BusinessUnit::TYPE_AGRICULTURE,
        ]);
    }

    #[Test]
    public function 必須項目が欠けているとエラーになる()
    {
        $this->expectException(ValidationException::class);

        $data = [
            'name' => null,
            'type' => null,
        ];

        Validator::validate($data, [
            'name' => 'required|string|max:255',
            'type' => 'required|in:'.implode(',', BusinessUnit::TYPES),
        ]);
    }

    #[Test]
    public function typeが不正な値だとエラーになる()
    {
        $this->expectException(ValidationException::class);

        $data = [
            'name' => 'テスト事業',
            'type' => 'invalid_type',
        ];

        Validator::validate($data, [
            'name' => 'required|string|max:255',
            'type' => 'required|in:'.implode(',', BusinessUnit::TYPES),
        ]);
    }

    #[Test]
    public function userとのリレーションが正しく機能する()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => '不動産管理',
            'type' => BusinessUnit::TYPE_REAL_ESTATE,
        ]);

        $this->assertInstanceOf(User::class, $unit->user);
        $this->assertEquals($user->id, $unit->user->id);
    }

    #[Test]
    public function create_with_default_accountsは標準勘定科目を登録する()
    {
        $user = User::factory()->create();

        $businessUnit = BusinessUnit::createWithDefaultAccounts([
            'user_id' => $user->id,
            'name' => '新規事業',
            'type' => BusinessUnit::TYPE_GENERAL,
        ]);

        $this->assertDatabaseHas('business_units', ['id' => $businessUnit->id, 'name' => '新規事業']);

        foreach (BusinessUnit::$defaultAccounts as $account) {
            $this->assertDatabaseHas('accounts', [
                'business_unit_id' => $businessUnit->id,
                'name' => $account['name'],
                'type' => $account['type'],
            ]);
        }
    }

    #[Test]
    public function create_with_default_accountsはexampleとcautionを保存する()
    {
        $user = User::factory()->create();

        $businessUnit = BusinessUnit::createWithDefaultAccounts([
            'user_id' => $user->id,
            'name' => 'NTAデータ検証',
            'type' => BusinessUnit::TYPE_GENERAL,
        ]);

        // example のみ持つ科目
        $travel = $businessUnit->getAccountByName('旅費交通費');
        $this->assertSame('電車賃、バス代、タクシー代、宿泊代', $travel->example);
        $this->assertNull($travel->caution);

        // example と caution の両方を持つ科目
        $tax = $businessUnit->getAccountByName('租税公課');
        $this->assertStringContainsString('消費税及び地方消費税', $tax->example);
        $this->assertStringContainsString('商工会議所', $tax->example);
        $this->assertStringContainsString("\n", $tax->example);
        $this->assertStringContainsString('所得税及び復興特別所得税', $tax->caution);

        // NTA データに含まれない科目
        $opening = $businessUnit->getAccountByName('期首商品（棚卸高）');
        $this->assertNull($opening->example);
        $this->assertNull($opening->caution);

        $unclassified = $businessUnit->getAccountByName(BusinessUnit::UNCLASSIFIED_EXPENSE_ACCOUNT_NAME);
        $this->assertNull($unclassified->example);
        $this->assertNull($unclassified->caution);
    }

    #[Test]
    public function 棚卸振替科目はexpenseとして初期作成される()
    {
        $user = User::factory()->create();

        $businessUnit = BusinessUnit::createWithDefaultAccounts([
            'user_id' => $user->id,
            'name' => '棚卸科目テスト',
            'type' => BusinessUnit::TYPE_GENERAL,
        ]);

        $openingInventory = $businessUnit->getAccountByName('期首商品（棚卸高）');
        $closingInventory = $businessUnit->getAccountByName('期末商品（棚卸高）');

        $this->assertNotNull($openingInventory);
        $this->assertNotNull($closingInventory);
        $this->assertSame(Account::TYPE_EXPENSE, $openingInventory->type);
        $this->assertSame(Account::TYPE_EXPENSE, $closingInventory->type);
    }

    #[Test]
    public function example_caution_migrationは既存accountをbackfillする()
    {
        $user = User::factory()->create();

        $businessUnit = BusinessUnit::factory()->create([
            'user_id' => $user->id,
        ]);

        // migration より前に作られた「素の」account を用意する（example/caution は null）。
        $taxAccount = $businessUnit->accounts()->create([
            'name' => '租税公課',
            'type' => Account::TYPE_EXPENSE,
        ]);

        $travelAccount = $businessUnit->accounts()->create([
            'name' => '旅費交通費',
            'type' => Account::TYPE_EXPENSE,
        ]);

        // 既にユーザーが独自の値を持っている account は上書きしない想定。
        $customTaxAccount = $businessUnit->accounts()->create([
            'name' => '租税公課',
            'type' => Account::TYPE_EXPENSE,
            'example' => 'ユーザー独自メモ',
        ]);

        $migration = require base_path('database/migrations/2026_08_04_093521_add_example_and_caution_to_accounts_table.php');
        $migration->up();

        $this->assertStringContainsString('消費税及び地方消費税', $taxAccount->fresh()->example);
        $this->assertStringContainsString('所得税及び復興特別所得税', $taxAccount->fresh()->caution);

        $this->assertSame('電車賃、バス代、タクシー代、宿泊代', $travelAccount->fresh()->example);
        $this->assertNull($travelAccount->fresh()->caution);

        // 既存値は残す。
        $this->assertSame('ユーザー独自メモ', $customTaxAccount->fresh()->example);
        $this->assertNull($customTaxAccount->fresh()->caution);
    }

    #[Test]
    public function 棚卸振替科目のmigrationで既存assetをexpenseへ更新できる()
    {
        $user = User::factory()->create();

        $businessUnit = BusinessUnit::factory()->create([
            'user_id' => $user->id,
            'name' => '既存棚卸科目テスト',
            'type' => BusinessUnit::TYPE_GENERAL,
        ]);

        $openingInventory = $businessUnit->accounts()->create([
            'name' => '期首商品（棚卸高）',
            'type' => Account::TYPE_ASSET,
        ]);

        $closingInventory = $businessUnit->accounts()->create([
            'name' => '期末商品（棚卸高）',
            'type' => Account::TYPE_ASSET,
        ]);

        $migration = require base_path('database/migrations/2026_07_03_061251_update_inventory_closing_account_types.php');
        $migration->up();

        $this->assertSame(Account::TYPE_EXPENSE, $openingInventory->fresh()->type);
        $this->assertSame(Account::TYPE_EXPENSE, $closingInventory->fresh()->type);
    }

    #[Test]
    public function 未分類費用_accountが初期作成され_未分類_sub_accountを持つ()
    {
        $user = User::factory()->create();

        $businessUnit = BusinessUnit::createWithDefaultAccounts([
            'user_id' => $user->id,
            'name' => '未分類費用テスト',
            'type' => BusinessUnit::TYPE_GENERAL,
        ]);

        $unclassifiedAccount = $businessUnit->getAccountByName(BusinessUnit::UNCLASSIFIED_EXPENSE_ACCOUNT_NAME);

        $this->assertNotNull($unclassifiedAccount, '未分類費用の勘定科目が見つかりません。');
        $this->assertSame(Account::TYPE_EXPENSE, $unclassifiedAccount->type);

        $subAccounts = $unclassifiedAccount->subAccounts;

        $this->assertCount(1, $subAccounts);
        $this->assertSame(BusinessUnit::UNCLASSIFIED_EXPENSE_SUB_ACCOUNT_NAME, $subAccounts->first()->name);
    }

    #[Test]
    public function 未分類費用のmigrationで既存事業体に予約科目を作成できる()
    {
        $user = User::factory()->create();

        $businessUnit = BusinessUnit::factory()->create([
            'user_id' => $user->id,
            'name' => '既存事業体（未分類費用未作成）',
            'type' => BusinessUnit::TYPE_GENERAL,
        ]);

        $this->assertNull($businessUnit->getAccountByName(BusinessUnit::UNCLASSIFIED_EXPENSE_ACCOUNT_NAME));

        $migration = require base_path('database/migrations/2026_08_03_115149_add_unclassified_expense_account_to_business_units.php');
        $migration->up();

        $unclassifiedAccount = $businessUnit->fresh()->getAccountByName(BusinessUnit::UNCLASSIFIED_EXPENSE_ACCOUNT_NAME);

        $this->assertNotNull($unclassifiedAccount);
        $this->assertSame(Account::TYPE_EXPENSE, $unclassifiedAccount->type);

        $subAccounts = $unclassifiedAccount->subAccounts;

        $this->assertCount(1, $subAccounts);
        $this->assertSame(BusinessUnit::UNCLASSIFIED_EXPENSE_SUB_ACCOUNT_NAME, $subAccounts->first()->name);
    }

    #[Test]
    public function 未分類費用のmigrationは既に存在する事業体に対して冪等である()
    {
        $user = User::factory()->create();

        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業所',
        ]);

        $originalAccountId = $businessUnit->getAccountByName(BusinessUnit::UNCLASSIFIED_EXPENSE_ACCOUNT_NAME)->id;

        $migration = require base_path('database/migrations/2026_08_03_115149_add_unclassified_expense_account_to_business_units.php');
        $migration->up();

        $unclassifiedAccount = $businessUnit->fresh()->getAccountByName(BusinessUnit::UNCLASSIFIED_EXPENSE_ACCOUNT_NAME);

        $this->assertSame($originalAccountId, $unclassifiedAccount->id);
        $this->assertCount(1, $unclassifiedAccount->subAccounts);
    }

    #[Test]
    public function sub_account_sort_order_migrationは既存データにも順序値を正しく埋める()
    {
        $user = User::factory()->create();

        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'sub_accounts sort_order 後埋めテスト',
        ]);

        $subAccountIds = $businessUnit->subAccounts()->pluck('sub_accounts.id');

        DB::table('sub_accounts')
            ->whereIn('id', $subAccountIds)
            ->update(['sort_order' => SubAccount::SORT_ORDER_DEFAULT]);

        $migration = require base_path('database/migrations/2026_08_04_100001_add_sort_order_to_sub_accounts_table.php');
        $migration->up();

        foreach (BusinessUnit::$prioritizedDefaultSubAccounts as $index => $name) {
            $expected = ($index + 1) * 10;
            $sub = $businessUnit->subAccounts()->where('sub_accounts.name', $name)->first();
            $this->assertSame($expected, $sub->sort_order, "$name は $expected 想定");
        }

        foreach (['現金', '通信費'] as $name) {
            $sub = $businessUnit->subAccounts()->where('sub_accounts.name', $name)->first();
            $this->assertSame(SubAccount::SORT_ORDER_DEFAULT, $sub->sort_order);
        }
    }

    #[Test]
    public function sub_account_system_purpose_visibility_migrationは既存データにも予約用途と可視性を正しく埋める()
    {
        $user = User::factory()->create();

        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'sub_accounts system_purpose/visibility 後埋めテスト',
        ]);

        // 予約 SubAccount まで含めて migration 未適用の状態に巻き戻す。
        $subAccountIds = $businessUnit->subAccounts()->pluck('sub_accounts.id');

        DB::table('sub_accounts')
            ->whereIn('id', $subAccountIds)
            ->update([
                'system_purpose' => null,
                'visibility' => SubAccount::VISIBILITY_STANDARD,
            ]);

        $migration = require base_path('database/migrations/2026_08_04_100000_add_system_purpose_and_visibility_to_sub_accounts_table.php');
        $migration->up();

        // 未分類 SubAccount は system_purpose=unclassified になる。
        $unclassified = $businessUnit->getSubAccountByName(
            BusinessUnit::UNCLASSIFIED_EXPENSE_ACCOUNT_NAME,
            BusinessUnit::UNCLASSIFIED_EXPENSE_SUB_ACCOUNT_NAME,
        );
        $this->assertSame(SubAccount::PURPOSE_UNCLASSIFIED, $unclassified->system_purpose);

        // 標準リストの SubAccount は standard のまま残る。
        foreach (BusinessUnit::$standardDefaultSubAccounts as $name) {
            $sub = $businessUnit->subAccounts()->where('sub_accounts.name', $name)->first();
            $this->assertSame(SubAccount::VISIBILITY_STANDARD, $sub->visibility, "$name は standard 想定");
        }

        // 標準リストに含まれない既定 SubAccount は expanded に降格する。
        foreach (['現金', '通信費', '源泉徴収'] as $name) {
            $sub = $businessUnit->subAccounts()->where('sub_accounts.name', $name)->first();
            $this->assertSame(SubAccount::VISIBILITY_EXPANDED, $sub->visibility, "$name は expanded 想定");
        }
    }

    #[Test]
    public function 自動生成された現金系の同名_sub_accountをhiddenにするmigrationが既存データへ適用される()
    {
        $user = User::factory()->create();

        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => '現金系 hidden 後埋めテスト',
        ]);

        foreach (['現金', '当座預金', '定期預金', 'その他の預金'] as $name) {
            $subAccount = $businessUnit->getSubAccountByName($name, $name);
            $this->assertNotNull($subAccount, "{$name} / {$name} の SubAccount が見つかりません。");

            DB::table('sub_accounts')
                ->where('id', $subAccount->id)
                ->update(['visibility' => SubAccount::VISIBILITY_EXPANDED]);
        }

        $migration = require base_path('database/migrations/2026_08_04_100004_hide_autogenerated_cash_like_sub_accounts.php');
        $migration->up();

        foreach (['現金', '当座預金', '定期預金', 'その他の預金'] as $name) {
            $subAccount = $businessUnit->fresh()->getSubAccountByName($name, $name);
            $this->assertSame(SubAccount::VISIBILITY_HIDDEN, $subAccount->visibility, "{$name} は hidden 想定");
        }
    }

    #[Test]
    public function nameで_accountを取得できる()
    {
        $user = User::factory()->create();

        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業',
        ]);

        $account = $unit->getAccountByName('その他の預金');

        $this->assertNotNull($account);
        $this->assertSame('その他の預金', $account->name);
    }

    #[Test]
    public function 存在しないnameを指定した場合nullが返る()
    {
        $user = User::factory()->create();

        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業',
        ]);

        $account = $unit->getAccountByName('架空勘定');

        $this->assertNull($account);
    }

    #[Test]
    public function 支払元プリセットに応じた勘定科目を想定順で取得できる(): void
    {
        $user = User::factory()->create();

        $unit = $user->createBusinessUnitWithDefaults([
            'name' => '支払元プリセット確認',
        ]);

        $unit->addCustomAccount(Account::TYPE_ASSET, '普通預金', '普通預金', $user);

        $paymentNames = $unit->paymentAccounts(BusinessUnit::PAYMENT_ACCOUNT_PRESET_PAYMENT)
            ->pluck('name')
            ->values()
            ->all();

        $this->assertSame(['現金', '普通預金', 'その他の預金', '事業主借', '買掛金'], $paymentNames);
    }

    #[Test]
    public function tax_paid_accountは仮払消費税の_accountを返す()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業']);

        $account = $unit->accounts()->create([
            'name' => '仮払消費税',
            'type' => 'asset',
        ]);

        $this->assertSame($account->id, $unit->taxPaidAccount()->id);
    }

    #[Test]
    public function tax_received_accountは仮受消費税の_accountを返す()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業']);

        $account = $unit->accounts()->create([
            'name' => '仮受消費税',
            'type' => 'liability',
        ]);

        $this->assertSame($account->id, $unit->taxReceivedAccount()->id);
    }

    #[Test]
    public function tax_paid_accountが存在しないと例外を投げる()
    {
        $this->expectException(ModelNotFoundException::class);

        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業']);

        $unit->taxPaidAccount();
    }

    #[Test]
    public function current_fiscal_yearを取得できる()
    {
        $user = User::factory()->create();

        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
        ]);

        $fiscal2024 = $unit->createFiscalYear(2024, $user);

        $fiscal2025 = $unit->createFiscalYear(2025, $user);

        $unit->update([
            'current_fiscal_year_id' => $fiscal2025->id,
        ]);

        $this->assertTrue($unit->currentFiscalYear->is($fiscal2025));
        $this->assertEquals(2025, $unit->currentFiscalYear->year);
    }

    #[Test]
    public function current_fiscal_yearを保持した事業体も関連取引ごと削除できる(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '削除テスト事業体']);
        $fiscalYear = $businessUnit->createFiscalYear(2025, $user);

        $cashSubAccount = $businessUnit->getAccountByName('現金')?->subAccounts()->firstOrFail();
        $salesSubAccount = $businessUnit->getAccountByName('売上高')?->subAccounts()->firstOrFail();

        $this->assertNotNull($cashSubAccount);
        $this->assertNotNull($salesSubAccount);

        $transaction = $fiscalYear->registerTransaction(
            [
                'date' => '2025-01-01',
                'description' => '削除連鎖テスト',
            ],
            [
                [
                    'sub_account_id' => $cashSubAccount->id,
                    'type' => 'debit',
                    'net_amount' => 1000,
                    'tax_amount' => 0,
                ],
                [
                    'sub_account_id' => $salesSubAccount->id,
                    'type' => 'credit',
                    'net_amount' => 1000,
                    'tax_amount' => 0,
                ],
            ],
            $user
        );

        $businessUnit->delete();

        $this->assertModelMissing($businessUnit);
        $this->assertModelMissing($fiscalYear);
        $this->assertModelMissing($transaction);
    }

    #[Test]
    public function fixed_assetsの一覧と償却中一覧を取得できる(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => '固定資産テスト事業体',
        ]);

        $unit->createFiscalYear(2031, $user);

        $assetAccount = $unit->getAccountByName('車両運搬具');
        $this->assertNotNull($assetAccount);

        $ongoingAsset = FixedAsset::create([
            'business_unit_id' => $unit->id,
            'account_id' => $assetAccount->id,
            'name' => '2025年度時点で償却継続中の車両',
            'asset_category' => '新車-普通車',
            'acquisition_date' => '2024-03-01',
            'taxable_amount' => 1_000_000,
            'tax_amount' => 100_000,
            'useful_life' => 72,
            'depreciation_method' => FixedAsset::DEPRECIATION_METHOD_STRAIGHT_LINE,
            'is_disposed' => false,
        ]);

        $completedAsset = FixedAsset::create([
            'business_unit_id' => $unit->id,
            'account_id' => $assetAccount->id,
            'name' => '2025年度時点で償却完了の車両',
            'asset_category' => '新車-普通車',
            'acquisition_date' => '2018-03-01',
            'taxable_amount' => 1_000_000,
            'tax_amount' => 100_000,
            'useful_life' => 72,
            'depreciation_method' => FixedAsset::DEPRECIATION_METHOD_STRAIGHT_LINE,
            'is_disposed' => false,
        ]);

        $allFixedAssets = $unit->allFixedAssets();
        $fiscalYear2025 = $unit->createFiscalYear(2025, $user);
        $depreciatingFixedAssets = $unit->depreciatingFixedAssets($fiscalYear2025);

        $this->assertSame(
            ['2025年度時点で償却継続中の車両', '2025年度時点で償却完了の車両'],
            $allFixedAssets->pluck('name')->all()
        );
        $this->assertSame(
            ['2025年度時点で償却継続中の車両'],
            $depreciatingFixedAssets->pluck('name')->all()
        );
    }

    #[Test]
    public function current_fiscal_yearを設定できる()
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);

        $fiscalYear2025 = $businessUnit->createFiscalYear(2025, $user);

        $businessUnit->setCurrentFiscalYear($fiscalYear2025, $user);

        $this->assertEquals($fiscalYear2025->id, $businessUnit->current_fiscal_year_id);
        $this->assertTrue($businessUnit->currentFiscalYear->is($fiscalYear2025));
    }

    #[Test]
    public function activate_fiscal_yearで表示中年度とactiveフラグを切り替えられる(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '切替テスト事業体']);
        $fiscalYear2024 = $businessUnit->createFiscalYear(2024, $user);
        $fiscalYear2025 = $businessUnit->createFiscalYear(2025, $user);

        $businessUnit->activateFiscalYear($fiscalYear2025, $user);

        $businessUnit->refresh();

        $this->assertSame($fiscalYear2025->id, $businessUnit->current_fiscal_year_id);
        $this->assertTrue((bool) $fiscalYear2025->fresh()->is_active);
        $this->assertFalse((bool) $fiscalYear2024->fresh()->is_active);
    }

    #[Test]
    public function create_next_fiscal_year_fromで税設定を引き継いだ翌年度を作成できる(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '翌年度作成テスト事業体']);
        $fiscalYear2025 = $businessUnit->createFiscalYear(2025, $user);
        $fiscalYear2025->update([
            'is_taxable' => true,
            'is_tax_exclusive' => false,
        ]);

        $nextFiscalYear = $businessUnit->createNextFiscalYearFrom($fiscalYear2025, $user);

        $this->assertSame(2026, $nextFiscalYear->year);
        $this->assertTrue((bool) $nextFiscalYear->is_taxable);
        $this->assertFalse((bool) $nextFiscalYear->is_tax_exclusive);
    }

    #[Test]
    public function create_next_fiscal_year_fromで翌年度の税設定を上書きできる(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '翌年度税設定上書きテスト事業体']);
        $fiscalYear2025 = $businessUnit->createFiscalYear(2025, $user);
        $fiscalYear2025->update([
            'is_taxable' => true,
            'is_tax_exclusive' => false,
        ]);

        $nextFiscalYear = $businessUnit->createNextFiscalYearFrom(
            $fiscalYear2025,
            $user,
            isTaxable: false,
            isTaxExclusive: false,
        );

        $this->assertFalse((bool) $nextFiscalYear->is_taxable);
        $this->assertFalse((bool) $nextFiscalYear->is_tax_exclusive);
    }

    #[Test]
    public function 他の事業体の_fiscal_yearを設定しようとすると例外が発生する()
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $unitA = $userA->createBusinessUnitWithDefaults(['name' => '事業体A']);
        $unitB = $userB->createBusinessUnitWithDefaults(['name' => '事業体B']);

        $foreignFiscalYear = $unitB->fiscalYears()->create([
            'year' => 2025,
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
            'is_taxable_supplier' => false,
            'is_tax_exclusive' => false,
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $unitA->setCurrentFiscalYear($foreignFiscalYear, $userA);
    }

    #[Test]
    public function 他ユーザーはcurrent_fiscal_yearを設定できない()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '年度選択認可テスト']);
        $fiscalYear = $businessUnit->createFiscalYear(2025, $user);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('この事業体の現在の会計年度を変更する権限がありません。');

        $businessUnit->setCurrentFiscalYear($fiscalYear, $otherUser);
    }

    #[Test]
    public function create_fiscal_yearで初回年度が自動で選択される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '初期事業体']);

        $fiscal = $unit->createFiscalYear(2025, $user);

        $this->assertTrue($unit->currentFiscalYear->is($fiscal));
    }

    #[Test]
    public function 既にcurrent_fiscal_yearがあれば、createしても選択は変わらない()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);

        $fiscal2024 = $unit->createFiscalYear(2024, $user);

        // 既にcurrentFiscalYearが設定されている
        $unit->setCurrentFiscalYear($fiscal2024, $user);

        // 新しい年度を作成してもcurrentFiscalYearは変わらない
        $unit->createFiscalYear(2025, $user);

        $this->assertTrue($unit->currentFiscalYear->is($fiscal2024));
    }

    #[Test]
    public function よく使う勘定科目のgetterテスト()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);

        $names = [
            '現金',
            '事業主貸',
            '事業主借',
            '売上高',
        ];

        foreach ($names as $name) {
            $account = $unit->getAccountByName($name);
            $this->assertNotNull($account, "勘定科目 '{$name}' が見つかりません。");
            $this->assertEquals($name, $account->name, '勘定科目の名前が期待と異なります。');
        }
    }

    #[Test]
    public function よく使う_sub_accountのgetterテスト()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);

        $names = [
            '事業主貸' => [
                '源泉徴収',
            ],
        ];

        foreach ($names as $accountName => $subAccounts) {
            $account = $unit->getAccountByName($accountName);
            $this->assertNotNull($account, "勘定科目 '{$accountName}' が見つかりません。");

            foreach ($subAccounts as $name) {
                $subAccount = $unit->getSubAccountByName($accountName, $name);
                $this->assertNotNull($subAccount, "サブ勘定科目 '{$name}' が見つかりません。");
                $this->assertEquals($name, $subAccount->name, 'サブ勘定科目の名前が期待と異なります。');
            }
        }
    }

    #[Test]
    public function 勘定科目を作成すると同名の補助科目が自動で作成される()
    {
        $user = User::factory()->create();

        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業所',
        ]);

        $account = $businessUnit->createAccount([
            'name' => '仮払金',
            'type' => 'asset',
        ], $businessUnit->user);

        $this->assertDatabaseHas('accounts', [
            'id' => $account->id,
            'name' => '仮払金',
        ]);

        $this->assertCount(1, $account->subAccounts);

        $this->assertSame('仮払金', $account->subAccounts->first()->name);
    }

    #[Test]
    public function add_custom_accountは既定で同名の補助科目を作成する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'カスタム勘定科目テスト事業所',
        ]);

        $account = $businessUnit->addCustomAccount(Account::TYPE_EXPENSE, '研究開発費', null, $user);

        $this->assertSame('研究開発費', $account->name);
        $this->assertSame(Account::TYPE_EXPENSE, $account->type);
        $this->assertCount(1, $account->subAccounts);
        $this->assertSame('研究開発費', $account->subAccounts->first()->name);
    }

    #[Test]
    public function create_accountは他人の事業体には追加できない(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $businessUnit = $owner->createBusinessUnitWithDefaults([
            'name' => '認可テスト事業所',
        ]);

        $this->expectException(AuthorizationException::class);

        $businessUnit->createAccount([
            'name' => '研究開発費',
            'type' => Account::TYPE_EXPENSE,
        ], $otherUser);
    }

    #[Test]
    public function add_custom_accountは指定された別名の補助科目を作成できる(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'カスタム補助科目テスト事業所',
        ]);

        $account = $businessUnit->addCustomAccount(Account::TYPE_EXPENSE, '研究開発費', '役員会議', $user);

        $this->assertSame('研究開発費', $account->name);
        $this->assertCount(1, $account->subAccounts);
        $this->assertSame('役員会議', $account->subAccounts->first()->name);
    }

    #[Test]
    public function add_custom_accountは同一事業体内で同名の勘定科目を許可しない(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => '重複勘定科目テスト事業所',
        ]);

        $businessUnit->addCustomAccount(Account::TYPE_EXPENSE, '研究開発費', null, $user);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('同名の勘定科目は既に存在します。');

        $businessUnit->addCustomAccount(Account::TYPE_EXPENSE, '研究開発費', null, $user);
    }

    #[Test]
    public function add_custom_accountは他人の事業体には追加できない(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $businessUnit = $owner->createBusinessUnitWithDefaults([
            'name' => '認可テスト事業所',
        ]);

        $this->expectException(AuthorizationException::class);

        $businessUnit->addCustomAccount(Account::TYPE_EXPENSE, '研究開発費', null, $otherUser);
    }

    #[Test]
    public function add_custom_accountはnullを渡すと同名の補助科目を作成する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'null補助科目テスト事業所',
        ]);

        $account = $businessUnit->addCustomAccount(Account::TYPE_EXPENSE, '研究開発費', null, $user);

        $this->assertSame('研究開発費', $account->subAccounts->first()->name);
    }

    #[Test]
    public function 事業主貸の_sub_accountに源泉徴収が自動で作成される()
    {
        $user = User::factory()->create();

        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業所',
        ]);

        $drawAccount = $businessUnit->getAccountByName('事業主貸');

        $this->assertNotNull($drawAccount, '事業主貸の勘定科目が見つかりません。');

        $subAccounts = $drawAccount->subAccounts;

        $this->assertCount(2, $subAccounts);
        $this->assertEquals('事業主貸', $subAccounts->first()->name);
        $this->assertTrue($subAccounts->contains(fn ($subAccount): bool => $subAccount->name === '源泉徴収'));
    }

    #[Test]
    public function 現金の_sub_accountは現金のみ()
    {

        $user = User::factory()->create();

        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業所',
        ]);

        $cashAccount = $businessUnit->getAccountByName('現金');

        $this->assertNotNull($cashAccount, '現金の勘定科目が見つかりません。');

        $subAccounts = $cashAccount->subAccounts;

        $this->assertCount(1, $subAccounts);
        $this->assertEquals('現金', $subAccounts->first()->name);
    }

    #[Test]
    public function available_credit_sourcesは利用可能な貸方候補だけを返す(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業所',
        ]);
        $fiscalYear = $businessUnit->createFiscalYear(2025, $user);

        $cashAccount = $businessUnit->getAccountByName('現金');
        $bankAccount = $businessUnit->getAccountByName('その他の預金');
        $privateAccount = $businessUnit->getAccountByName('事業主借');
        $liabilityAccount = $businessUnit->getAccountByName('未払金');

        $cashAccount?->createSubAccount(['name' => '金庫現金'], $user);
        $bankA = $bankAccount?->createSubAccount(['name' => 'XX銀行'], $user);
        $bankB = $bankAccount?->createSubAccount(['name' => 'OO銀行'], $user);
        $privateAccount?->createSubAccount(['name' => '家族立替'], $user);
        $cardLiability = $liabilityAccount?->createSubAccount(['name' => 'Amex Business'], $user);

        (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-01-15',
            'description' => '現金利用実績',
        ], [
            [
                'sub_account_id' => $privateAccount?->subAccounts()->first()?->id,
                'type' => 'debit',
                'net_amount' => 1000,
            ],
            [
                'sub_account_id' => $cashAccount?->subAccounts()->first()?->id,
                'type' => 'credit',
                'net_amount' => 1000,
            ],
        ], $fiscalYear->businessUnit->user);

        (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-01-20',
            'description' => '銀行利用実績',
        ], [
            [
                'sub_account_id' => $privateAccount?->subAccounts()->first()?->id,
                'type' => 'debit',
                'net_amount' => 2000,
            ],
            [
                'sub_account_id' => $bankA?->id,
                'type' => 'credit',
                'net_amount' => 2000,
            ],
        ], $fiscalYear->businessUnit->user);

        $businessCard = CreditCard::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'issuer_name' => 'Amex',
            'network' => 'amex',
            'last_four' => '1234',
            'ownership_type' => CreditCard::OWNERSHIP_TYPE_BUSINESS,
            'liability_sub_account_id' => $cardLiability?->id,
            'is_active' => true,
        ]);

        CreditCard::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'ownership_type' => CreditCard::OWNERSHIP_TYPE_PERSONAL,
            'owner_draw_sub_account_id' => $privateAccount?->subAccounts()->first()?->id,
        ]);

        CreditCard::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'ownership_type' => CreditCard::OWNERSHIP_TYPE_BUSINESS,
            'liability_sub_account_id' => $cardLiability?->id,
            'is_active' => false,
        ]);

        $sources = $businessUnit->availableCreditSources();

        $this->assertSame([
            '現金',
            '現金',
            '銀行口座',
            '銀行口座',
            'クレジットカード',
            'プライベート資金',
            'プライベート資金',
        ], $sources->pluck('category_label')->all());

        $this->assertSame([
            '現金',
            '金庫現金',
            'XX銀行',
            'OO銀行',
            $businessCard->display_label,
            'プライベートの財布・クレジットから支払い',
            '家族立替',
        ], $sources->pluck('label')->all());

        $this->assertFalse($sources->contains(fn (array $source): bool => $source['label'] === 'その他の預金'));
        $this->assertFalse($sources->contains(fn (array $source): bool => $source['category'] === BusinessUnit::CREDIT_SOURCE_CATEGORY_CARD && $source['label'] !== $businessCard->display_label));

        $privateSource = $sources->firstWhere('category', BusinessUnit::CREDIT_SOURCE_CATEGORY_PRIVATE);
        $this->assertSame('プライベート資金', $privateSource['category_label']);
        $this->assertSame('個人のお金で立て替えて支払った場合', $privateSource['description']);

        $bankSource = $sources->firstWhere('sub_account_id', $bankA?->id);
        $this->assertSame($bankAccount?->id, $bankSource['account_id']);

        $cardSource = $sources->firstWhere('category', BusinessUnit::CREDIT_SOURCE_CATEGORY_CARD);
        $this->assertSame($cardLiability?->id, $cardSource['sub_account_id']);
    }

    #[Test]
    public function 現金の仕訳実績がなければavailable_credit_sourcesに現金を含めない(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業所',
        ]);

        $sources = $businessUnit->availableCreditSources();

        $this->assertFalse(
            $sources->contains(
                fn (array $source): bool => $source['category'] === BusinessUnit::CREDIT_SOURCE_CATEGORY_CASH
            )
        );
    }

    #[Test]
    public function 明示的に追加した現金のsub_accountは仕訳実績がなくてもavailable_credit_sourcesに含める(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業所',
        ]);

        $cashAccount = $businessUnit->getAccountByName('現金');
        $cashSubAccount = $cashAccount?->createSubAccount(['name' => '金庫現金'], $user);

        $sources = $businessUnit->availableCreditSources();

        $cashSource = $sources->firstWhere('sub_account_id', $cashSubAccount?->id);

        $this->assertNotNull($cashSource);
        $this->assertSame(BusinessUnit::CREDIT_SOURCE_CATEGORY_CASH, $cashSource['category']);
        $this->assertSame('金庫現金', $cashSource['label']);
        $this->assertFalse($sources->contains(fn (array $source): bool => $source['label'] === '現金'));
    }

    #[Test]
    public function 明示的に追加した銀行口座のsub_accountは仕訳実績がなくてもavailable_credit_sourcesに含める(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業所',
        ]);

        $bankAccount = $businessUnit->getAccountByName('その他の預金');
        $bankSubAccount = $bankAccount?->createSubAccount(['name' => 'XX銀行'], $user);

        $sources = $businessUnit->availableCreditSources();

        $bankSource = $sources->firstWhere('sub_account_id', $bankSubAccount?->id);

        $this->assertNotNull($bankSource);
        $this->assertSame(BusinessUnit::CREDIT_SOURCE_CATEGORY_BANK, $bankSource['category']);
        $this->assertSame('XX銀行', $bankSource['label']);
        $this->assertFalse($sources->contains(fn (array $source): bool => $source['label'] === 'その他の預金'));
    }
}
