<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\DepreciationEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BlueReturnStatementCalculator;
use App\Services\DepreciationService;
use App\Services\FiscalYearSummaryCalculator;
use App\Services\OpeningEntryRegistrar;
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
        $fiscalYear = $businessUnit->createFiscalYear(2025, $user);

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
        ], $user);

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
        ], $user);

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
        ], $user);

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
        ], $user);

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
        ], $user);

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
        ], $user);

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
        ], $user);

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
        ], $user);

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
        ], $user);

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
        ], $user);

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
        ], $user);

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
        ], $user);

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

    #[Test]
    #[Group('mysql')]
    public function 追加した費用勘定は任意科目欄と費用合計に反映される(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => '任意科目テスト',
        ]);
        $fiscalYear = $businessUnit->createFiscalYear(2025, $user);

        $cash = $businessUnit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $sales = $businessUnit->getAccountByName('売上高')->subAccounts()->firstOrFail();
        $meetingExpense = $businessUnit->createAccount([
            'name' => '会議費',
            'type' => Account::TYPE_EXPENSE,
        ], $businessUnit->user)
            ->subAccounts()
            ->firstOrFail();

        $bookExpense = $businessUnit->createAccount([
            'name' => '新聞図書費',
            'type' => Account::TYPE_EXPENSE,
        ], $businessUnit->user)
            ->subAccounts()
            ->firstOrFail();

        app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-01-10',
            'description' => '任意科目テスト売上',
        ], [
            [
                'sub_account_id' => $cash->id,
                'type' => 'debit',
                'gross_amount' => 50_000,
            ],
            [
                'sub_account_id' => $sales->id,
                'type' => 'credit',
                'gross_amount' => 50_000,
            ],
        ], $user);

        app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-02-10',
            'description' => '任意科目テスト会議費',
        ], [
            [
                'sub_account_id' => $meetingExpense->id,
                'type' => 'debit',
                'gross_amount' => 12_000,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'credit',
                'gross_amount' => 12_000,
            ],
        ], $user);

        app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-03-10',
            'description' => '任意科目テスト新聞図書費',
        ], [
            [
                'sub_account_id' => $bookExpense->id,
                'type' => 'debit',
                'gross_amount' => 8_000,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'credit',
                'gross_amount' => 8_000,
            ],
        ], $user);

        $statement = app(BlueReturnStatementCalculator::class)->calculate($fiscalYear, 0);
        $profitAndLoss = $statement['profit_and_loss'];

        $this->assertSame(12_000, $profitAndLoss['custom_expense_1']);
        $this->assertSame(8_000, $profitAndLoss['custom_expense_2']);
        $this->assertSame(0, $profitAndLoss['custom_expense_3']);
        $this->assertSame('会議費', $statement['custom_expense_labels']['custom_expense_1_label']);
        $this->assertSame('新聞図書費', $statement['custom_expense_labels']['custom_expense_2_label']);
        $this->assertSame('', $statement['custom_expense_labels']['custom_expense_3_label']);
        $this->assertSame(20_000, $profitAndLoss['total_expenses']);
        $this->assertSame(30_000, $profitAndLoss['income_before_blue_return_deduction']);
        $this->assertSame(
            $fiscalYear->calculateSummary()['actual']['profit'],
            $profitAndLoss['income_before_blue_return_deduction']
        );
    }

    #[Test]
    #[Group('mysql')]
    public function 任意科目欄を超える件数の追加費用勘定がある場合は例外になる(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => '任意科目超過テスト',
        ]);
        $fiscalYear = $businessUnit->createFiscalYear(2025, $user);

        $cash = $businessUnit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $sales = $businessUnit->getAccountByName('売上高')->subAccounts()->firstOrFail();

        app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-01-10',
            'description' => '任意科目超過テスト売上',
        ], [
            [
                'sub_account_id' => $cash->id,
                'type' => 'debit',
                'gross_amount' => 100_000,
            ],
            [
                'sub_account_id' => $sales->id,
                'type' => 'credit',
                'gross_amount' => 100_000,
            ],
        ], $user);

        for ($index = 1; $index <= 7; $index++) {
            $expenseSubAccount = $businessUnit->createAccount([
                'name' => "追加費用{$index}",
                'type' => Account::TYPE_EXPENSE,
            ], $businessUnit->user)
                ->subAccounts()
                ->firstOrFail();

            app(TransactionRegistrar::class)->register($fiscalYear, [
                'date' => sprintf('2025-02-%02d', $index),
                'description' => "任意科目超過テスト追加費用{$index}",
            ], [
                [
                    'sub_account_id' => $expenseSubAccount->id,
                    'type' => 'debit',
                    'gross_amount' => 1_000,
                ],
                [
                    'sub_account_id' => $cash->id,
                    'type' => 'credit',
                    'gross_amount' => 1_000,
                ],
            ], $user);
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('青色申告決算書の任意科目欄(6行)を超える費用勘定があります。');

        app(BlueReturnStatementCalculator::class)->calculate($fiscalYear, 0);
    }

    #[Test]
    #[Group('mysql')]
    public function 月別売上仕入集計が集計対象の取引だけを月ごとに返す(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => '月別集計テスト',
        ]);
        $fiscalYear = $businessUnit->createFiscalYear(2025, $user);

        $cash = $businessUnit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $sales = $businessUnit->getAccountByName('売上高')->subAccounts()->firstOrFail();
        $houseConsumption = $businessUnit->getAccountByName('家事消費等')->subAccounts()->firstOrFail();
        $miscIncome = $businessUnit->getAccountByName('雑収入')->subAccounts()->firstOrFail();
        $purchases = $businessUnit->getAccountByName('仕入金額')->subAccounts()->firstOrFail();

        app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-01-10',
            'description' => '月別集計テスト',
        ], [
            [
                'sub_account_id' => $sales->id,
                'type' => 'credit',
                'net_amount' => 10_000,
                'tax_amount' => 0,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'debit',
                'net_amount' => 10_000,
                'tax_amount' => 0,
            ],
        ], $user);

        app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-01-20',
            'description' => '月別集計テスト（税込売上）',
        ], [
            [
                'sub_account_id' => $sales->id,
                'type' => 'credit',
                'net_amount' => 5_000,
                'tax_amount' => 500,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'debit',
                'net_amount' => 5_000,
                'tax_amount' => 500,
            ],
        ], $user);

        app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-01-25',
            'description' => '月別集計テスト（売上値引）',
        ], [
            [
                'sub_account_id' => $sales->id,
                'type' => 'debit',
                'net_amount' => 1_000,
                'tax_amount' => 100,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'credit',
                'net_amount' => 1_000,
                'tax_amount' => 100,
            ],
        ], $user);

        app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-02-10',
            'description' => '月別集計テスト',
        ], [
            [
                'sub_account_id' => $houseConsumption->id,
                'type' => 'credit',
                'net_amount' => 20_000,
                'tax_amount' => 0,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'debit',
                'net_amount' => 20_000,
                'tax_amount' => 0,
            ],
        ], $user);

        app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-02-20',
            'description' => '月別集計テスト',
        ], [
            [
                'sub_account_id' => $miscIncome->id,
                'type' => 'credit',
                'net_amount' => 3_000,
                'tax_amount' => 0,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'debit',
                'net_amount' => 3_000,
                'tax_amount' => 0,
            ],
        ], $user);

        app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-02-28',
            'description' => '月別集計テスト',
        ], [
            [
                'sub_account_id' => $miscIncome->id,
                'type' => 'credit',
                'net_amount' => 2_000,
                'tax_amount' => 0,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'debit',
                'net_amount' => 2_000,
                'tax_amount' => 0,
            ],
        ], $user);

        app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-03-10',
            'description' => '月別集計テスト',
        ], [
            [
                'sub_account_id' => $purchases->id,
                'type' => 'debit',
                'net_amount' => 40_000,
                'tax_amount' => 0,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'credit',
                'net_amount' => 40_000,
                'tax_amount' => 0,
            ],
        ], $user);

        $outOfPeriodTransaction = Transaction::factory()->create([
            'fiscal_year_id' => $fiscalYear->id,
            'date' => '2024-12-31',
            'is_active' => true,
            'is_planned' => false,
        ]);

        $outOfPeriodTransaction->journalEntries()->createMany([
            [
                'sub_account_id' => $sales->id,
                'type' => 'credit',
                'net_amount' => 99_999,
                'tax_amount' => 0,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'debit',
                'net_amount' => 99_999,
                'tax_amount' => 0,
            ],
        ]);

        $inactiveTransaction = Transaction::factory()->create([
            'fiscal_year_id' => $fiscalYear->id,
            'date' => '2025-05-10',
            'is_active' => false,
            'is_planned' => false,
        ]);

        $inactiveTransaction->journalEntries()->createMany([
            [
                'sub_account_id' => $sales->id,
                'type' => 'credit',
                'net_amount' => 88_888,
                'tax_amount' => 0,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'debit',
                'net_amount' => 88_888,
                'tax_amount' => 0,
            ],
        ]);

        $plannedTransaction = Transaction::factory()->create([
            'fiscal_year_id' => $fiscalYear->id,
            'date' => '2025-06-10',
            'is_active' => true,
            'is_planned' => true,
        ]);

        $plannedTransaction->journalEntries()->createMany([
            [
                'sub_account_id' => $sales->id,
                'type' => 'credit',
                'net_amount' => 77_777,
                'tax_amount' => 0,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'debit',
                'net_amount' => 77_777,
                'tax_amount' => 0,
            ],
        ]);

        $monthlySummary = app(BlueReturnStatementCalculator::class)->calculate($fiscalYear, 0)['monthly_sales_and_purchases'];
        $months = collect($monthlySummary['months'])->keyBy('year_month');

        $this->assertCount(12, $monthlySummary['months']);
        $this->assertSame('2025-01', $monthlySummary['months'][0]['year_month']);
        $this->assertSame('2025-12', $monthlySummary['months'][11]['year_month']);

        // 1月売上: 10,000 + 税込 5,500 − 値引（借方売上）1,100 = 14,400
        $this->assertSame(14_400, $months['2025-01']['sales_amount']);
        $this->assertSame(0, $months['2025-01']['house_consumption_amount']);
        $this->assertSame(0, $months['2025-01']['misc_income_amount']);
        $this->assertSame(0, $months['2025-01']['purchases_amount']);

        $this->assertSame(0, $months['2025-02']['sales_amount']);
        $this->assertSame(20_000, $months['2025-02']['house_consumption_amount']);
        $this->assertSame(5_000, $months['2025-02']['misc_income_amount']);
        $this->assertSame(0, $months['2025-02']['purchases_amount']);

        $this->assertSame(40_000, $months['2025-03']['purchases_amount']);
        $this->assertSame(0, $months['2025-04']['sales_amount']);
        $this->assertSame(0, $months['2025-04']['house_consumption_amount']);
        $this->assertSame(0, $months['2025-04']['misc_income_amount']);
        $this->assertSame(0, $months['2025-04']['purchases_amount']);

        // 無効（is_active=false）・予定（is_planned=true）の取引は集計されない
        $this->assertSame(0, $months['2025-05']['sales_amount']);
        $this->assertSame(0, $months['2025-06']['sales_amount']);

        $this->assertSame(14_400, $monthlySummary['totals']['sales_amount']);
        $this->assertSame(20_000, $monthlySummary['totals']['house_consumption_amount']);
        $this->assertSame(5_000, $monthlySummary['totals']['misc_income_amount']);
        $this->assertSame(40_000, $monthlySummary['totals']['purchases_amount']);
    }

    #[Test]
    #[Group('mysql')]
    public function 減価償却明細が固定資産と記帳済み償却仕訳から導出される(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => '減価償却明細テスト',
        ]);
        $fiscalYear = $businessUnit->createFiscalYear(2025, $user);

        $assetSubAccount = $businessUnit->subAccounts()
            ->whereHas('account', function ($query): void {
                $query->where('name', '機械装置');
            })
            ->firstOrFail();

        $paymentSubAccount = $businessUnit->subAccounts()
            ->whereHas('account', function ($query): void {
                $query->where('name', 'その他の預金');
            })
            ->firstOrFail();

        $fixedAsset = app(DepreciationService::class)->registerFixedAsset(
            fiscalYear: $fiscalYear,
            assetSubAccount: $assetSubAccount,
            paymentSubAccount: $paymentSubAccount,
            fixedAssetData: [
                'name' => '減価償却明細テスト資産',
                'asset_category' => 'machinery',
                'acquisition_date' => '2025-10-01',
                'taxable_amount' => 480_000,
                'tax_amount' => 0,
                'depreciation_method' => 'straight_line',
                'useful_life' => 48,
                'business_usage_ratio' => 0.80,
            ],
            transactionData: [
                'date' => '2025-10-01',
                'description' => '減価償却明細テスト資産を購入',
            ],
        );

        $entry = DepreciationEntry::where('fixed_asset_id', $fixedAsset->id)->firstOrFail();
        app(DepreciationService::class)->registerTransactionFor($entry, $user);

        $statement = app(BlueReturnStatementCalculator::class)->calculate($fiscalYear, 0);
        $depreciation = $statement['depreciation_calculation'];
        $row = $depreciation['entries'][0];

        $this->assertCount(1, $depreciation['entries']);
        $this->assertSame('減価償却明細テスト資産', $row['fixed_asset_name']);
        $this->assertSame(1, $row['quantity']);
        $this->assertSame('2025-10', $row['acquisition_year_month']);
        $this->assertSame(480_000, $row['depreciation_base_amount']);
        $this->assertSame('straight_line', $row['depreciation_method']);
        $this->assertSame(4, $row['useful_life']);
        $this->assertSame('0.250', $row['depreciation_rate']);
        $this->assertSame(3, $row['months']);
        $this->assertSame(30_000, $row['ordinary_amount']);
        $this->assertSame(30_000, $row['total_amount']);
        $this->assertSame('0.80', $row['business_usage_ratio']);
        $this->assertSame(24_000, $row['deductible_amount']);
        $this->assertSame(450_000, $row['ending_undepreciated_balance']);

        $this->assertSame(30_000, $depreciation['totals']['ordinary_amount']);
        $this->assertSame(30_000, $depreciation['totals']['total_amount']);
        $this->assertSame(24_000, $depreciation['totals']['deductible_amount']);
        $this->assertSame(24_000, $depreciation['totals']['ledger_depreciation_expense']);
        $this->assertSame(0, $depreciation['totals']['difference']);
        $this->assertSame($depreciation['totals']['ledger_depreciation_expense'], $statement['profit_and_loss']['depreciation_expense']);
    }

    #[Test]
    #[Group('mysql')]
    public function 減価償却明細は代表的な_fixed_assetパターンを網羅して表示される(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => '減価償却明細パターンテスト',
        ]);
        $fiscalYear2023 = $businessUnit->createFiscalYear(2023, $user);
        $fiscalYear2024 = $businessUnit->createFiscalYear(2024, $user);

        $assetSubAccount = $businessUnit->subAccounts()
            ->whereHas('account', function ($query): void {
                $query->where('name', '機械装置');
            })
            ->firstOrFail();

        $paymentSubAccount = $businessUnit->subAccounts()
            ->whereHas('account', function ($query): void {
                $query->where('name', 'その他の預金');
            })
            ->firstOrFail();

        $currentYearAsset = app(DepreciationService::class)->registerFixedAsset(
            fiscalYear: $fiscalYear2024,
            assetSubAccount: $assetSubAccount,
            paymentSubAccount: $paymentSubAccount,
            fixedAssetData: [
                'name' => '当年度取得の資産',
                'asset_category' => 'machinery',
                'acquisition_date' => '2024-10-01',
                'taxable_amount' => 480_000,
                'tax_amount' => 0,
                'depreciation_method' => 'straight_line',
                'useful_life' => 48,
                'business_usage_ratio' => 0.80,
            ],
            transactionData: [
                'date' => '2024-10-01',
                'description' => '当年度取得の資産を購入',
            ],
        );

        $pastYearAsset = app(DepreciationService::class)->registerFixedAsset(
            fiscalYear: $fiscalYear2024,
            assetSubAccount: $assetSubAccount,
            paymentSubAccount: $paymentSubAccount,
            fixedAssetData: [
                'name' => '前年度取得の資産',
                'asset_category' => 'machinery',
                'acquisition_date' => '2023-10-01',
                'taxable_amount' => 480_000,
                'tax_amount' => 0,
                'depreciation_method' => 'straight_line',
                'useful_life' => 48,
            ],
            transactionData: [
                'date' => '2024-10-01',
                'description' => '前年度取得の資産を購入',
            ],
            allowRegistration: true,
        );

        $completedAsset = app(DepreciationService::class)->registerFixedAsset(
            fiscalYear: $fiscalYear2024,
            assetSubAccount: $assetSubAccount,
            paymentSubAccount: $paymentSubAccount,
            fixedAssetData: [
                'name' => '償却完了の資産',
                'asset_category' => 'machinery',
                'acquisition_date' => '2021-01-01',
                'taxable_amount' => 240_000,
                'tax_amount' => 0,
                'depreciation_method' => 'straight_line',
                'useful_life' => 24,
            ],
            transactionData: [
                'date' => '2024-10-01',
                'description' => '償却完了の資産を登録',
            ],
            allowRegistration: true,
        );

        $currentYearEntry = DepreciationEntry::where('fixed_asset_id', $currentYearAsset->id)->firstOrFail();
        $pastYearEntry = DepreciationEntry::where('fixed_asset_id', $pastYearAsset->id)
            ->where('fiscal_year_id', $fiscalYear2024->id)
            ->firstOrFail();

        app(DepreciationService::class)->registerTransactionFor($currentYearEntry, $user);
        app(DepreciationService::class)->registerTransactionFor($pastYearEntry, $user);

        $statement = app(BlueReturnStatementCalculator::class)->calculate($fiscalYear2024, 0);
        $depreciation = $statement['depreciation_calculation'];
        $rows = collect($depreciation['entries'])->keyBy('fixed_asset_name');

        $this->assertCount(2, $depreciation['entries']);
        $this->assertArrayHasKey('当年度取得の資産', $rows);
        $this->assertArrayHasKey('前年度取得の資産', $rows);
        $this->assertArrayNotHasKey('償却完了の資産', $rows);

        $this->assertSame(1, $rows['当年度取得の資産']['quantity']);
        $this->assertSame(3, $rows['当年度取得の資産']['months']);
        $this->assertSame(30_000, $rows['当年度取得の資産']['ordinary_amount']);
        $this->assertSame(30_000, $rows['当年度取得の資産']['total_amount']);
        $this->assertSame('0.80', $rows['当年度取得の資産']['business_usage_ratio']);
        $this->assertSame(24_000, $rows['当年度取得の資産']['deductible_amount']);
        $this->assertSame(450_000, $rows['当年度取得の資産']['ending_undepreciated_balance']);

        $this->assertSame(12, $rows['前年度取得の資産']['months']);
        $this->assertSame(120_000, $rows['前年度取得の資産']['ordinary_amount']);
        $this->assertSame(120_000, $rows['前年度取得の資産']['deductible_amount']);
        $this->assertSame(330_000, $rows['前年度取得の資産']['ending_undepreciated_balance']);

        $this->assertSame(144_000, $depreciation['totals']['deductible_amount']);
        $this->assertSame(144_000, $depreciation['totals']['ledger_depreciation_expense']);
        $this->assertSame(0, $depreciation['totals']['difference']);
    }

    #[Test]
    #[Group('mysql')]
    public function 貸借対照表ページが残高集計を決算書形式へ変換する(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => '貸借対照表変換テスト',
        ]);
        $fiscalYear = $businessUnit->createFiscalYear(2025, $user);

        $cash = $businessUnit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $deposit = $businessUnit->getAccountByName('その他の預金')->subAccounts()->firstOrFail();
        $ownerLoan = $businessUnit->getAccountByName('事業主借')->subAccounts()->firstOrFail();
        $ownerDraw = $businessUnit->getAccountByName('事業主貸')->subAccounts()->firstOrFail();

        app(OpeningEntryRegistrar::class)->registerForRollover($fiscalYear, [
            [
                'account_name' => '現金',
                'sub_account_name' => '現金',
                'type' => 'debit',
                'amount' => 100_000,
            ],
            [
                'account_name' => '借入金',
                'sub_account_name' => '借入金',
                'type' => 'credit',
                'amount' => 30_000,
            ],
        ], [
            'account_name' => '元入金',
            'sub_account_name' => '元入金',
            'type' => 'credit',
            'amount' => 70_000,
        ], $user);

        app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-04-01',
            'description' => '貸借対照表変換テスト',
        ], [
            [
                'sub_account_id' => $cash->id,
                'type' => 'debit',
                'gross_amount' => 1100,
            ],
            [
                'sub_account_id' => $ownerLoan->id,
                'type' => 'credit',
                'gross_amount' => 1100,
            ],
        ], $user);

        app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-04-02',
            'description' => '貸借対照表変換テスト',
        ], [
            [
                'sub_account_id' => $deposit->id,
                'type' => 'debit',
                'gross_amount' => 2200,
            ],
            [
                'sub_account_id' => $ownerLoan->id,
                'type' => 'credit',
                'gross_amount' => 2200,
            ],
        ], $user);

        app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-04-03',
            'description' => '貸借対照表変換テスト',
        ], [
            [
                'sub_account_id' => $ownerDraw->id,
                'type' => 'debit',
                'gross_amount' => 500,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'credit',
                'gross_amount' => 500,
            ],
        ], $user);

        $statement = app(BlueReturnStatementCalculator::class)->calculate($fiscalYear, 0);
        $balanceSheet = $statement['balance_sheet'];

        $this->assertSame($statement['profit_and_loss']['income_before_blue_return_deduction'], $balanceSheet['income_before_blue_return_deduction']);
        $this->assertSame('資産の部', $balanceSheet['sections']['asset']['label']);
        $this->assertSame('負債の部', $balanceSheet['sections']['liability']['label']);
        $this->assertSame('純資産の部', $balanceSheet['sections']['equity']['label']);

        $assetRows = collect($balanceSheet['sections']['asset']['rows'])->keyBy('account_name');
        $liabilityRows = collect($balanceSheet['sections']['liability']['rows'])->keyBy('account_name');
        $equityRows = collect($balanceSheet['sections']['equity']['rows'])->keyBy('account_name');

        $this->assertSame(100_000, $balanceSheet['sections']['asset']['opening_total_balance']);
        $this->assertSame(102_800, $balanceSheet['sections']['asset']['ending_total_balance']);
        $this->assertSame(30_000, $balanceSheet['sections']['liability']['opening_total_balance']);
        $this->assertSame(30_000, $balanceSheet['sections']['liability']['ending_total_balance']);
        $this->assertSame(70_000, $balanceSheet['sections']['equity']['opening_total_balance']);
        $this->assertSame(72_800, $balanceSheet['sections']['equity']['ending_total_balance']);
        $this->assertSame(2, $assetRows->count());

        $this->assertSame(100_000, $assetRows['現金']['opening_balance']);
        $this->assertSame(100_600, $assetRows['現金']['ending_balance']);
        $this->assertSame(100_000, $assetRows['現金']['rows'][0]['opening_balance']);
        $this->assertSame(100_600, $assetRows['現金']['rows'][0]['ending_balance']);
        $this->assertSame(0, $assetRows['その他の預金']['opening_balance']);
        $this->assertSame(2_200, $assetRows['その他の預金']['ending_balance']);
        $this->assertSame(0, $assetRows['その他の預金']['rows'][0]['opening_balance']);
        $this->assertSame(2_200, $assetRows['その他の預金']['rows'][0]['ending_balance']);
        $this->assertSame(30_000, $liabilityRows['借入金']['opening_balance']);
        $this->assertSame(30_000, $liabilityRows['借入金']['ending_balance']);
        $this->assertSame(30_000, $liabilityRows['借入金']['rows'][0]['opening_balance']);
        $this->assertSame(30_000, $liabilityRows['借入金']['rows'][0]['ending_balance']);

        $this->assertSame(70_000, $equityRows['元入金']['opening_balance']);
        $this->assertSame(70_000, $equityRows['元入金']['ending_balance']);
        $this->assertSame(0, $equityRows['事業主借']['opening_balance']);
        $this->assertSame(3_300, $equityRows['事業主借']['ending_balance']);
        $this->assertSame(0, $equityRows['事業主貸']['opening_balance']);
        $this->assertSame(-500, $equityRows['事業主貸']['ending_balance']);

        $this->assertSame([
            'opening' => [
                'asset' => 100_000,
                'liability' => 30_000,
                'equity' => 70_000,
            ],
            'ending' => [
                'asset' => 102_800,
                'liability' => 30_000,
                'equity' => 72_800,
            ],
        ], $balanceSheet['totals']);
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
        $fiscalYear = $businessUnit->createFiscalYear(2025, $user);

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
            ], $user);
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
            ], $user);
        }

        $statement = $fiscalYear->calculateBlueReturnStatement(650_000);
        $summary = $statement['profit_and_loss'];

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

        // 様式の不変条件: 月別表の計（売上・家事消費等・雑収入）の合計は①と、仕入金額の計は③と一致する
        $monthlyTotals = $statement['monthly_sales_and_purchases']['totals'];
        $this->assertSame(
            $summary['sales_amount'],
            $monthlyTotals['sales_amount'] + $monthlyTotals['house_consumption_amount'] + $monthlyTotals['misc_income_amount']
        );
        $this->assertSame($summary['purchases_amount'], $monthlyTotals['purchases_amount']);
    }

    #[Test]
    public function 負の青色申告特別控除額は例外になる(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => '青色申告テスト',
        ]);
        $fiscalYear = $businessUnit->createFiscalYear(2025, $user);

        $this->expectException(\InvalidArgumentException::class);

        app(BlueReturnStatementCalculator::class)->calculate($fiscalYear, -1);
    }
}
