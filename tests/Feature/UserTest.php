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
}
