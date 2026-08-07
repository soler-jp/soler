<?php

namespace Tests\Feature;

use App\Exceptions\PhysicalDeletionNotAllowed;
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
    public function business_unitの物理削除は拒否される(): void
    {
        // 監査ログ導入に伴い、BusinessUnit の物理削除はサポートしない。
        // 削除は将来の退会・匿名化フローで扱う。
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '削除予定']);

        $this->expectException(PhysicalDeletionNotAllowed::class);
        $this->expectExceptionMessageMatches('/物理削除は許可されていません/');

        $unit->delete();
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
    public function userの物理削除は拒否される(): void
    {
        // 監査ログ (actor_id) や会計データが長期保存対象になるため、
        // User の物理削除はサポートしない。
        $user = User::factory()->create();
        $user->createBusinessUnitWithDefaults(['name' => '対象事業体']);

        $this->expectException(PhysicalDeletionNotAllowed::class);
        $this->expectExceptionMessageMatches('/物理削除は許可されていません/');

        $user->delete();
    }
}
