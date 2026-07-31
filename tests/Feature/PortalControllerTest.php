<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PortalControllerTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 事業体未作成のユーザーはinitializeへリダイレクトされる(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('initialize'));
    }

    #[Test]
    public function 選択中事業体が未所属ならダッシュボードを拒否する(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherUnit = $otherUser->createBusinessUnitWithDefaults(['name' => '他人の事業体']);

        $user->forceFill([
            'current_business_unit_id' => $otherUnit->id,
        ])->save();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertForbidden();
    }

    #[Test]
    public function initialize画面ではサイドメニューを表示しない(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('initialize'))
            ->assertOk()
            ->assertSee('初期セットアップ')
            ->assertDontSee('Main Menu');
    }
}
