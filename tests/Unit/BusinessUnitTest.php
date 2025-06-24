<?php

namespace Tests\Unit;

use App\Models\BusinessUnit;
use App\Models\User;
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
}
