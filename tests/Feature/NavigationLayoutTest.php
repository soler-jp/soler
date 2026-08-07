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

        $response = $this->actingAs($user)->get(route('help.accounting-basics'));

        $response->assertOk();
        $response->assertSee('年度未設定');
        $response->assertSee(__('navigation.revenue'));
        $response->assertSee(__('navigation.expense'));
        $response->assertSee(__('navigation.fixed_expenses'));
        $response->assertSee(__('navigation.purchase'));
        $response->assertSee(__('navigation.expense_monthly'));
        $response->assertSee(__('navigation.account_summary'));
        $response->assertSee(__('navigation.audit_logs'));
        $response->assertSee(__('navigation.fiscal_years'));
        $response->assertSee(__('Dashboard'));
        $response->assertSee('青色申告決算書PDF');
        $response->assertSee('Help');
        $response->assertSee('brand/logo-mark-light.png');
        $response->assertDontSee('ユーザー管理');
    }

    #[Test]
    public function 管理者には管理メニューが表示される(): void
    {
        $admin = User::factory()->create([
            'is_admin' => true,
        ]);

        $response = $this->actingAs($admin)->get(route('help.accounting-basics'));

        $response->assertOk();
        $response->assertSee('ユーザー管理');
    }

    #[Test]
    public function 現在の事業年度がヘッダーに表示される(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業']);
        $unit->createFiscalYear(2025, $user);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('2025年度');
    }
}
