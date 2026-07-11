<?php

namespace App\Services\BlueReturnPdf;

use RuntimeException;

class FieldFormatter
{
    private const FAMILY_EMPLOYEE_SALARY_ROW_COUNT = 4;

    private const RENT_EXPENSE_ROW_COUNT = 2;

    /**
     * @param  array<string, int>  $profitAndLoss
     * @return array<string, string>
     */
    public function formatProfitAndLoss(array $profitAndLoss): array
    {
        $formatted = [];

        foreach ($profitAndLoss as $fieldKey => $amount) {
            $formatted[$fieldKey] = $this->formatProfitAndLossAmount($fieldKey, $amount);
        }

        return $formatted;
    }

    /**
     * 2ページ(月別売上・専従者給与の内訳・地代家賃の内訳ほか)の欄キー → 印字文字列を作る。
     *
     * @param  array{
     *     months: array<int, array{year_month: string, label: string, sales_amount: int, house_consumption_amount: int, misc_income_amount: int, purchases_amount: int}>,
     *     totals: array{sales_amount: int, house_consumption_amount: int, misc_income_amount: int, purchases_amount: int}
     * }  $monthlySalesAndPurchases
     * @param  array<int, array{name: string, age?: ?int, months?: ?int, salary: int, bonus?: ?int, withheld_tax_amount?: ?int}>  $familyEmployeeSalaryRows
     * @param  array<int, array{address: string, name: string, rent_amount: int, deductible_amount: int}>  $rentExpenseRows
     * @return array<string, string>
     */
    public function formatPage2(
        int $eraYear,
        array $monthlySalesAndPurchases,
        int $incomeBeforeBlueReturnDeduction,
        array $familyEmployeeSalaryRows = [],
        array $rentExpenseRows = [],
        string $name = '',
        string $nameKana = '',
        string $filingNumber = ''
    ): array {
        $formatted = [
            'era_year' => (string) $eraYear,
            'filing_number' => $filingNumber,
            'name' => $name,
            'name_kana' => $nameKana,
            'income_before_blue_return_deduction' => $this->formatAmount($incomeBeforeBlueReturnDeduction),
        ];

        return array_merge(
            $formatted,
            $this->formatMonthlySalesAndPurchases($monthlySalesAndPurchases),
            $this->formatFamilyEmployeeSalaries($familyEmployeeSalaryRows),
            $this->formatRentExpenses($rentExpenseRows)
        );
    }

    /**
     * @param  array{
     *     months: array<int, array{year_month: string, label: string, sales_amount: int, house_consumption_amount: int, misc_income_amount: int, purchases_amount: int}>,
     *     totals: array{sales_amount: int, house_consumption_amount: int, misc_income_amount: int, purchases_amount: int}
     * }  $monthlySalesAndPurchases
     * @return array<string, string>
     */
    private function formatMonthlySalesAndPurchases(array $monthlySalesAndPurchases): array
    {
        $formatted = [];

        foreach ($monthlySalesAndPurchases['months'] as $month) {
            $monthNumber = (int) substr($month['year_month'], 5, 2);

            if ($monthNumber < 1 || $monthNumber > 12) {
                throw new RuntimeException("月別売上の対象月が不正です: {$month['year_month']}");
            }

            $formatted["monthly_sales_{$monthNumber}"] = $this->formatOptionalAmount($month['sales_amount']);
            $formatted["monthly_purchases_{$monthNumber}"] = $this->formatOptionalAmount($month['purchases_amount']);
        }

        $totals = $monthlySalesAndPurchases['totals'];

        // 計の売上欄は①(雑収入・家事消費等を含む)と一致させる
        $formatted['monthly_house_consumption'] = $this->formatOptionalAmount($totals['house_consumption_amount']);
        $formatted['monthly_misc_income'] = $this->formatOptionalAmount($totals['misc_income_amount']);
        $formatted['monthly_sales_total'] = $this->formatAmount(
            $totals['sales_amount'] + $totals['house_consumption_amount'] + $totals['misc_income_amount']
        );
        $formatted['monthly_purchases_total'] = $this->formatAmount($totals['purchases_amount']);

        return $formatted;
    }

