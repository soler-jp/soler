<?php

namespace Tests\Feature;

use App\Models\Account;
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
}
