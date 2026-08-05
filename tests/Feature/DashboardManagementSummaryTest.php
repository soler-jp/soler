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
        $this->registerRevenue($unit, $fiscalYear, 5000, '預金売上', 'その他の預金');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeLivewire(ProfitSummary::class)
            ->assertSee('売上')
            ->assertSee('経費')
            ->assertSee('利益')
            ->assertSee('現金・預金')
            ->assertSee('現金')
            ->assertSee('その他の預金')
            ->assertSee('12,000')
            ->assertSee('15,000')
            ->assertSee('space-y-4', false)
            ->assertSee('grid gap-4 md:grid-cols-2 xl:grid-cols-4', false)
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
            ->assertSee('現金・預金')
            ->assertSee('現金')
            ->assertSee('10,000')
            ->assertSee('grid gap-4 md:grid-cols-2 xl:grid-cols-4', false)
            ->assertSee('売上から、記録済みの経費と仕入(6,000円)を引いた金額です。')
            ->assertSee('年末に在庫を入力すると、最終的な利益は変わることがあります。')
            ->assertDontSee('>利益</h2>', false);
    }

    #[Test]
    public function 期末棚卸は経費カードから除外され仕入れカードで相殺表示される(): void
    {
        [$user, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;

        $this->registerRevenue($unit, $fiscalYear, 100000);
        $this->registerExpense($unit, $fiscalYear, 30000);
        $this->registerExpense($unit, $fiscalYear, 40000, '仕入金額');
        $this->registerClosingInventoryAdjustment($unit, $fiscalYear, 12640);

        $expectedCostOfGoodsSold = 40000 - 12640;
        $expectedDifference = 100000 - 30000 - $expectedCostOfGoodsSold;

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('仕入れ')
            ->assertSee('40,000') // 仕入れカードの主表示は支払った仕入額
            ->assertSee(sprintf(
                'ただし、期末に残っている 12,640 円分を差し引いて、%s 円を経費として計上します。',
                number_format($expectedCostOfGoodsSold),
            ))
            ->assertSee(number_format($expectedDifference))
            ->assertSee(sprintf(
                '売上から、記録済みの経費と仕入(%s円)を引いた金額です。',
                number_format($expectedCostOfGoodsSold),
            ));
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

    private function registerRevenue(
        BusinessUnit $unit,
        FiscalYear $fiscalYear,
        int $amount,
        string $description = '売上',
        string $receiptAccountName = '現金',
    ): Transaction {
        return (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-01-10',
            'description' => $description,
        ], [
            [
                'sub_account_id' => $unit->getAccountByName($receiptAccountName)->subAccounts()->firstOrFail()->id,
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
        string $paymentAccountName = '現金',
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
                'sub_account_id' => $unit->getAccountByName($paymentAccountName)->subAccounts()->firstOrFail()->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => $amount,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $unit->user);
    }

    private function registerClosingInventoryAdjustment(
        BusinessUnit $unit,
        FiscalYear $fiscalYear,
        int $amount,
    ): Transaction {
        $inventoryAsset = $unit->getAccountByName('棚卸資産')->subAccounts()->firstOrFail();
        $closing = $unit->getAccountByName('期末商品（棚卸高）')->subAccounts()->firstOrFail();

        return (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-12-31',
            'description' => '期末棚卸',
        ], [
            [
                'sub_account_id' => $inventoryAsset->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => $amount,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $closing->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => $amount,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $unit->user);
    }
}
