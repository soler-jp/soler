<?php

namespace Tests\Unit;

use App\Models\BusinessUnit;
use App\Models\FixedAsset;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $fiscal2024 = $unit->createFiscalYear(2024);

        $fiscal2025 = $unit->createFiscalYear(2025);

        $unit->update([
            'current_fiscal_year_id' => $fiscal2025->id,
        ]);

        $this->assertTrue($unit->currentFiscalYear->is($fiscal2025));
        $this->assertEquals(2025, $unit->currentFiscalYear->year);
    }

    #[Test]
    public function current_fiscal_yearを設定できる()
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);

        $fiscalYear2025 = $businessUnit->createFiscalYear(2025);

        $businessUnit->setCurrentFiscalYear($fiscalYear2025);

        $this->assertEquals($fiscalYear2025->id, $businessUnit->current_fiscal_year_id);
        $this->assertTrue($businessUnit->currentFiscalYear->is($fiscalYear2025));
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

        $unitA->setCurrentFiscalYear($foreignFiscalYear);
    }

    #[Test]
    public function create_fiscal_yearで初回年度が自動で選択される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '初期事業体']);

        $fiscal = $unit->createFiscalYear(2025);

        $this->assertTrue($unit->currentFiscalYear->is($fiscal));
    }

    #[Test]
    public function 既にcurrent_fiscal_yearがあれば、createしても選択は変わらない()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);

        $fiscal2024 = $unit->createFiscalYear(2024);

        // 既にcurrentFiscalYearが設定されている
        $unit->setCurrentFiscalYear($fiscal2024);

        // 新しい年度を作成してもcurrentFiscalYearは変わらない
        $unit->createFiscalYear(2025);

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
        ]);

        $this->assertDatabaseHas('accounts', [
            'id' => $account->id,
            'name' => '仮払金',
        ]);

        $this->assertCount(1, $account->subAccounts);

        $this->assertSame('仮払金', $account->subAccounts->first()->name);
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
        $this->assertEquals('源泉徴収', $subAccounts->last()->name);
    }

    #[Test]
    public function 現金の_sub_accountはレジ現金とその他現金()
    {

        $user = User::factory()->create();

        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業所',
        ]);

        $cashAccount = $businessUnit->getAccountByName('現金');

        $this->assertNotNull($cashAccount, '現金の勘定科目が見つかりません。');

        $subAccounts = $cashAccount->subAccounts;

        $this->assertCount(2, $subAccounts);
        $this->assertEquals('レジ現金', $subAccounts->first()->name);
        $this->assertEquals('その他現金', $subAccounts->last()->name);
    }

    #[Test]
    public function 新車普通車の固定資産をプリセット作成できる()
    {
        $businessUnit = BusinessUnit::factory()->create();

        $fixedAsset = $businessUnit->newStandardCarFixedAsset();

        $this->assertNotNull($fixedAsset->id);
        $this->assertSame($businessUnit->id, $fixedAsset->business_unit_id);
        $this->assertSame('新車-普通車', $fixedAsset->asset_category);
        $this->assertSame('新車-普通車', $fixedAsset->name);
        $this->assertSame(72, $fixedAsset->useful_life);
        $this->assertSame(FixedAsset::DEPRECIATION_METHOD_STRAIGHT_LINE, $fixedAsset->depreciation_method);
    }

    #[Test]
    public function 新車普通車プリセットは入力値で上書きできる()
    {
        $businessUnit = BusinessUnit::factory()->create();

        $fixedAsset = $businessUnit->newStandardCarFixedAsset([
            'name' => '営業用車両',
            'acquisition_cost' => 3_300_000,
            'depreciation_base_amount' => 3_300_000,
        ]);

        $this->assertSame('営業用車両', $fixedAsset->name);
        $this->assertSame(3_300_000, $fixedAsset->acquisition_cost);
        $this->assertSame(3_300_000, $fixedAsset->depreciation_base_amount);
        $this->assertSame(72, $fixedAsset->useful_life);
    }

    #[Test]
    public function 複数の_business_unitで異なる車両運搬具_accountが作成される()
    {
        $businessUnit1 = BusinessUnit::factory()->create();
        $businessUnit2 = BusinessUnit::factory()->create();

        $fixedAsset1 = $businessUnit1->newStandardCarFixedAsset();
        $fixedAsset2 = $businessUnit2->newStandardCarFixedAsset();

        $this->assertNotEquals($fixedAsset1->account_id, $fixedAsset2->account_id);
        $this->assertSame($businessUnit1->id, $fixedAsset1->account->business_unit_id);
        $this->assertSame($businessUnit2->id, $fixedAsset2->account->business_unit_id);
    }

    #[Test]
    public function 同じ_business_unitなら車両運搬具_accountは再利用される()
    {
        $businessUnit = BusinessUnit::factory()->create();

        $fixedAsset1 = $businessUnit->newStandardCarFixedAsset();
        $fixedAsset2 = $businessUnit->newStandardCarFixedAsset();

        // 2つの異なるFixedAssetが作成される
        $this->assertNotEquals($fixedAsset1->id, $fixedAsset2->id);
        // でもAccountは同じ（再利用される）
        $this->assertSame($fixedAsset1->account_id, $fixedAsset2->account_id);
    }

    #[Test]
    public function 新車軽自動車の固定資産をプリセット作成できる()
    {
        $businessUnit = BusinessUnit::factory()->create();

        $fixedAsset = $businessUnit->newLightCarFixedAsset();

        $this->assertNotNull($fixedAsset->id);
        $this->assertSame($businessUnit->id, $fixedAsset->business_unit_id);
        $this->assertSame('新車-軽自動車', $fixedAsset->asset_category);
        $this->assertSame('新車-軽自動車', $fixedAsset->name);
        $this->assertSame(48, $fixedAsset->useful_life);
        $this->assertSame(FixedAsset::DEPRECIATION_METHOD_STRAIGHT_LINE, $fixedAsset->depreciation_method);
    }

    #[Test]
    public function 新車軽自動車プリセットは入力値で上書きできる()
    {
        $businessUnit = BusinessUnit::factory()->create();

        $fixedAsset = $businessUnit->newLightCarFixedAsset([
            'name' => '営業用軽自動車',
            'acquisition_cost' => 1_650_000,
            'depreciation_base_amount' => 1_650_000,
        ]);

        $this->assertSame('営業用軽自動車', $fixedAsset->name);
        $this->assertSame(1_650_000, $fixedAsset->acquisition_cost);
        $this->assertSame(1_650_000, $fixedAsset->depreciation_base_amount);
        $this->assertSame(48, $fixedAsset->useful_life);
    }

    #[Test]
    public function 複数の_business_unitで異なる車両運搬具_accountが作成される_軽自動車版()
    {
        $businessUnit1 = BusinessUnit::factory()->create();
        $businessUnit2 = BusinessUnit::factory()->create();

        $fixedAsset1 = $businessUnit1->newLightCarFixedAsset();
        $fixedAsset2 = $businessUnit2->newLightCarFixedAsset();

        $this->assertNotEquals($fixedAsset1->account_id, $fixedAsset2->account_id);
        $this->assertSame($businessUnit1->id, $fixedAsset1->account->business_unit_id);
        $this->assertSame($businessUnit2->id, $fixedAsset2->account->business_unit_id);
    }

    #[Test]
    public function 同じ_business_unitなら車両運搬具_accountは再利用される_軽自動車版()
    {
        $businessUnit = BusinessUnit::factory()->create();

        $fixedAsset1 = $businessUnit->newLightCarFixedAsset();
        $fixedAsset2 = $businessUnit->newLightCarFixedAsset();

        // 2つの異なるFixedAssetが作成される
        $this->assertNotEquals($fixedAsset1->id, $fixedAsset2->id);
        // でもAccountは同じ（再利用される）
        $this->assertSame($fixedAsset1->account_id, $fixedAsset2->account_id);
    }
}
