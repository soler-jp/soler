<?php

namespace Tests\Feature;

use App\Livewire\FiscalYearClosing\PlannedTransactionsSection;
use App\Models\BusinessUnit;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\TransactionRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FiscalYearClosingPlannedSectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: BusinessUnit, 2: FiscalYear}
     */
    private function createUserWithFiscalYear(int $year = 2025): array
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '予定取引テスト事業体']);
        $fiscalYear = $unit->createFiscalYear($year, $user);

        return [$user, $unit->refresh(), $fiscalYear];
    }

    private function createPlannedRentPayment(FiscalYear $fiscalYear, User $user, string $date, int $amount, string $description): void
    {
        $unit = $fiscalYear->businessUnit;
        $expense = $unit->getSubAccountByName('地代家賃', '地代家賃') ?? $unit->getSubAccountByName('事業主借', '事業主借');
        $cash = $unit->getSubAccountByName('現金', '現金');

        (new TransactionRegistrar)->register($fiscalYear, [
            'date' => $date,
            'description' => $description,
            'is_planned' => true,
        ], [
            ['sub_account_id' => $expense->id, 'type' => JournalEntry::TYPE_DEBIT, 'net_amount' => $amount],
            ['sub_account_id' => $cash->id, 'type' => JournalEntry::TYPE_CREDIT, 'net_amount' => $amount],
        ], $user);
    }

    #[Test]
    public function 予定取引がなければ空メッセージを表示する(): void
    {
        [$user, , $fiscalYear] = $this->createUserWithFiscalYear();

        Livewire::actingAs($user)
            ->test(PlannedTransactionsSection::class, ['fiscalYearId' => $fiscalYear->id])
            ->assertSee(__('fiscal_year_closing.planned.no_items'));
    }

    #[Test]
    public function 予定取引を確定すると本登録になる(): void
    {
        [$user, , $fiscalYear] = $this->createUserWithFiscalYear();

        $this->createPlannedRentPayment($fiscalYear, $user, '2025-06-01', 3_000, '事務用品');

        $planned = $fiscalYear->transactions()->where('is_planned', true)->firstOrFail();

        Livewire::actingAs($user)
            ->test(PlannedTransactionsSection::class, ['fiscalYearId' => $fiscalYear->id])
            ->assertSee('事務用品')
            ->call('confirm', $planned->id)
            ->assertSet('errorMessage', null);

        $planned->refresh();
        $this->assertFalse((bool) $planned->is_planned);
        $this->assertTrue((bool) $planned->is_active);
    }

    #[Test]
    public function 他ユーザーの会計年度は読み込めない(): void
    {
        [$owner, , $ownerFiscalYear] = $this->createUserWithFiscalYear();
        $this->createPlannedRentPayment($ownerFiscalYear, $owner, '2025-06-01', 3_000, '所有者の予定');

        $attacker = User::factory()->create();
        $attacker->createBusinessUnitWithDefaults(['name' => '攻撃者事業体']);

        Livewire::actingAs($attacker)
            ->test(PlannedTransactionsSection::class, ['fiscalYearId' => $ownerFiscalYear->id])
            ->assertStatus(403);
    }

    #[Test]
    public function 予定取引を削除すると非アクティブになる(): void
    {
        [$user, , $fiscalYear] = $this->createUserWithFiscalYear();

        $this->createPlannedRentPayment($fiscalYear, $user, '2025-06-01', 3_000, '発生しなかった予定');

        $planned = $fiscalYear->transactions()->where('is_planned', true)->firstOrFail();

        Livewire::actingAs($user)
            ->test(PlannedTransactionsSection::class, ['fiscalYearId' => $fiscalYear->id])
            ->call('cancel', $planned->id)
            ->assertSet('errorMessage', null);

        $planned->refresh();
        $this->assertFalse((bool) $planned->is_active);
        $this->assertTrue((bool) $planned->is_planned);
    }
}
