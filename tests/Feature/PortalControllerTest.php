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
            ->assertSee('選んでくれてありがとう')
            ->assertSee('以下の3ステップで始めていきましょう')
            ->assertSee('Solerを始める')
            ->assertSee('会計の基本')
            ->assertSee(':disabled="guidePage === 1"', false)
            ->assertDontSee('Main Menu');
    }

    #[Test]
    public function helpから会計説明をいつでも読み直せる(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('help.accounting-basics'))
            ->assertOk()
            ->assertSee('個人事業主としての会計の説明')
            ->assertSee('事業のお金は、まず3つに分けます')
            ->assertSee('Help');
    }
}