    /**
     * @param  array<int, array{name: string, age?: ?int, months?: ?int, salary: int, bonus?: ?int, withheld_tax_amount?: ?int}>  $rows
     * @return array<string, string>
     */
    private function formatFamilyEmployeeSalaries(array $rows): array
    {
        if (count($rows) > self::FAMILY_EMPLOYEE_SALARY_ROW_COUNT) {
            throw new RuntimeException(sprintf(
                '専従者給与の内訳が様式の行数(%d行)を超えています: %d行',
                self::FAMILY_EMPLOYEE_SALARY_ROW_COUNT,
                count($rows)
            ));
        }

        $formatted = [];
        $totals = ['months' => 0, 'salary' => 0, 'bonus' => 0, 'total' => 0, 'withheld_tax' => 0];

        foreach (array_values($rows) as $index => $row) {
            $rowNumber = $index + 1;
            $salary = (int) $row['salary'];
            $bonus = (int) ($row['bonus'] ?? 0);
            $withheldTax = (int) ($row['withheld_tax_amount'] ?? 0);

            $formatted["family_employee_salary_{$rowNumber}_name"] = (string) $row['name'];
            $formatted["family_employee_salary_{$rowNumber}_age"] = isset($row['age']) ? (string) $row['age'] : '';
            $formatted["family_employee_salary_{$rowNumber}_months"] = isset($row['months']) ? (string) $row['months'] : '';
            $formatted["family_employee_salary_{$rowNumber}_salary"] = $this->formatAmount($salary);
            $formatted["family_employee_salary_{$rowNumber}_bonus"] = $this->formatOptionalAmount($row['bonus'] ?? null);
            $formatted["family_employee_salary_{$rowNumber}_total"] = $this->formatAmount($salary + $bonus);
            $formatted["family_employee_salary_{$rowNumber}_withheld_tax"] = $this->formatOptionalAmount($row['withheld_tax_amount'] ?? null);

            $totals['months'] += (int) ($row['months'] ?? 0);
            $totals['salary'] += $salary;
            $totals['bonus'] += $bonus;
            $totals['total'] += $salary + $bonus;
            $totals['withheld_tax'] += $withheldTax;
        }

        if ($rows !== []) {
            $formatted['family_employee_salary_total_months'] = (string) $totals['months'];
            $formatted['family_employee_salary_total_salary'] = $this->formatAmount($totals['salary']);
            $formatted['family_employee_salary_total_bonus'] = $this->formatOptionalAmount($totals['bonus']);
            $formatted['family_employee_salary_total_amount'] = $this->formatAmount($totals['total']);
            $formatted['family_employee_salary_total_withheld_tax'] = $this->formatOptionalAmount($totals['withheld_tax']);
        }

        return $formatted;
    }

    /**
     * @param  array<int, array{address: string, name: string, rent_amount: int, deductible_amount: int}>  $rows
     * @return array<string, string>
     */
    private function formatRentExpenses(array $rows): array
    {
        if (count($rows) > self::RENT_EXPENSE_ROW_COUNT) {
            throw new RuntimeException(sprintf(
                '地代家賃の内訳が様式の行数(%d行)を超えています: %d行',
                self::RENT_EXPENSE_ROW_COUNT,
                count($rows)
            ));
        }

        $formatted = [];

        foreach (array_values($rows) as $index => $row) {
            $rowNumber = $index + 1;

            $formatted["rent_expense_{$rowNumber}_address"] = (string) $row['address'];
            $formatted["rent_expense_{$rowNumber}_name"] = (string) $row['name'];
            $formatted["rent_expense_{$rowNumber}_rent_amount"] = $this->formatAmount((int) $row['rent_amount']);
            $formatted["rent_expense_{$rowNumber}_deductible_amount"] = $this->formatAmount((int) $row['deductible_amount']);
        }

        return $formatted;
    }

    public function formatAmount(int $amount): string
    {
        if ($amount < 0) {
            return '△'.number_format(abs($amount));
        }

        return number_format($amount);
    }

    public function formatOptionalAmount(?int $amount): string
    {
        if ($amount === null || $amount === 0) {
            return '';
        }

        return $this->formatAmount($amount);
    }

    private function formatProfitAndLossAmount(string $fieldKey, int $amount): string
    {
        if ($amount === 0 && in_array($fieldKey, $this->blankWhenZeroFields(), true)) {
            return '';
        }

        return $this->formatAmount($amount);
    }

    /**
     * @return array<int, string>
     */
    private function blankWhenZeroFields(): array
    {
        return [
            'custom_expense_1',
            'custom_expense_2',
            'custom_expense_3',
            'custom_expense_4',
            'custom_expense_5',
            'custom_expense_6',
            'bad_debt_reserve_reversal',
            'reserve_reversal_1',
            'reserve_reversal_2',
            'bad_debt_reserve_provision',
            'reserve_provision_1',
            'reserve_provision_2',
        ];
    }
}
