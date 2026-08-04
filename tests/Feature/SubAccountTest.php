<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BusinessUnit;
use App\Models\SubAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubAccountTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 同じ勘定科目内では補助科目名の重複はできない()
    {
        $account = Account::factory()->create();

        SubAccount::create([
            'account_id' => $account->id,
            'name' => '営業部',
        ]);

        $this->expectException(QueryException::class);

        SubAccount::create([
            'account_id' => $account->id,
            'name' => '営業部', // 同一 account_id 内で重複
        ]);
    }

    #[Test]
    public function 異なる勘定科目なら同じ補助科目名でも登録できる()
    {
        $account1 = Account::factory()->create();
        $account2 = Account::factory()->create();

        $sa1 = SubAccount::create([
            'account_id' => $account1->id,
            'name' => '営業部',
        ]);

        $sa2 = SubAccount::create([
            'account_id' => $account2->id,
            'name' => '営業部', // OK: account_id が異なる
        ]);

        $this->assertDatabaseHas('sub_accounts', ['id' => $sa1->id]);
        $this->assertDatabaseHas('sub_accounts', ['id' => $sa2->id]);
    }

    #[Test]
    public function add_custom_sub_accountで補助科目を追加できる(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => '補助科目追加テスト',
        ]);
        $account = $businessUnit->accounts()->create([
            'name' => '会議費',
            'type' => Account::TYPE_EXPENSE,
        ]);

        $subAccount = $account->addCustomSubAccount('役員会議', $user);

        $this->assertDatabaseHas('sub_accounts', [
            'id' => $subAccount->id,
            'account_id' => $account->id,
            'name' => '役員会議',
        ]);
    }

    #[Test]
    public function add_custom_sub_accountは空名を許可しない(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create([
            'business_unit_id' => $user->createBusinessUnitWithDefaults([
                'name' => '空名補助科目テスト',
            ])->id,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('name は必須です。');

        $account->addCustomSubAccount('', $user);
    }

    #[Test]
    public function add_custom_sub_accountは他人の事業体には追加できない(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $businessUnit = $owner->createBusinessUnitWithDefaults([
            'name' => '認可テスト',
        ]);

        $account = $businessUnit->accounts()->create([
            'name' => '会議費',
            'type' => Account::TYPE_EXPENSE,
        ]);

        $this->expectException(AuthorizationException::class);

        $account->addCustomSubAccount('役員会議', $otherUser);
    }

    #[Test]
    public function add_custom_sub_accountは既定でstandard_visibilityになる(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'visibility 既定テスト',
        ]);
        $account = $businessUnit->accounts()->create([
            'name' => 'テスト費用',
            'type' => Account::TYPE_EXPENSE,
        ]);

        $subAccount = $account->addCustomSubAccount('社内勉強会', $user);

        $this->assertSame(SubAccount::VISIBILITY_STANDARD, $subAccount->visibility);
        $this->assertNull($subAccount->system_purpose);
    }

    #[Test]
    public function add_custom_sub_accountはvisibilityと_system_purposeを明示指定できる(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'visibility 指定テスト',
        ]);
        $account = $businessUnit->accounts()->create([
            'name' => 'テスト費用2',
            'type' => Account::TYPE_EXPENSE,
        ]);

        $subAccount = $account->addCustomSubAccount(
            '内部利用',
            $user,
            SubAccount::VISIBILITY_EXPANDED,
        );

        $this->assertSame(SubAccount::VISIBILITY_EXPANDED, $subAccount->visibility);
    }

    #[Test]
    public function 既定シードでは標準リストに含まれる_sub_accountはstandardになる(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => '既定シード standard テスト',
        ]);

        foreach (BusinessUnit::$standardDefaultSubAccounts as $name) {
            $sub = $businessUnit->subAccounts()->where('sub_accounts.name', $name)->first();
            $this->assertNotNull($sub, "$name の SubAccount が見つかりません。");
            $this->assertSame(
                SubAccount::VISIBILITY_STANDARD,
                $sub->visibility,
                "$name は standard で登録される想定です。",
            );
        }
    }

    #[Test]
    public function 既定シードでは標準リストに含まれない_sub_accountはexpandedになる(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => '既定シード expanded テスト',
        ]);

        // 標準リストに含まれない既定 SubAccount のサンプル
        $expandedSamples = ['現金', '水道光熱費', '通信費', '源泉徴収'];

        foreach ($expandedSamples as $name) {
            $sub = $businessUnit->subAccounts()->where('sub_accounts.name', $name)->first();
            $this->assertNotNull($sub, "$name の SubAccount が見つかりません。");
            $this->assertSame(
                SubAccount::VISIBILITY_EXPANDED,
                $sub->visibility,
                "$name は expanded で登録される想定です。",
            );
        }
    }

    #[Test]
    public function add_custom_sub_accountは既定で_sort_orderがdefault値になる(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'sort_order 既定テスト',
        ]);
        $account = $businessUnit->accounts()->create([
            'name' => 'テスト費用sort',
            'type' => Account::TYPE_EXPENSE,
        ]);

        $subAccount = $account->addCustomSubAccount('社内勉強会', $user);

        $this->assertSame(SubAccount::SORT_ORDER_DEFAULT, $subAccount->sort_order);
    }

    #[Test]
    public function 既定シードでは優先リストの_sub_accountに順序値が付く(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'sort_order 優先リストテスト',
        ]);

        foreach (BusinessUnit::$prioritizedDefaultSubAccounts as $index => $name) {
            $expected = ($index + 1) * 10;
            $sub = $businessUnit->subAccounts()->where('sub_accounts.name', $name)->first();
            $this->assertNotNull($sub, "$name の SubAccount が見つかりません。");
            $this->assertSame($expected, $sub->sort_order, "$name の sort_order 想定値 $expected");
        }
    }

    #[Test]
    public function 既定シードでは優先リスト外の_sub_accountはdefault値になる(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'sort_order 非優先テスト',
        ]);

        foreach (['現金', '通信費', '水道光熱費'] as $name) {
            $sub = $businessUnit->subAccounts()->where('sub_accounts.name', $name)->first();
            $this->assertNotNull($sub);
            $this->assertSame(SubAccount::SORT_ORDER_DEFAULT, $sub->sort_order);
        }
    }

    #[Test]
    public function 既定シードでは非表示リストの_sub_accountはhiddenになる(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'hidden 既定シードテスト',
        ]);

        foreach (BusinessUnit::$hiddenDefaultSubAccounts as $name) {
            $sub = $businessUnit->subAccounts()->where('sub_accounts.name', $name)->first();
            $this->assertNotNull($sub, "$name の SubAccount が見つかりません。");
            $this->assertSame(
                SubAccount::VISIBILITY_HIDDEN,
                $sub->visibility,
                "$name は hidden で登録される想定です。",
            );
        }
    }

    #[Test]
    public function 既定シードでは特定の_sub_accountに_ui_labelが付与される(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'ui_label 既定シードテスト',
        ]);

        $expected = [
            ['売掛金', '売掛金', '後日入金予定'],
            ['事業主貸', '事業主貸', '個人の財布に入金'],
            ['事業主借', '事業主借', '個人の財布・個人のクレジットカードで支払い'],
            ['買掛金', '買掛金', '後日支払い予定'],
            [
                BusinessUnit::UNCLASSIFIED_EXPENSE_ACCOUNT_NAME,
                BusinessUnit::UNCLASSIFIED_EXPENSE_SUB_ACCOUNT_NAME,
                '後から決める',
            ],
        ];

        foreach ($expected as [$accountName, $subAccountName, $label]) {
            $sub = $businessUnit->getSubAccountByName($accountName, $subAccountName);
            $this->assertNotNull($sub, "$accountName / $subAccountName の SubAccount が見つかりません。");
            $this->assertSame($label, $sub->ui_label, "$accountName / $subAccountName の ui_label 想定値: $label");
        }
    }

    #[Test]
    public function 既定シードで_ui_labelの付かない_sub_accountはnullのまま(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'ui_label 非対象テスト',
        ]);

        foreach (['現金', '通信費', '水道光熱費', '源泉徴収'] as $name) {
            $sub = $businessUnit->subAccounts()->where('sub_accounts.name', $name)->first();
            $this->assertNotNull($sub, "$name の SubAccount が見つかりません。");
            $this->assertNull($sub->ui_label, "$name には ui_label が付かない想定です。");
        }
    }

    #[Test]
    public function 未分類_sub_accountはunclassified_system_purposeが付く(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'unclassified system_purpose テスト',
        ]);

        $unclassified = $businessUnit->getSubAccountByName(
            BusinessUnit::UNCLASSIFIED_EXPENSE_ACCOUNT_NAME,
            BusinessUnit::UNCLASSIFIED_EXPENSE_SUB_ACCOUNT_NAME,
        );

        $this->assertNotNull($unclassified);
        $this->assertSame(SubAccount::PURPOSE_UNCLASSIFIED, $unclassified->system_purpose);
    }

    #[Test]
    public function display_nameはui_labelが最優先される(): void
    {
        $account = Account::factory()->create(['name' => '売掛金']);
        // AccountFactory は同名の SubAccount を自動生成するのでそれを使う。
        $subAccount = $account->subAccounts()->where('name', '売掛金')->firstOrFail();
        $subAccount->update(['ui_label' => '後日入金予定']);

        $this->assertSame('後日入金予定', $subAccount->displayName());
    }

    #[Test]
    public function display_nameはui_labelが未設定ならsub名を返す(): void
    {
        $account = Account::factory()->create(['name' => '普通預金']);
        $subAccount = SubAccount::create([
            'account_id' => $account->id,
            'name' => 'A銀行',
        ]);

        $this->assertSame('A銀行', $subAccount->displayName());
    }

    #[Test]
    public function display_nameはaccount名と同名でもsub名だけを返す(): void
    {
        // Account 名は含めない設計。Account か SubAccount かは UI 側の文脈で示す。
        $account = Account::factory()->create(['name' => '現金']);
        $subAccount = $account->subAccounts()->where('name', '現金')->firstOrFail();

        $this->assertSame('現金', $subAccount->displayName());
    }

    #[Test]
    public function display_nameはui_labelが空文字や空白だけならsub名にフォールバックする(): void
    {
        $account = Account::factory()->create(['name' => '普通預金']);
        $subAccount = SubAccount::create([
            'account_id' => $account->id,
            'name' => 'A銀行',
            'ui_label' => '   ',
        ]);

        $this->assertSame('A銀行', $subAccount->displayName());
    }

    #[Test]
    public function 不正な_visibility値は保存時に拒否される(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'visibility 不正値テスト',
        ]);
        $account = $businessUnit->accounts()->create([
            'name' => '不正値テスト費用',
            'type' => Account::TYPE_EXPENSE,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SubAccount::visibility の値が不正です');

        $account->addCustomSubAccount('X', $user, 'bogus');
    }

    #[Test]
    public function 不正な_system_purpose値は保存時に拒否される(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'system_purpose 不正値テスト',
        ]);
        $account = $businessUnit->accounts()->create([
            'name' => '不正値テスト費用2',
            'type' => Account::TYPE_EXPENSE,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SubAccount::system_purpose の値が不正です');

        $account->addCustomSubAccount('Y', $user, SubAccount::VISIBILITY_STANDARD, 'bogus');
    }
}
