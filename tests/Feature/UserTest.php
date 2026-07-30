<?php

namespace Tests\Feature;

use App\Models\FixedAsset;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function selected_business_unitを取得できる()
    {
        $user = User::factory()->create();
        $unit1 = $user->createBusinessUnitWithDefaults(['name' => '事業体A']);
        $unit2 = $user->createBusinessUnitWithDefaults(['name' => '事業体B']);

        $user->update([
            'current_business_unit_id' => $unit2->id,
        ]);

        $this->assertEquals('事業体B', $user->selectedBusinessUnit->name);
        $this->assertTrue($user->selectedBusinessUnit->is($unit2));
    }

    #[Test]
    public function create_business_unit_with_defaultsで_current_business_unitが設定される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '新規事業体']);

        $this->assertEquals($unit->id, $user->current_business_unit_id);
        $this->assertTrue($user->selectedBusinessUnit->is($unit));
    }

    #[Test]
    public function current_business_unitが未設定の場合はnullを返す()
    {
        $user = User::factory()->create([
            'current_business_unit_id' => null,
        ]);

        $this->assertNull($user->selectedBusinessUnit);
    }

    #[Test]
    public function current_business_unitが削除されたらnullになる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '削除予定']);

        $user->update(['current_business_unit_id' => $unit->id]);
        $unit->delete();

        $user->refresh();

        $this->assertNull($user->selectedBusinessUnit);
    }

    #[Test]
    public function set_selected_business_unitで選択できる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '設定対象']);

        $user->setSelectedBusinessUnit($unit);

        $this->assertEquals($unit->id, $user->current_business_unit_id);
        $this->assertTrue($user->selectedBusinessUnit->is($unit));
    }

    #[Test]
    public function 他人の事業体を選択しようとすると例外が発生する()
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $unitB = $userB->createBusinessUnitWithDefaults(['name' => '他人の事業体']);

        $this->expectException(\InvalidArgumentException::class);

        $userA->setSelectedBusinessUnit($unitB);
    }

    #[Test]
    public function selected_business_unit_or_failは未選択なら例外を投げる(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->current_business_unit_id);

        $this->expectException(AuthorizationException::class);

        $user->selectedBusinessUnitOrFail();
    }

    #[Test]
    public function userを削除すると関連する事業体と仕訳データも削除できる()
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '削除対象事業体']);
        $fiscalYear = $businessUnit->createFiscalYear(2025, $user);

        $cashSubAccount = $businessUnit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $salesSubAccount = $businessUnit->getAccountByName('売上高')->subAccounts()->firstOrFail();
        $fixedAssetAccount = $businessUnit->getAccountByName('車両運搬具');

        $transaction = $fiscalYear->registerTransaction(
            [
                'date' => '2025-01-01',
                'description' => 'テスト売上',
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

        $fixedAsset = FixedAsset::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'account_id' => $fixedAssetAccount->id,
        ]);

        $user->delete();

        $this->assertModelMissing($user);
        $this->assertModelMissing($businessUnit);
        $this->assertModelMissing($fiscalYear);
        $this->assertModelMissing($transaction);
        $this->assertModelMissing($fixedAsset);
    }
}
