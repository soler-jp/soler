<?php

namespace Tests\Unit;

use App\Models\BusinessUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

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
            'type' => 'required|in:' . implode(',', BusinessUnit::TYPES),
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
            'type' => 'required|in:' . implode(',', BusinessUnit::TYPES),
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
    public function createWithDefaultAccountsは標準勘定科目を登録する()
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
    public function nameでAccountを取得できる()
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
    public function taxPaidAccountは仮払消費税のAccountを返す()
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
    public function taxReceivedAccountは仮受消費税のAccountを返す()
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
    public function taxPaidAccountが存在しないと例外を投げる()
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業']);

        $unit->taxPaidAccount();
    }

    #[Test]
    public function currentFiscalYearを取得できる()
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
    public function currentFiscalYearを設定できる()
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);

        $fiscalYear2025 = $businessUnit->createFiscalYear(2025);

        $businessUnit->setCurrentFiscalYear($fiscalYear2025);

        $this->assertEquals($fiscalYear2025->id, $businessUnit->current_fiscal_year_id);
        $this->assertTrue($businessUnit->currentFiscalYear->is($fiscalYear2025));
    }

    #[Test]
    public function 他の事業体のFiscalYearを設定しようとすると例外が発生する()
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
    public function createFiscalYearで初回年度が自動で選択される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '初期事業体']);

        $fiscal = $unit->createFiscalYear(2025);

        $this->assertTrue($unit->currentFiscalYear->is($fiscal));
    }

    #[Test]
    public function 既にcurrentFiscalYearがあれば、createしても選択は変わらない()
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
            $this->assertEquals($name, $account->name, "勘定科目の名前が期待と異なります。");
        }
    }

    #[Test]
    public function よく使うSubAccountのgetterテスト()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);

        $names = [
            '事業主貸' => [
                '源泉徴収',
            ]
        ];

        foreach ($names as $accountName => $subAccounts) {
            $account = $unit->getAccountByName($accountName);
            $this->assertNotNull($account, "勘定科目 '{$accountName}' が見つかりません。");


            foreach ($subAccounts as $name) {
                $subAccount = $unit->getSubAccountByName($accountName, $name);
                $this->assertNotNull($subAccount, "サブ勘定科目 '{$name}' が見つかりません。");
                $this->assertEquals($name, $subAccount->name, "サブ勘定科目の名前が期待と異なります。");
            }
        }
    }
}
