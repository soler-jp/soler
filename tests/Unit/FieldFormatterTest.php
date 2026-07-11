<?php

namespace Tests\Unit;

use App\Services\BlueReturnPdf\FieldFormatter;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class FieldFormatterTest extends TestCase
{
    #[Test]
    public function 金額はカンマ付きで整形される(): void
    {
        $formatter = new FieldFormatter;

        $this->assertSame('1,234,567', $formatter->formatAmount(1234567));
    }

    #[Test]
    public function 負値は三角付きで整形される(): void
    {
        $formatter = new FieldFormatter;

        $this->assertSame('△1,234,567', $formatter->formatAmount(-1234567));
    }

    #[Test]
    public function 零は零で整形される(): void
    {
        $formatter = new FieldFormatter;

        $this->assertSame('0', $formatter->formatAmount(0));
    }

    #[Test]
    public function nullは空欄として整形される(): void
    {
        $formatter = new FieldFormatter;

        $this->assertSame('', $formatter->formatOptionalAmount(null));
    }

    #[Test]
    public function page2の欄キーへ整形される(): void
    {
        $formatter = new FieldFormatter;

        $formatted = $formatter->formatPage2(
            eraYear: 7,
            monthlySalesAndPurchases: $this->monthlySalesAndPurchases(),
            incomeBeforeBlueReturnDeduction: 1234567,
            familyEmployeeSalaryRows: [
                [
                    'name' => '専従 花子',
                    'age' => 45,
                    'months' => 12,
                    'salary' => 1200000,
                    'bonus' => 300000,
                    'withheld_tax_amount' => 45000,
                ],
                [
                    'name' => '専従 次郎',
                    'age' => null,
                    'months' => 6,
                    'salary' => 600000,
                ],
            ],
            rentExpenseRows: [
                [
                    'address' => '東京都千代田区1-2-3',
                    'name' => '家主 太郎',
                    'rent_amount' => 660000,
                    'deductible_amount' => 330000,
                ],
            ],
            name: '国税 花子',
            nameKana: 'コクゼイ ハナコ',
            filingNumber: '12345678',
        );

        $this->assertSame('7', $formatted['era_year']);
        $this->assertSame('12345678', $formatted['filing_number']);
        $this->assertSame('国税 花子', $formatted['name']);
        $this->assertSame('コクゼイ ハナコ', $formatted['name_kana']);
        $this->assertSame('1,234,567', $formatted['income_before_blue_return_deduction']);

        // 月別: 0の月は空欄、計は雑収入・家事消費等を含めて①と一致する
        $this->assertSame('1,100,000', $formatted['monthly_sales_1']);
        $this->assertSame('', $formatted['monthly_sales_2']);
        $this->assertSame('2,200,000', $formatted['monthly_sales_12']);
        $this->assertSame('550,000', $formatted['monthly_purchases_1']);
        $this->assertSame('', $formatted['monthly_purchases_12']);
        $this->assertSame('30,000', $formatted['monthly_house_consumption']);
        $this->assertSame('70,000', $formatted['monthly_misc_income']);
        $this->assertSame('3,400,000', $formatted['monthly_sales_total']);
        $this->assertSame('550,000', $formatted['monthly_purchases_total']);

        // 専従者給与の内訳: 行ごとの欄と計
        $this->assertSame('専従 花子', $formatted['family_employee_salary_1_name']);
        $this->assertSame('45', $formatted['family_employee_salary_1_age']);
        $this->assertSame('12', $formatted['family_employee_salary_1_months']);
        $this->assertSame('1,200,000', $formatted['family_employee_salary_1_salary']);
        $this->assertSame('300,000', $formatted['family_employee_salary_1_bonus']);
        $this->assertSame('1,500,000', $formatted['family_employee_salary_1_total']);
        $this->assertSame('45,000', $formatted['family_employee_salary_1_withheld_tax']);
        $this->assertSame('', $formatted['family_employee_salary_2_age']);
        $this->assertSame('', $formatted['family_employee_salary_2_bonus']);
        $this->assertSame('600,000', $formatted['family_employee_salary_2_total']);
        $this->assertSame('18', $formatted['family_employee_salary_total_months']);
        $this->assertSame('1,800,000', $formatted['family_employee_salary_total_salary']);
        $this->assertSame('300,000', $formatted['family_employee_salary_total_bonus']);
        $this->assertSame('2,100,000', $formatted['family_employee_salary_total_amount']);
        $this->assertSame('45,000', $formatted['family_employee_salary_total_withheld_tax']);

        // 地代家賃の内訳
        $this->assertSame('東京都千代田区1-2-3', $formatted['rent_expense_1_address']);
        $this->assertSame('家主 太郎', $formatted['rent_expense_1_name']);
        $this->assertSame('660,000', $formatted['rent_expense_1_rent_amount']);
        $this->assertSame('330,000', $formatted['rent_expense_1_deductible_amount']);
        $this->assertArrayNotHasKey('rent_expense_2_address', $formatted);
    }

    #[Test]
    public function page2の内訳が未入力なら計も印字されない(): void
    {
        $formatter = new FieldFormatter;

        $formatted = $formatter->formatPage2(
            eraYear: 7,
            monthlySalesAndPurchases: $this->monthlySalesAndPurchases(),
            incomeBeforeBlueReturnDeduction: 0,
        );

        $this->assertArrayNotHasKey('family_employee_salary_total_amount', $formatted);
        $this->assertArrayNotHasKey('rent_expense_1_address', $formatted);
        $this->assertSame('', $formatted['filing_number']);
    }

    #[Test]
    public function 専従者給与の内訳が様式の行数を超えると例外になる(): void
    {
        $formatter = new FieldFormatter;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('専従者給与の内訳が様式の行数(4行)を超えています');

        $formatter->formatPage2(
            eraYear: 7,
            monthlySalesAndPurchases: $this->monthlySalesAndPurchases(),
            incomeBeforeBlueReturnDeduction: 0,
            familyEmployeeSalaryRows: array_fill(0, 5, ['name' => '専従者', 'salary' => 100000]),
        );
    }

    #[Test]
    public function 地代家賃の内訳が様式の行数を超えると例外になる(): void
    {
        $formatter = new FieldFormatter;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('地代家賃の内訳が様式の行数(2行)を超えています');

        $formatter->formatPage2(
            eraYear: 7,
            monthlySalesAndPurchases: $this->monthlySalesAndPurchases(),
            incomeBeforeBlueReturnDeduction: 0,
            rentExpenseRows: array_fill(0, 3, [
                'address' => '住所',
                'name' => '氏名',
                'rent_amount' => 1,
                'deductible_amount' => 1,
            ]),
        );
    }

    #[Test]
    public function page3の欄キーへ整形される(): void
    {
        $formatter = new FieldFormatter;

        $formatted = $formatter->formatPage3(
            salesAmount: 3400000,
            purchasesAmount: 550000,
            depreciationCalculation: [
                'entries' => [
                    [
                        'fixed_asset_name' => '軽トラック',
                        'quantity' => 1,
                        'acquisition_year_month' => '2025-10',
                        'depreciation_base_amount' => 1500000,
                        'depreciation_method' => 'straight_line',
                        'useful_life' => 4,
                        'depreciation_rate' => '0.250',
                        'months' => 3,
                        'ordinary_amount' => 93750,
                        'total_amount' => 93750,
                        'business_usage_ratio' => '0.80',
                        'deductible_amount' => 75000,
                        'ending_undepreciated_balance' => 1406250,
                    ],
                    [
                        'fixed_asset_name' => '一括償却資産',
                        'quantity' => 1,
                        'acquisition_year_month' => null,
                        'depreciation_base_amount' => null,
                        'depreciation_method' => null,
                        'useful_life' => null,
                        'depreciation_rate' => null,
                        'months' => 12,
                        'ordinary_amount' => 60000,
                        'total_amount' => 60000,
                        'business_usage_ratio' => 1,
                        'deductible_amount' => 60000,
                        'ending_undepreciated_balance' => null,
                    ],
                ],
                'totals' => [
                    'ordinary_amount' => 153750,
                    'total_amount' => 153750,
                    'deductible_amount' => 135000,
                ],
            ],
            filingNumber: '12345678',
        );

        $this->assertSame('12345678', $formatted['filing_number']);

        // 売上・仕入の明細: 「上記以外の計」と「計」に同じ金額
        $this->assertSame('3,400,000', $formatted['sales_amount_other_total']);
        $this->assertSame('3,400,000', $formatted['sales_amount_total']);
        $this->assertSame('550,000', $formatted['purchases_amount_other_total']);
        $this->assertSame('550,000', $formatted['purchases_amount_total']);

        // 減価償却費の計算: 明細行
        $this->assertSame('軽トラック', $formatted['depreciation_1_asset_name']);
        $this->assertSame('1', $formatted['depreciation_1_quantity']);
        $this->assertSame('R7・10', $formatted['depreciation_1_acquisition_year_month']);
        $this->assertSame('1,500,000', $formatted['depreciation_1_base_amount']);
        $this->assertSame('定額', $formatted['depreciation_1_method']);
        $this->assertSame('4', $formatted['depreciation_1_useful_life']);
        $this->assertSame('0.250', $formatted['depreciation_1_depreciation_rate']);
        $this->assertSame('3', $formatted['depreciation_1_months']);
        $this->assertSame('93,750', $formatted['depreciation_1_ordinary_amount']);
        $this->assertSame('93,750', $formatted['depreciation_1_total_amount']);
        $this->assertSame('80', $formatted['depreciation_1_business_usage_ratio']);
        $this->assertSame('75,000', $formatted['depreciation_1_deductible_amount']);
        $this->assertSame('1,406,250', $formatted['depreciation_1_ending_undepreciated_balance']);

        // null許容の欄は空欄になる
        $this->assertSame('', $formatted['depreciation_2_acquisition_year_month']);
        $this->assertSame('', $formatted['depreciation_2_base_amount']);
        $this->assertSame('', $formatted['depreciation_2_method']);
        $this->assertSame('', $formatted['depreciation_2_useful_life']);
        $this->assertSame('', $formatted['depreciation_2_depreciation_rate']);
        $this->assertSame('', $formatted['depreciation_2_ending_undepreciated_balance']);
        $this->assertSame('100', $formatted['depreciation_2_business_usage_ratio']);

        // 計
        $this->assertSame('153,750', $formatted['depreciation_total_ordinary_amount']);
        $this->assertSame('153,750', $formatted['depreciation_total_amount']);
        $this->assertSame('135,000', $formatted['depreciation_total_deductible_amount']);
        $this->assertArrayNotHasKey('depreciation_3_asset_name', $formatted);
    }

    #[Test]
    public function page3の減価償却がなければ計も印字されない(): void
    {
        $formatter = new FieldFormatter;

        $formatted = $formatter->formatPage3(
            salesAmount: 3400000,
            purchasesAmount: 0,
            depreciationCalculation: [
                'entries' => [],
                'totals' => [
                    'ordinary_amount' => 0,
                    'total_amount' => 0,
                    'deductible_amount' => 0,
                ],
            ],
        );

        $this->assertSame('', $formatted['filing_number']);
        $this->assertSame('3,400,000', $formatted['sales_amount_total']);
        $this->assertSame('', $formatted['purchases_amount_other_total']);
        $this->assertSame('', $formatted['purchases_amount_total']);
        $this->assertArrayNotHasKey('depreciation_total_amount', $formatted);
        $this->assertArrayNotHasKey('depreciation_1_asset_name', $formatted);
    }

    #[Test]
    public function page3の減価償却が様式の行数を超えると例外になる(): void
    {
        $formatter = new FieldFormatter;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('減価償却費の計算が様式の行数(7行)を超えています');

        $formatter->formatPage3(
            salesAmount: 0,
            purchasesAmount: 0,
            depreciationCalculation: [
                'entries' => array_fill(0, 8, [
                    'fixed_asset_name' => '資産',
                    'quantity' => 1,
                    'acquisition_year_month' => '2025-01',
                    'depreciation_base_amount' => 1,
                    'useful_life' => 4,
                    'depreciation_rate' => '0.250',
                    'months' => 12,
                    'ordinary_amount' => 1,
                    'total_amount' => 1,
                    'business_usage_ratio' => 1,
                    'deductible_amount' => 1,
                    'ending_undepreciated_balance' => 1,
                ]),
                'totals' => [
                    'ordinary_amount' => 8,
                    'total_amount' => 8,
                    'deductible_amount' => 8,
                ],
            ],
        );
    }

    /**
     * @return array{months: array<int, array<string, mixed>>, totals: array<string, int>}
     */
    private function monthlySalesAndPurchases(): array
    {
        $months = [];

        for ($month = 1; $month <= 12; $month++) {
            $months[] = [
                'year_month' => sprintf('2025-%02d', $month),
                'label' => $month.'月',
                'sales_amount' => $month === 1 ? 1100000 : ($month === 12 ? 2200000 : 0),
                'house_consumption_amount' => $month === 1 ? 30000 : 0,
                'misc_income_amount' => $month === 1 ? 70000 : 0,
                'purchases_amount' => $month === 1 ? 550000 : 0,
            ];
        }

        return [
            'months' => $months,
            'totals' => [
                'sales_amount' => 3300000,
                'house_consumption_amount' => 30000,
                'misc_income_amount' => 70000,
                'purchases_amount' => 550000,
            ],
        ];
    }

    #[Test]
    public function 損益計算書の0は欄により空欄になる(): void
    {
        $formatter = new FieldFormatter;

        $formatted = $formatter->formatProfitAndLoss([
            'sales_amount' => 0,
            'custom_expense_1' => 0,
        ]);

        $this->assertSame('0', $formatted['sales_amount']);
        $this->assertSame('', $formatted['custom_expense_1']);
    }
}
