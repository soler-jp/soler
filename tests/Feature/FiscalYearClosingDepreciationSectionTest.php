<?php

namespace Tests\Feature;

use App\Livewire\FiscalYearClosing\DepreciationSection;
use App\Models\BusinessUnit;
use App\Models\DepreciationEntry;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DepreciationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FiscalYearClosingDepreciationSectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: BusinessUnit, 2: FiscalYear}
     */
    private function createUserWithFiscalYear(int $year = 2025): array
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '減価償却テスト事業体']);
        $fiscalYear = $unit->createFiscalYear($year, $user);

        return [$user, $unit->refresh(), $fiscalYear];
    }

    private function createDepreciableCar(BusinessUnit $unit, string $name, string $acquisitionDate): FixedAsset
    {
        return FixedAsset::factory()->create([
            'business_unit_id' => $unit->id,
            'account_id' => $unit->getAccountByName('車両運搬具')->id,
            'name' => $name,
            'acquisition_date' => $acquisitionDate,
            'taxable_amount' => 1_200_000,
            'tax_amount' => 120_000,
            'useful_life' => 72,
        ]);
    }

    #[Test]
    public function 償却対象がなければ空メッセージを表示する(): void
    {
        [$user, , $fiscalYear] = $this->createUserWithFiscalYear();

        Livewire::actingAs($user)
            ->test(DepreciationSection::class, ['fiscalYearId' => $fiscalYear->id])
            ->assertSee(__('fiscal_year_closing.depreciation.no_assets'));
    }

    #[Test]
    public function 償却対象ごとに当年度の償却額と割合入力欄を表示する(): void
    {
        [$user, $unit, $fiscalYear] = $this->createUserWithFiscalYear();
        $this->createDepreciableCar($unit, 'PRIUS', '2025-01-15');

        app(DepreciationService::class)->prepareEntriesFor($fiscalYear, $user);

        $entry = DepreciationEntry::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->firstOrFail();

        Livewire::actingAs($user)
            ->test(DepreciationSection::class, ['fiscalYearId' => $fiscalYear->id])
            ->assertSee('PRIUS')
            ->assertSee(number_format($entry->total_amount))
            ->assertSet("businessUsagePercents.{$entry->id}", 100);
    }

    #[Test]
    public function 割合を変更して計上すると仕訳と経費計上額が更新される(): void
    {
        [$user, $unit, $fiscalYear] = $this->createUserWithFiscalYear();
        $this->createDepreciableCar($unit, 'PRIUS', '2025-01-15');

        app(DepreciationService::class)->prepareEntriesFor($fiscalYear, $user);

        $entry = DepreciationEntry::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->firstOrFail();
        $expectedDeductible = (int) floor((int) $entry->total_amount * 0.5);

        Livewire::actingAs($user)
            ->test(DepreciationSection::class, ['fiscalYearId' => $fiscalYear->id])
            ->set("businessUsagePercents.{$entry->id}", 50)
            ->call('post', $entry->id)
            ->assertSet('errorMessage', null);

        $entry->refresh();
        $this->assertSame(0.5, (float) $entry->business_usage_ratio);
        $this->assertSame($expectedDeductible, (int) $entry->deductible_amount);
        $this->assertNotNull($entry->transaction_id);

        $this->assertTrue(
            $fiscalYear->transactions()
                ->where('adjusting_entry_type', Transaction::ADJUSTING_ENTRY_TYPE_DEPRECIATION)
                ->exists(),
        );
    }

    #[Test]
    public function 他ユーザーの会計年度は減価償却セクションでも読み込めない(): void
    {
        [, $ownerUnit, $ownerFiscalYear] = $this->createUserWithFiscalYear();
        $this->createDepreciableCar($ownerUnit, 'PRIUS', '2025-01-15');

        $attacker = User::factory()->create();
        $attacker->createBusinessUnitWithDefaults(['name' => '攻撃者事業体']);

        Livewire::actingAs($attacker)
            ->test(DepreciationSection::class, ['fiscalYearId' => $ownerFiscalYear->id])
            ->assertStatus(403);
    }

    #[Test]
    public function 空入力の割合はエラーメッセージで拒否され計上されない(): void
    {
        [$user, $unit, $fiscalYear] = $this->createUserWithFiscalYear();
        $this->createDepreciableCar($unit, 'PRIUS', '2025-01-15');

        app(DepreciationService::class)->prepareEntriesFor($fiscalYear, $user);

        $entry = DepreciationEntry::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->firstOrFail();

        Livewire::actingAs($user)
            ->test(DepreciationSection::class, ['fiscalYearId' => $fiscalYear->id])
            ->set("businessUsagePercents.{$entry->id}", '')
            ->call('post', $entry->id)
            ->assertSet('errorMessage', __('fiscal_year_closing.depreciation.invalid_percent'));

        $entry->refresh();
        $this->assertNull($entry->transaction_id);
    }

    #[Test]
    public function 計上済みの明細は計上済み表示になりフォームは出ない(): void
    {
        [$user, $unit, $fiscalYear] = $this->createUserWithFiscalYear();
        $this->createDepreciableCar($unit, 'PRIUS', '2025-01-15');

        app(DepreciationService::class)->prepareEntriesFor($fiscalYear, $user);

        $entry = DepreciationEntry::query()
            ->where('fiscal_year_id', $fiscalYear->id)
            ->firstOrFail();

        app(DepreciationService::class)->postWithRatio($entry, 1.0, $user);

        Livewire::actingAs($user)
            ->test(DepreciationSection::class, ['fiscalYearId' => $fiscalYear->id])
            ->assertSee(__('fiscal_year_closing.depreciation.already_posted', [
                'amount' => number_format($entry->refresh()->deductible_amount),
            ]))
            ->assertDontSee(__('fiscal_year_closing.depreciation.post_button'));
    }
}
