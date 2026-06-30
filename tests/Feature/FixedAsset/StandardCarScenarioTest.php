<?php

namespace Tests\Feature\FixedAsset;

use App\Models\DepreciationEntry;
use App\Models\FixedAsset;
use App\Models\Transaction;
use App\Models\User;
use App\Services\DepreciationService;
use App\Setup\Initializers\GeneralBusinessInitializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StandardCarScenarioTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    #[DataProvider('taxabilityCases')]
    public function 普通車の新車を登録できる(bool $isTaxable, string $scenarioLabel): void
    {
        $user = User::factory()->create();

        $unit = (new GeneralBusinessInitializer)->initialize($user, [
            'name' => 'テスト事業体',
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => $isTaxable,
            'is_tax_exclusive' => false,
            'cash_balance' => null,
            'bank_accounts' => [],
            'fixed_assets' => [],
            'recurring_templates' => [],
        ]);

        $fiscalYear = $unit->currentFiscalYear;
        $paymentSubAccount = $unit->getAccountByName('その他の預金')
            ?->subAccounts()
            ->firstOrFail();

        $fixedAsset = app(DepreciationService::class)->registerNewStandardCar(
            $fiscalYear,
            $paymentSubAccount,
            [
                'name' => 'PRIUS',
                'acquisition_date' => '2025-10-03',
                'taxable_amount' => 3_000_000,
                'tax_amount' => 300_000,
            ],
            [
                'date' => '2025-10-03',
                'description' => 'PRIUSを購入',
            ],
        );

        $this->assertModelExists($fixedAsset);
        $this->assertInstanceOf(FixedAsset::class, $fixedAsset);
        $this->assertSame($unit->id, $fixedAsset->business_unit_id, $scenarioLabel);
        $this->assertSame('車両運搬具', $fixedAsset->account->name, $scenarioLabel);
        $this->assertSame('新車-普通車', $fixedAsset->asset_category, $scenarioLabel);
        $this->assertSame('PRIUS', $fixedAsset->name, $scenarioLabel);
        $this->assertSame('2025-10-03', $fixedAsset->acquisition_date->toDateString(), $scenarioLabel);
        $this->assertSame(3_000_000, $fixedAsset->taxable_amount, $scenarioLabel);
        $this->assertSame(300_000, $fixedAsset->tax_amount, $scenarioLabel);
        $this->assertSame(3_300_000, $fixedAsset->acquisition_cost, $scenarioLabel);
        $this->assertSame(72, $fixedAsset->useful_life, $scenarioLabel);
        $this->assertSame(FixedAsset::DEPRECIATION_METHOD_STRAIGHT_LINE, $fixedAsset->depreciation_method, $scenarioLabel);
        $this->assertTrue($fixedAsset->isNewStandardCar(), $scenarioLabel);

        $transaction = Transaction::where('fiscal_year_id', $fiscalYear->id)
            ->where('description', 'PRIUSを購入')
            ->first();

        $this->assertNotNull($transaction, $scenarioLabel);
        $this->assertCount(2, $transaction->journalEntries, $scenarioLabel);
        $this->assertTrue(
            $transaction->journalEntries->contains(
                fn ($entry) => $entry->type === 'debit'
                    && $entry->sub_account_id !== null
                    && $entry->net_amount === 3_300_000
            ),
            $scenarioLabel
        );
        $this->assertTrue(
            $transaction->journalEntries->contains(
                fn ($entry) => $entry->type === 'credit'
                    && $entry->sub_account_id === $paymentSubAccount->id
                    && $entry->net_amount === 3_300_000
            ),
            $scenarioLabel
        );

        $depreciationEntry = DepreciationEntry::where('fixed_asset_id', $fixedAsset->id)->first();

        $this->assertNotNull($depreciationEntry, $scenarioLabel);
        $this->assertSame($fiscalYear->id, $depreciationEntry->fiscal_year_id, $scenarioLabel);
    }

    public static function taxabilityCases(): array
    {
        return [
            '免税事業者' => [false, '免税事業者'],
            '課税事業者' => [true, '課税事業者'],
        ];
    }
}
