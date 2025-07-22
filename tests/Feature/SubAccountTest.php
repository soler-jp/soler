<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\SubAccount;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

        $this->expectException(\Illuminate\Database\QueryException::class);

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
}
