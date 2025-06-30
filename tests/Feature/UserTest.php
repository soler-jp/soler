<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;

class UserTest extends TestCase
{

    #[Test]
    public function selectedBusinessUnitを取得できる()
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
    public function createBusinessUnitWithDefaultsでCurrentBusinessUnitが設定される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '新規事業体']);

        $this->assertEquals($unit->id, $user->current_business_unit_id);
        $this->assertTrue($user->selectedBusinessUnit->is($unit));
    }

    #[Test]
    public function currentBusinessUnitが未設定の場合はnullを返す()
    {
        $user = User::factory()->create([
            'current_business_unit_id' => null,
        ]);

        $this->assertNull($user->selectedBusinessUnit);
    }

    #[Test]
    public function currentBusinessUnitが削除されたらnullになる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '削除予定']);

        $user->update(['current_business_unit_id' => $unit->id]);
        $unit->delete();

        $user->refresh();

        $this->assertNull($user->selectedBusinessUnit);
    }


    #[Test]
    public function setSelectedBusinessUnitで選択できる()
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
}
