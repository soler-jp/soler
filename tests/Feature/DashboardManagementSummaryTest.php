<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\ProfitSummary;
use App\Models\BusinessUnit;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardManagementSummaryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 仕入れがなければ売上経費利益の3カードを表示する(): void
    {
        [$user, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;

        $this->registerRevenue($unit, $fiscalYear, 10000);
        $this->registerExpense($unit, $fiscalYear, 3000);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeLivewire(ProfitSummary::class)
            ->assertSee('売上')
            ->assertSee('経費')
            ->assertSee('利益')
            ->assertSee('7,000')
            ->assertSee('md:min-w-0 md:flex-1', false)
            ->assertDontSee('>仕入れ</h2>', false)
            ->assertDontSee('>今の差し引き</h2>', false);
    }

    #[Test]
    public function 仕入れがあれば仕入れと今の差し引きカードを表示し利益カードを隠す(): void
    {
        [$user, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;

        $this->registerRevenue($unit, $fiscalYear, 20000);
        $this->registerExpense($unit, $fiscalYear, 4000);
        $this->registerExpense($unit, $fiscalYear, 6000, '仕入金額');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeLivewire(ProfitSummary::class)
            ->assertSee('売上')
            ->assertSee('経費')
            ->assertSee('仕入れ')
            ->assertSee('今の差し引き')
            ->assertSee('10,000')
            ->assertSee('md:min-w-0 md:flex-1', false)
            ->assertSee('売上から、記録済みの経費と仕入(6,000円)を引いた金額です。')
            ->assertSee('年末に在庫を入力すると、最終的な利益は変わることがあります。')
            ->assertDontSee('>利益</h2>', false);
    }

    /**
     * @return array{0: User, 1: BusinessUnit}
     */
    private function createInitializedUser(): array
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $unit->createFiscalYear(2025, $user);
        $unit->refresh();

        return [$user, $unit];
    }

    private function registerRevenue(BusinessUnit $unit, FiscalYear $fiscalYear, int $amount): Transaction
    {
        return (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-01-10',
            'description' => '売上',
        ], [
            [
                'sub_account_id' => $unit->getAccountByName('現金')->subAccounts()->firstOrFail()->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => $amount,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $unit->getAccountByName('売上高')->subAccounts()->firstOrFail()->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => $amount,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $unit->user);
    }

    private function registerExpense(
        BusinessUnit $unit,
        FiscalYear $fiscalYear,
        int $amount,
        string $accountName = '消耗品費',
    ): Transaction {
        return (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-01-11',
            'description' => '経費',
        ], [
            [
                'sub_account_id' => $unit->getAccountByName($accountName)->subAccounts()->firstOrFail()->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => $amount,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $unit->getAccountByName('現金')->subAccounts()->firstOrFail()->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => $amount,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $unit->user);
    }
}
