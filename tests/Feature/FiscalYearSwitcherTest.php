<?php

namespace Tests\Feature;

use App\Livewire\Layout\FiscalYearSwitcher;
use App\Models\BusinessUnit;
use App\Models\FiscalYear;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FiscalYearSwitcherTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function 現在が過去年度で未締めの後続年度があるとstoneテーマとバナーを表示する(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 10:00:00'));

        [$user, $unit] = $this->createInitializedUser(2025);
        $unit->createFiscalYear(2026, $user);
        $unit->update(['current_fiscal_year_id' => $unit->fiscalYears()->where('year', 2025)->first()->id]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-theme="stone"', false)
            ->assertSeeLivewire(FiscalYearSwitcher::class)
            ->assertSee('これは 2025 年度です。')
            ->assertSee('2026 年度に切り替える');
    }

    #[Test]
    public function 単一の_f_yしかなければバナーもstoneテーマも適用しない(): void
    {
        Carbon::setTestNow(Carbon::parse('2025-06-15 10:00:00'));

        [$user] = $this->createInitializedUser(2025);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-theme="default"', false)
            ->assertDontSee('年度に切り替える');
    }

    #[Test]
    public function 現在が当年で未締めの過去年度が残っていれば切替ボタンを表示する(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00'));

        [$user, $unit] = $this->createInitializedUser(2025);
        $unit->createFiscalYear(2026, $user);
        $unit->update(['current_fiscal_year_id' => $unit->fiscalYears()->where('year', 2026)->first()->id]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-theme="default"', false)
            ->assertSee('これは 2026 年度です。')
            ->assertSee('2025 年度に切り替える');
    }

    #[Test]
    public function 過去年度でも未締めの後続年度が無ければstoneテーマもバナーの後続年度ボタンも出ない(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-03-15 10:00:00'));

        [$user, $unit] = $this->createInitializedUser(2025);
        $closed2026 = $unit->createFiscalYear(2026, $user);
        $this->closeFiscalYear($closed2026);
        $unit->update(['current_fiscal_year_id' => $unit->fiscalYears()->where('year', 2025)->first()->id]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-theme="default"', false)
            ->assertDontSee('2026 年度に切り替える');
    }

    #[Test]
    public function 未締めの切替候補が複数あればそれぞれのボタンを年度昇順で表示する(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-03-15 10:00:00'));

        [$user, $unit] = $this->createInitializedUser(2025);
        $unit->createFiscalYear(2026, $user);
        $unit->createFiscalYear(2027, $user);
        $unit->update(['current_fiscal_year_id' => $unit->fiscalYears()->where('year', 2025)->first()->id]);

        $response = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();

        $response->assertSee('2026 年度に切り替える');
        $response->assertSee('2027 年度に切り替える');
        $response->assertSeeInOrder(['2026 年度に切り替える', '2027 年度に切り替える']);
    }

    #[Test]
    public function 締め済みの年度は切替ボタンから除外される(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-03-15 10:00:00'));

        [$user, $unit] = $this->createInitializedUser(2025);
        $closed2026 = $unit->createFiscalYear(2026, $user);
        $this->closeFiscalYear($closed2026);
        $unit->createFiscalYear(2027, $user);
        $unit->update(['current_fiscal_year_id' => $unit->fiscalYears()->where('year', 2025)->first()->id]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('2027 年度に切り替える')
            ->assertDontSee('2026 年度に切り替える');
    }

    #[Test]
    public function 現在の会計年度自身は切替ボタンに含まれない(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 10:00:00'));

        [$user, $unit] = $this->createInitializedUser(2025);
        $unit->createFiscalYear(2026, $user);
        $unit->update(['current_fiscal_year_id' => $unit->fiscalYears()->where('year', 2025)->first()->id]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('2025 年度に切り替える');
    }

    #[Test]
    public function switch_toで指定した会計年度をcurrentに切り替える(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-03-15 10:00:00'));

        [$user, $unit] = $this->createInitializedUser(2025);
        $unit->createFiscalYear(2026, $user);
        $fy2027 = $unit->createFiscalYear(2027, $user);
        $currentFy = $unit->fiscalYears()->where('year', 2025)->first();
        $unit->update(['current_fiscal_year_id' => $currentFy->id]);

        Livewire::actingAs($user)
            ->test(FiscalYearSwitcher::class)
            ->call('switchTo', $fy2027->id)
            ->assertRedirect();

        $unit->refresh();
        $this->assertSame($fy2027->id, $unit->current_fiscal_year_id);
        $this->assertTrue((bool) $fy2027->fresh()->is_active);
    }

    #[Test]
    public function switch_toで過去方向の未締め年度にも切り替えられる(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 10:00:00'));

        [$user, $unit] = $this->createInitializedUser(2025);
        $unit->createFiscalYear(2026, $user);
        $currentFy = $unit->fiscalYears()->where('year', 2026)->first();
        $unit->update(['current_fiscal_year_id' => $currentFy->id]);
        $pastFy = $unit->fiscalYears()->where('year', 2025)->first();

        Livewire::actingAs($user)
            ->test(FiscalYearSwitcher::class)
            ->call('switchTo', $pastFy->id)
            ->assertRedirect();

        $unit->refresh();
        $this->assertSame($pastFy->id, $unit->current_fiscal_year_id);
    }

    #[Test]
    public function switch_toに締め済みの会計年度idを渡すとエラーメッセージを表示する(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-03-15 10:00:00'));

        [$user, $unit] = $this->createInitializedUser(2025);
        $closed2026 = $unit->createFiscalYear(2026, $user);
        $this->closeFiscalYear($closed2026);
        $unit->createFiscalYear(2027, $user);
        $currentFy = $unit->fiscalYears()->where('year', 2025)->first();
        $unit->update(['current_fiscal_year_id' => $currentFy->id]);

        Livewire::actingAs($user)
            ->test(FiscalYearSwitcher::class)
            ->call('switchTo', $closed2026->id)
            ->assertSet('errorMessage', '切り替え先の会計年度が見つかりません。')
            ->assertNoRedirect();
    }

    private function closeFiscalYear(FiscalYear $fiscalYear): void
    {
        $fiscalYear->forceFill([
            'is_closed' => true,
            'closed_at' => now(),
        ])->saveOrFail();
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
