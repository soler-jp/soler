<?php

namespace Tests\Feature;

use App\Livewire\Pages\FiscalYearIndex;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FiscalYearPageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 年度管理ページで年度一覧と操作導線を表示できる(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '年度管理テスト事業']);
        $fiscalYear2025 = $unit->createFiscalYear(2025, $user);
        $fiscalYear2025->update([
            'is_taxable' => true,
            'is_tax_exclusive' => false,
        ]);
        $fiscalYear2025->close($user);

        $fiscalYear2024 = $unit->createFiscalYear(2024, $user);
        $unit->activateFiscalYear($fiscalYear2024, $user);

        $response = $this->actingAs($user)->get(route('fiscal-years.index'));

        $response->assertOk();
        $response->assertSeeLivewire(FiscalYearIndex::class);
        $response->assertSee('年度管理');
        $response->assertSee('2025年度');
        $response->assertSee('2024年度');
        $response->assertSee('この年度を見る');
        $response->assertSee('この年度を締める');
        $response->assertSee('2026年度の繰越内容を確認');
    }
}
