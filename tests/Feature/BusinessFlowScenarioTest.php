<?php

namespace Tests\Feature;

use App\Models\DepreciationEntry;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\DepreciationService;
use App\Services\TransactionRegistrar;
use App\Setup\Initializers\GeneralBusinessInitializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BusinessFlowScenarioTest extends TestCase
{
    use RefreshDatabase;

    public static function supportedInitializationProfiles(): array
    {
        return [
            'non-taxable' => [false, false],
            'taxable-inclusive' => [true, false],
        ];
    }

    #[Test]
    #[DataProvider('supportedInitializationProfiles')]
    public function 初期登録でサポート対象の税設定パターンを選ぶと事業体と会計年度が有効化される(bool $isTaxable, bool $isTaxExclusive): void
    {
        $user = User::factory()->create();

        $unit = (new GeneralBusinessInitializer)->initialize($user, [
            'name' => '上位シナリオ事業体',
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => $isTaxable,
            'is_tax_exclusive' => $isTaxExclusive,
            'opening_entries' => [],
            'fixed_assets' => [],
            'recurring_templates' => [],
        ]);

        /** @var FiscalYear $fiscalYear */
        $fiscalYear = $unit->fiscalYears()->where('year', 2025)->firstOrFail();

        $this->assertDatabaseHas('business_units', [
            'id' => $unit->id,
            'user_id' => $user->id,
            'current_fiscal_year_id' => $fiscalYear->id,
        ]);

        $this->assertDatabaseHas('fiscal_years', [
            'id' => $fiscalYear->id,
            'business_unit_id' => $unit->id,
            'is_taxable' => $isTaxable,
            'is_tax_exclusive' => $isTaxExclusive,
            'is_active' => true,
            'is_closed' => false,
        ]);

        $this->assertSame($unit->id, $user->fresh()->current_business_unit_id);
        $this->assertSame($fiscalYear->id, $unit->fresh()->current_fiscal_year_id);
    }

    #[Test]
    public function 会計年度作成後に期首残高の空入力と実入力の両パターンを扱える(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => '期首パターン事業体',
        ]);

        $fiscalYear = $unit->createFiscalYear(2026);
        $this->assertDatabaseCount('transactions', 0);

        $emptyResult = $fiscalYear->registerOpeningEntry([]);
        $this->assertNull($emptyResult);
        $this->assertDatabaseCount('transactions', 0);

        $openingTransaction = $fiscalYear->registerOpeningEntry([
            [
                'account_name' => '現金',
                'sub_account_name' => 'レジ現金',
                'amount' => 50000,
            ],
        ]);

        $this->assertNotNull($openingTransaction);
        $this->assertDatabaseHas('transactions', [
            'id' => $openingTransaction->id,
            'fiscal_year_id' => $fiscalYear->id,
            'is_opening_entry' => true,
        ]);
        $this->assertCount(2, $openingTransaction->journalEntries);
        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $openingTransaction->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 50000,
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $openingTransaction->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 50000,
        ]);
    }

    #[Test]
    #[DataProvider('supportedInitializationProfiles')]
    public function 日々業務から期末処理までの上位フローを通してsummaryが整合する(bool $isTaxable, bool $isTaxExclusive): void
    {
        $user = User::factory()->create();
        $unit = (new GeneralBusinessInitializer)->initialize($user, [
            'name' => '業務フロー事業体',
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => $isTaxable,
            'is_tax_exclusive' => $isTaxExclusive,
            'opening_entries' => [],
            'fixed_assets' => [],
            'recurring_templates' => [],
        ]);

        /** @var FiscalYear $fiscalYear */
        $fiscalYear = $unit->fiscalYears()->where('year', 2025)->firstOrFail();

        $revenueSubAccount = $unit->getSubAccountByName('売上高', '一般売上');
        $expenseSubAccount = $unit->getSubAccountByName('消耗品費', '消耗品費');
        $cashSubAccount = $unit->getSubAccountByName('現金', 'レジ現金');
        $liabilitySubAccount = $unit->getSubAccountByName('未払金', '未払金');
        $paymentSubAccount = $unit->getSubAccountByName('その他の預金', 'その他の預金');

        $this->assertNotNull($revenueSubAccount);
        $this->assertNotNull($expenseSubAccount);
        $this->assertNotNull($cashSubAccount);
        $this->assertNotNull($liabilitySubAccount);
        $this->assertNotNull($paymentSubAccount);

        $registrar = new TransactionRegistrar;

        $registrar->register($fiscalYear, [
            'date' => '2025-04-01',
            'description' => '売上登録',
        ], [
            [
                'sub_account_id' => $revenueSubAccount->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => 12000,
            ],
            [
                'sub_account_id' => $cashSubAccount->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 12000,
            ],
        ]);

        $registrar->register($fiscalYear, [
            'date' => '2025-04-02',
            'description' => '経費登録',
        ], [
            [
                'sub_account_id' => $expenseSubAccount->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 4000,
            ],
            [
                'sub_account_id' => $liabilitySubAccount->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => 4000,
            ],
        ]);

        $summaryAfterDaily = $fiscalYear->calculateSummary();
        $this->assertSame(12000, $summaryAfterDaily['actual']['total_income']);
        $this->assertSame(4000, $summaryAfterDaily['actual']['total_expense']);
        $this->assertSame(8000, $summaryAfterDaily['actual']['profit']);

        $fixedAsset = app(DepreciationService::class)->registerNewLightCar(
            $fiscalYear,
            $paymentSubAccount,
            [
                'name' => 'N-BOX',
                'acquisition_date' => '2025-05-01',
                'taxable_amount' => 1_200_000,
                'tax_amount' => 120_000,
                'business_usage_ratio' => 0.5,
            ],
            [
                'date' => '2025-05-01',
                'description' => '車両購入',
            ],
        );

        $this->assertDatabaseHas('fixed_assets', [
            'id' => $fixedAsset->id,
            'business_unit_id' => $unit->id,
        ]);

        $fixedCostPlan = $unit->createRecurringTransactionPlan([
            'name' => 'サーバー費',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'is_income' => false,
            'debit_sub_account_id' => $expenseSubAccount->id,
            'credit_sub_account_id' => $paymentSubAccount->id,
            'amount' => 3000,
            'tax_amount' => 0,
        ]);

        $plannedTransactions = $unit->generatePlannedTransactionsForPlan($fixedCostPlan, $fiscalYear);
        $this->assertCount(12, $plannedTransactions);
        $firstPlanned = $plannedTransactions->firstOrFail();
        $this->assertTrue($firstPlanned->is_planned);
        $this->assertSame('2025-01-10', $firstPlanned->date->toDateString());

        $confirmed = $fixedCostPlan->confirmTransaction($firstPlanned->id, [
            'date' => '2025-06-10',
            'amount' => 3500,
            'credit_sub_account_id' => $paymentSubAccount->id,
        ]);

        $this->assertNotNull($confirmed);
        $this->assertFalse($confirmed->is_planned);
        $this->assertSame('2025-06-10', $confirmed->date->toDateString());
        $this->assertCount(2, $confirmed->journalEntries);
        $this->assertSame(3500, $confirmed->journalEntries->firstWhere('type', JournalEntry::TYPE_DEBIT)?->net_amount);
        $this->assertSame(3500, $confirmed->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT)?->net_amount);
        $this->assertSame($paymentSubAccount->id, $confirmed->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT)?->sub_account_id);

        $summaryAfterConfirm = $fiscalYear->calculateSummary();
        $this->assertSame(7500, $summaryAfterConfirm['actual']['total_expense']);

        /** @var DepreciationEntry $entry */
        $entry = DepreciationEntry::query()
            ->where('fixed_asset_id', $fixedAsset->id)
            ->where('fiscal_year_id', $fiscalYear->id)
            ->firstOrFail();

        $this->assertNull($entry->transaction_id);

        app(DepreciationService::class)->registerTransactionFor($entry);

        $entry->refresh();
        $this->assertNotNull($entry->transaction_id);

        $summaryAfterYearEndPosting = $fiscalYear->calculateSummary();

        $this->assertSame(
            $summaryAfterConfirm['actual']['total_expense'] + $entry->deductible_amount,
            $summaryAfterYearEndPosting['actual']['total_expense']
        );
        $this->assertSame(
            $summaryAfterConfirm['actual']['profit'] - $entry->deductible_amount,
            $summaryAfterYearEndPosting['actual']['profit']
        );
    }
}
