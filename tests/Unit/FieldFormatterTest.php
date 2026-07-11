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
