<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\NextFiscalYearPrompt;
use App\Models\BusinessUnit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardNextFiscalYearPromptTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function アクセス年と現在の会計年度が異なり翌年度が未作成ならプロンプトを表示する(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 10:00:00'));

        [$user] = $this->createInitializedUser(2025);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeLivewire(NextFiscalYearPrompt::class)
            ->assertSee('2026年度の会計を始めますか？')
            ->assertSee('2026年度の会計を始める');
    }

    #[Test]
    public function アクセス年と現在の会計年度が同じならプロンプトを表示しない(): void
    {
        Carbon::setTestNow(Carbon::parse('2025-06-15 10:00:00'));

        [$user] = $this->createInitializedUser(2025);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSeeLivewire(NextFiscalYearPrompt::class);
    }

    #[Test]
    public function 翌年度が既に作成されていればプロンプトを表示しない(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 10:00:00'));

        [$user, $unit] = $this->createInitializedUser(2025);
        $unit->createFiscalYear(2026, $user);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSeeLivewire(NextFiscalYearPrompt::class);
    }

    #[Test]
    public function startアクションで翌年度を作成し現在の会計年度に切り替える(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 10:00:00'));

        [$user, $unit] = $this->createInitializedUser(2025);
        $originalFiscalYearId = $unit->currentFiscalYear->id;

        Livewire::actingAs($user)
            ->test(NextFiscalYearPrompt::class, ['businessUnit' => $unit])
            ->call('start')
            ->assertRedirect(route('dashboard'));

        $unit->refresh();
        $nextFiscalYear = $unit->fiscalYears()->where('year', 2026)->first();

        $this->assertNotNull($nextFiscalYear, '2026年度が作成されていること');
        $this->assertSame($nextFiscalYear->id, $unit->current_fiscal_year_id);
        $this->assertTrue((bool) $nextFiscalYear->is_active);
        $this->assertNotSame($originalFiscalYearId, $unit->current_fiscal_year_id);
    }

    #[Test]
    public function 翌年度がすでに存在するときにstartを呼ぶとエラーメッセージを表示する(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 10:00:00'));

        [$user, $unit] = $this->createInitializedUser(2025);
        $unit->createFiscalYear(2026, $user);

        Livewire::actingAs($user)
            ->test(NextFiscalYearPrompt::class, ['businessUnit' => $unit])
            ->call('start')
            ->assertSet('errorMessage', '翌年度はすでに作成されています。')
            ->assertNoRedirect();
    }

    /**
     * @return array{0: User, 1: BusinessUnit}
     */
    private function createInitializedUser(int $year): array
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $unit->createFiscalYear($year, $user);
        $unit->refresh();

        return [$user, $unit];
    }
}
