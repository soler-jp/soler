<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Pages\FiscalYearIndex;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\TransactionRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FiscalYearIndexTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 表示する年度を切り替えられる(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '切替テスト事業']);
        $fiscalYear2024 = $unit->createFiscalYear(2024, $user);
        $fiscalYear2025 = $unit->createFiscalYear(2025, $user);

        Livewire::actingAs($user)
            ->test(FiscalYearIndex::class)
            ->call('switchFiscalYear', $fiscalYear2025->id)
            ->assertSee('2025年度を表示中に切り替えました。');

        $unit->refresh();

        $this->assertSame($fiscalYear2025->id, $unit->current_fiscal_year_id);
        $this->assertTrue($fiscalYear2025->fresh()->is_active);
        $this->assertFalse($fiscalYear2024->fresh()->is_active);
    }

    #[Test]
    public function 進行中の年度を締められる(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '締めテスト事業']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        Livewire::actingAs($user)
            ->test(FiscalYearIndex::class)
            ->call('openCloseConfirm', $fiscalYear->id)
            ->assertSet('showCloseConfirmModal', true)
            ->assertSee('この年度は締められます。')
            ->call('confirmCloseFiscalYear')
            ->assertSee('2025年度を締めました。');

        $this->assertTrue((bool) $fiscalYear->fresh()->is_closed);
        $this->assertFalse((bool) $fiscalYear->fresh()->is_active);
    }

    #[Test]
    public function 締め済み年度から翌年度を繰越作成して自動で切り替えられる(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '繰越作成テスト事業']);
        $closedYear = $unit->createFiscalYear(2025, $user);
        $closedYear->update([
            'is_taxable' => true,
            'is_tax_exclusive' => false,
        ]);

        $cash = $unit->getSubAccountByName('現金', '現金');
        $sales = $unit->getSubAccountByName('売上高', '売上高');

        $closedYear->registerOpeningEntry([
            [
                'account_name' => '現金',
                'sub_account_name' => '現金',
                'amount' => 100_000,
            ],
        ], $user);

        (new TransactionRegistrar)->register($closedYear, [
            'date' => '2025-04-10',
            'description' => '売上',
        ], [
            [
                'sub_account_id' => $cash->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 30_000,
            ],
            [
                'sub_account_id' => $sales->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => 30_000,
            ],
        ], $user);

        $closedYear->close($user);

        Livewire::actingAs($user)
            ->test(FiscalYearIndex::class)
            ->call('openRolloverConfirm', $closedYear->id)
            ->assertSet('showRolloverConfirmModal', true)
            ->assertSee('繰越データの確認')
            ->assertSee('元入金')
            ->set('nextYearIsTaxable', false)
            ->call('confirmCreateNextFiscalYearFromRollover')
            ->assertSee('2026年度を繰越データで作成し、表示中に切り替えました。');

        $unit->refresh();
        $nextYear = $unit->fiscalYears()->where('year', 2026)->first();

        $this->assertNotNull($nextYear);
        $this->assertSame($nextYear->id, $unit->current_fiscal_year_id);
        $this->assertTrue((bool) $nextYear->is_active);
        $this->assertFalse((bool) $nextYear->is_taxable);
        $this->assertSame((bool) $closedYear->fresh()->is_tax_exclusive, (bool) $nextYear->is_tax_exclusive);
        $this->assertTrue($nextYear->transactions()->where('is_opening_entry', true)->exists());
    }
}
