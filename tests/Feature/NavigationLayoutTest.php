<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NavigationLayoutTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 認証ユーザーにはサイドナビゲーションが表示される(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('initialize'));

        $response->assertOk();
        $response->assertSee('年度未設定');
        $response->assertSee('売上一覧');
        $response->assertSee('経費の月別一覧');
        $response->assertSee('勘定科目集計');
        $response->assertSee('固定費');
        $response->assertSee('青色申告決算書PDF');
        $response->assertSee('brand/logo-mark-light.png');
        $response->assertDontSee('ユーザー管理');
    }

    #[Test]
    public function 管理者には管理メニューが表示される(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
        ]);

        $response = $this->actingAs($admin)->get(route('initialize'));

        $response->assertOk();
        $response->assertSee('ユーザー管理');
    }

    #[Test]
    public function 現在の事業年度がヘッダーに表示される(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業']);
        $unit->createFiscalYear(2025);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('2025年度');
    }
}
