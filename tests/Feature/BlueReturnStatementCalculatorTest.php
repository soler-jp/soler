<?php

namespace Tests\Feature;

use App\Models\FiscalYear;
use App\Models\SubAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BlueReturnStatementCalculator;
use App\Services\FiscalYearSummaryCalculator;
use App\Services\TransactionRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BlueReturnStatementCalculatorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    #[Group('mysql')]
    public function 損益計算書の集計値が帳簿から計算される(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => '青色申告テスト',
        ]);
        $fiscalYear = $businessUnit->createFiscalYear(2025);

        $cash = $businessUnit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $sales = $businessUnit->getAccountByName('売上高')->subAccounts()->firstOrFail();
        $miscIncome = $businessUnit->getAccountByName('雑収入')->subAccounts()->firstOrFail();
        $houseConsumption = $businessUnit->getAccountByName('家事消費等')->subAccounts()->firstOrFail();
        $openingInventory = $businessUnit->getAccountByName('期首商品（棚卸高）')->subAccounts()->firstOrFail();
        $purchases = $businessUnit->getAccountByName('仕入金額')->subAccounts()->firstOrFail();
        $endingInventory = $businessUnit->getAccountByName('期末商品（棚卸高）')->subAccounts()->firstOrFail();
        $taxesAndDues = $businessUnit->getAccountByName('租税公課')->subAccounts()->firstOrFail();
        $utilities = $businessUnit->getAccountByName('水道光熱費')->subAccounts()->firstOrFail();
        $supplies = $businessUnit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();
        $depreciation = $businessUnit->getAccountByName('減価償却費')->subAccounts()->firstOrFail();
        $wages = $businessUnit->getAccountByName('給料賃金')->subAccounts()->firstOrFail();
        $familyEmployeeSalaries = $businessUnit->getAccountByName('専従者給与')->subAccounts()->firstOrFail();

        app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-01-10',
            'description' => '青色申告集計テスト',
        ], [
            [
                'sub_account_id' => $cash->id,
                'type' => 'debit',
                'net_amount' => 50_000,
                'tax_amount' => 5_000,
            ],
            [
                'sub_account_id' => $sales->id,
                'type' => 'credit',
                'net_amount' => 50_000,
                'tax_amount' => 5_000,
            ],
        ]);

        app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-02-10',
            'description' => '青色申告集計テスト',
        ], [
            [
                'sub_account_id' => $cash->id,
                'type' => 'debit',
                'net_amount' => 20_000,
                'tax_amount' => 2_000,
            ],
            [
                'sub_account_id' => $miscIncome->id,
                'type' => 'credit',
                'net_amount' => 20_000,
                'tax_amount' => 2_000,
            ],
        ]);

        app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-03-10',
            'description' => '青色申告集計テスト',
        ], [
            [
                'sub_account_id' => $cash->id,
                'type' => 'debit',
                'net_amount' => 30_000,
                'tax_amount' => 3_000,
            ],
            [
                'sub_account_id' => $houseConsumption->id,
                'type' => 'credit',
                'net_amount' => 30_000,
                'tax_amount' => 3_000,
            ],
        ]);

        app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-04-01',
            'description' => '青色申告集計テスト',
        ], [
            [
                'sub_account_id' => $openingInventory->id,
                'type' => 'debit',
                'net_amount' => 10_000,
                'tax_amount' => 0,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'credit',
                'net_amount' => 10_000,
                'tax_amount' => 0,
            ],
        ]);

        app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-05-01',
            'description' => '青色申告集計テスト',
        ], [
            [
                'sub_account_id' => $purchases->id,
                'type' => 'debit',
                'net_amount' => 20_000,
                'tax_amount' => 0,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'credit',
                'net_amount' => 20_000,
                'tax_amount' => 0,
            ],
        ]);

        app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-06-01',
            'description' => '青色申告集計テスト',
        ], [
            [
                'sub_account_id' => $cash->id,
                'type' => 'debit',
                'net_amount' => 5_000,
                'tax_amount' => 0,
            ],
            [
                'sub_account_id' => $endingInventory->id,
                'type' => 'credit',
                'net_amount' => 5_000,
                'tax_amount' => 0,
            ],
        ]);

        app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-07-01',
            'description' => '青色申告集計テスト',
        ], [
            [
                'sub_account_id' => $taxesAndDues->id,
                'type' => 'debit',
                'net_amount' => 1_000,
                'tax_amount' => 0,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'credit',
                'net_amount' => 1_000,
                'tax_amount' => 0,
            ],
        ]);

        app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-08-01',
            'description' => '青色申告集計テスト',
        ], [
            [
                'sub_account_id' => $utilities->id,
                'type' => 'debit',
                'net_amount' => 1_800,
                'tax_amount' => 200,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'credit',
                'net_amount' => 1_800,
                'tax_amount' => 200,
            ],
        ]);

        app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-09-01',
            'description' => '青色申告集計テスト',
        ], [
            [
                'sub_account_id' => $supplies->id,
                'type' => 'debit',
                'net_amount' => 3_000,
                'tax_amount' => 0,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'credit',
                'net_amount' => 3_000,
                'tax_amount' => 0,
            ],
        ]);

        app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-10-01',
            'description' => '青色申告集計テスト',
        ], [
            [
                'sub_account_id' => $depreciation->id,
                'type' => 'debit',
                'net_amount' => 4_000,
                'tax_amount' => 0,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'credit',
                'net_amount' => 4_000,
                'tax_amount' => 0,
            ],
        ]);

        app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-11-01',
            'description' => '青色申告集計テスト',
        ], [
            [
                'sub_account_id' => $wages->id,
                'type' => 'debit',
                'net_amount' => 5_000,
                'tax_amount' => 0,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'credit',
                'net_amount' => 5_000,
                'tax_amount' => 0,
            ],
        ]);

        app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-12-01',
            'description' => '青色申告集計テスト',
        ], [
            [
                'sub_account_id' => $familyEmployeeSalaries->id,
                'type' => 'debit',
                'net_amount' => 6_000,
                'tax_amount' => 0,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'credit',
                'net_amount' => 6_000,
                'tax_amount' => 0,
            ],
        ]);

        $plannedTransaction = Transaction::factory()->create([
            'fiscal_year_id' => $fiscalYear->id,
            'date' => '2025-12-15',
            'is_active' => true,
            'is_planned' => true,
        ]);

        $plannedTransaction->journalEntries()->createMany([
            [
                'sub_account_id' => $sales->id,
                'type' => 'credit',
                'net_amount' => 999_999,
                'tax_amount' => 0,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'debit',
                'net_amount' => 999_999,
                'tax_amount' => 0,
            ],
        ]);

        $summary = app(BlueReturnStatementCalculator::class)->calculate($fiscalYear, 65_000)['profit_and_loss'];
        $fiscalYearSummary = app(FiscalYearSummaryCalculator::class)->calculate($fiscalYear)['actual'];

        $this->assertSame(110_000, $summary['sales_amount']);
        $this->assertSame(10_000, $summary['beginning_inventory']);
        $this->assertSame(20_000, $summary['purchases_amount']);
        $this->assertSame(30_000, $summary['purchases_subtotal']);
        $this->assertSame(5_000, $summary['ending_inventory']);
        $this->assertSame(25_000, $summary['cost_of_goods_sold']);
        $this->assertSame(85_000, $summary['gross_profit']);
        $this->assertSame(1_000, $summary['taxes_and_dues']);
        $this->assertSame(2_000, $summary['utilities']);
        $this->assertSame(3_000, $summary['supplies_expenses']);
        $this->assertSame(4_000, $summary['depreciation_expense']);
        $this->assertSame(5_000, $summary['wages']);
        $this->assertSame(6_000, $summary['family_employee_salaries']);
        $this->assertSame(15_000, $summary['total_expenses']);
        $this->assertSame(70_000, $summary['profit_before_reserves']);
        $this->assertSame(6_000, $summary['total_reserve_provisions']);
        $this->assertSame($fiscalYearSummary['profit'], $summary['income_before_blue_return_deduction']);
        $this->assertSame(64_000, $summary['income_before_blue_return_deduction']);
        $this->assertSame(64_000, $summary['blue_return_deduction']);
        $this->assertSame(0, $summary['business_income']);
        $this->assertSame(0, $summary['custom_expense_1']);
        $this->assertSame(0, $summary['reserve_reversal_1']);
        $this->assertSame(0, $summary['bad_debt_reserve_provision']);
    }

    /**
     * 「青色申告決算書（一般用）の書き方」（kokuzei/037.pdf）の記載例（国税太郎）による検算。
     * 貸倒引当金は初版未対応のため、引当金（㉞・㊴）を除いた調整後期待値を使う:
     * ㊲ = 0、㊷ = 専従者給与のみ 1,200,000、㊸ = 5,331,400 + 0 − 1,200,000 = 4,131,400。
     */
    #[Test]
    #[Group('mysql')]
    public function 記載例の数字で損益計算書の全欄が一致する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => '青果小売業',
        ]);
        $fiscalYear = $businessUnit->createFiscalYear(2025);

        $cash = $businessUnit->getAccountByName('現金')->subAccounts()->firstOrFail();

        $creditEntries = [
            '売上高' => 38_753_000,
            '家事消費等' => 207_000,
            '雑収入' => 320_000,
            '期末商品（棚卸高）' => 3_814_000,
        ];

        $debitEntries = [
            '期首商品（棚卸高）' => 3_705_000,
            '仕入金額' => 27_596_000,
            '租税公課' => 385_000,
            '水道光熱費' => 224_000,
            '旅費交通費' => 148_000,
            '通信費' => 167_000,
            '広告宣伝費' => 105_000,
            '接待交際費' => 163_000,
            '損害保険料' => 105_000,
            '修繕費' => 259_000,
            '消耗品費' => 378_000,
            '減価償却費' => 1_433_600,
            '福利厚生費' => 173_000,
            '給料賃金' => 2_625_000,
            '利子割引料' => 128_000,
            '地代家賃' => 120_000,
            '雑費' => 48_000,
            '専従者給与' => 1_200_000,
        ];

        foreach ($creditEntries as $accountName => $amount) {
            $subAccount = $businessUnit->getAccountByName($accountName)->subAccounts()->firstOrFail();
            app(TransactionRegistrar::class)->register($fiscalYear, [
                'date' => '2025-06-15',
                'description' => '記載例検算テスト',
            ], [
                [
                    'sub_account_id' => $subAccount->id,
                    'type' => 'credit',
                    'net_amount' => $amount,
                    'tax_amount' => 0,
                ],
                [
                    'sub_account_id' => $cash->id,
                    'type' => 'debit',
                    'net_amount' => $amount,
                    'tax_amount' => 0,
                ],
            ]);
        }

        foreach ($debitEntries as $accountName => $amount) {
            $subAccount = $businessUnit->getAccountByName($accountName)->subAccounts()->firstOrFail();
            app(TransactionRegistrar::class)->register($fiscalYear, [
                'date' => '2025-06-15',
                'description' => '記載例検算テスト',
            ], [
                [
                    'sub_account_id' => $subAccount->id,
                    'type' => 'debit',
                    'net_amount' => $amount,
                    'tax_amount' => 0,
                ],
                [
                    'sub_account_id' => $cash->id,
                    'type' => 'credit',
                    'net_amount' => $amount,
                    'tax_amount' => 0,
                ],
            ]);
        }

        $summary = $fiscalYear->calculateBlueReturnStatement(650_000)['profit_and_loss'];

        $this->assertSame(39_280_000, $summary['sales_amount']);
        $this->assertSame(3_705_000, $summary['beginning_inventory']);
        $this->assertSame(27_596_000, $summary['purchases_amount']);
        $this->assertSame(31_301_000, $summary['purchases_subtotal']);
        $this->assertSame(3_814_000, $summary['ending_inventory']);
        $this->assertSame(27_487_000, $summary['cost_of_goods_sold']);
        $this->assertSame(11_793_000, $summary['gross_profit']);
        $this->assertSame(385_000, $summary['taxes_and_dues']);
        $this->assertSame(0, $summary['packing_and_freight']);
        $this->assertSame(224_000, $summary['utilities']);
        $this->assertSame(148_000, $summary['travel_expenses']);
        $this->assertSame(167_000, $summary['communication_expenses']);
        $this->assertSame(105_000, $summary['advertising_expenses']);
        $this->assertSame(163_000, $summary['entertainment_expenses']);
        $this->assertSame(105_000, $summary['casualty_insurance']);
        $this->assertSame(259_000, $summary['repair_expenses']);
        $this->assertSame(378_000, $summary['supplies_expenses']);
        $this->assertSame(1_433_600, $summary['depreciation_expense']);
        $this->assertSame(173_000, $summary['welfare_expenses']);
        $this->assertSame(2_625_000, $summary['wages']);
        $this->assertSame(0, $summary['outsourcing_costs']);
        $this->assertSame(128_000, $summary['interest_and_discounts']);
        $this->assertSame(120_000, $summary['rent_expenses']);
        $this->assertSame(0, $summary['bad_debts']);
        $this->assertSame(48_000, $summary['miscellaneous_expenses']);
        $this->assertSame(6_461_600, $summary['total_expenses']);
        $this->assertSame(5_331_400, $summary['profit_before_reserves']);
        $this->assertSame(0, $summary['total_reserve_reversals']);
        $this->assertSame(1_200_000, $summary['family_employee_salaries']);
        $this->assertSame(1_200_000, $summary['total_reserve_provisions']);
        $this->assertSame(4_131_400, $summary['income_before_blue_return_deduction']);
        $this->assertSame(650_000, $summary['blue_return_deduction']);
        $this->assertSame(3_481_400, $summary['business_income']);

        // テスト方針2: ㊸ と FiscalYearSummaryCalculator の actual profit の恒等
        $fiscalYearSummary = app(FiscalYearSummaryCalculator::class)->calculate($fiscalYear)['actual'];
        $this->assertSame($fiscalYearSummary['profit'], $summary['income_before_blue_return_deduction']);
    }

    #[Test]
    public function 負の青色申告特別控除額は例外になる(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => '青色申告テスト',
        ]);
        $fiscalYear = $businessUnit->createFiscalYear(2025);

        $this->expectException(\InvalidArgumentException::class);

        app(BlueReturnStatementCalculator::class)->calculate($fiscalYear, -1);
    }
}
