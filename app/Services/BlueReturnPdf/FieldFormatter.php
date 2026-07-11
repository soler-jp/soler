<?php

namespace App\Services\BlueReturnPdf;

use RuntimeException;

class FieldFormatter
{
    private const FAMILY_EMPLOYEE_SALARY_ROW_COUNT = 4;

    private const RENT_EXPENSE_ROW_COUNT = 2;

    private const DEPRECIATION_ROW_COUNT = 7;

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
     * 3ページ(売上・仕入金額の明細、減価償却費の計算)の欄キー → 印字文字列を作る。
     *
     * 売上・仕入の明細は取引先別の内訳を持たないため、
     * 「上記以外の計」と「計」の2行に同じ金額を印字する。
     *
     * @param  array{
     *     entries: array<int, array{
     *         fixed_asset_name: string,
     *         quantity: int,
     *         acquisition_year_month: ?string,
     *         depreciation_base_amount: ?int,
     *         depreciation_method: ?string,
     *         useful_life: ?int,
     *         depreciation_rate: ?string,
     *         months: int,
     *         ordinary_amount: int,
     *         total_amount: int,
     *         business_usage_ratio: string|int|float,
     *         deductible_amount: int,
     *         ending_undepreciated_balance: ?int
     *     }>,
     *     totals: array{ordinary_amount: int, total_amount: int, deductible_amount: int}
     * }  $depreciationCalculation
     * @return array<string, string>
     */
    public function formatPage3(
        int $salesAmount,
        int $purchasesAmount,
        array $depreciationCalculation,
        string $filingNumber = ''
    ): array {
        $formatted = [
            'filing_number' => $filingNumber,
            'sales_amount_other_total' => $this->formatAmount($salesAmount),
            'sales_amount_total' => $this->formatAmount($salesAmount),
            'purchases_amount_other_total' => $this->formatOptionalAmount($purchasesAmount),
            'purchases_amount_total' => $this->formatOptionalAmount($purchasesAmount),
        ];

        return array_merge($formatted, $this->formatDepreciationCalculation($depreciationCalculation));
    }

    /**
     * @param  array{
     *     entries: array<int, array<string, mixed>>,
     *     totals: array{ordinary_amount: int, total_amount: int, deductible_amount: int}
     * }  $depreciationCalculation
     * @return array<string, string>
     */
    private function formatDepreciationCalculation(array $depreciationCalculation): array
    {
        $entries = $depreciationCalculation['entries'];

        if (count($entries) > self::DEPRECIATION_ROW_COUNT) {
            throw new RuntimeException(sprintf(
                '減価償却費の計算が様式の行数(%d行)を超えています: %d行',
                self::DEPRECIATION_ROW_COUNT,
                count($entries)
            ));
        }

        $formatted = [];

        foreach (array_values($entries) as $index => $entry) {
            $rowNumber = $index + 1;

            $formatted["depreciation_{$rowNumber}_asset_name"] = (string) $entry['fixed_asset_name'];
            $formatted["depreciation_{$rowNumber}_quantity"] = (string) $entry['quantity'];
            $formatted["depreciation_{$rowNumber}_acquisition_year_month"] = $this->formatAcquisitionYearMonth($entry['acquisition_year_month']);
            $formatted["depreciation_{$rowNumber}_base_amount"] = $this->formatOptionalAmount($entry['depreciation_base_amount']);
            $formatted["depreciation_{$rowNumber}_method"] = $this->formatDepreciationMethod($entry['depreciation_method'] ?? null);
            $formatted["depreciation_{$rowNumber}_useful_life"] = $entry['useful_life'] === null ? '' : (string) $entry['useful_life'];
            $formatted["depreciation_{$rowNumber}_depreciation_rate"] = (string) ($entry['depreciation_rate'] ?? '');
            $formatted["depreciation_{$rowNumber}_months"] = (string) $entry['months'];
            $formatted["depreciation_{$rowNumber}_ordinary_amount"] = $this->formatAmount((int) $entry['ordinary_amount']);
            $formatted["depreciation_{$rowNumber}_total_amount"] = $this->formatAmount((int) $entry['total_amount']);
            $formatted["depreciation_{$rowNumber}_business_usage_ratio"] = $this->formatBusinessUsageRatio($entry['business_usage_ratio']);
            $formatted["depreciation_{$rowNumber}_deductible_amount"] = $this->formatAmount((int) $entry['deductible_amount']);
            $formatted["depreciation_{$rowNumber}_ending_undepreciated_balance"] = $entry['ending_undepreciated_balance'] === null
                ? ''
                : $this->formatAmount((int) $entry['ending_undepreciated_balance']);
        }

        if ($entries !== []) {
            $totals = $depreciationCalculation['totals'];

            $formatted['depreciation_total_ordinary_amount'] = $this->formatAmount($totals['ordinary_amount']);
            $formatted['depreciation_total_amount'] = $this->formatAmount($totals['total_amount']);
            $formatted['depreciation_total_deductible_amount'] = $this->formatAmount($totals['deductible_amount']);
        }

        return $formatted;
    }

    /**
     * 取得年月("YYYY-MM")を和暦表記(例: R7・10)にする。
     */
    private function formatAcquisitionYearMonth(?string $yearMonth): string
    {
        if ($yearMonth === null || $yearMonth === '') {
            return '';
        }

        if (preg_match('/\A(\d{4})-(\d{2})\z/', $yearMonth, $matches) !== 1) {
            return $yearMonth;
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];

        return match (true) {
            $year >= 2019 => sprintf('R%d・%d', $year - 2018, $month),
            $year >= 1989 => sprintf('H%d・%d', $year - 1988, $month),
            $year >= 1926 => sprintf('S%d・%d', $year - 1925, $month),
            default => $yearMonth,
        };
    }

    /**
     * 誤った償却方法を印字しないよう、未知の値・nullは空欄にして目視確認に委ねる。
     */
    private function formatDepreciationMethod(?string $method): string
    {
        return match ($method) {
            'straight_line' => '定額',
            default => '',
        };
    }

    /**
     * 事業専用割合(0〜1の比率)を%表記の数値文字列にする(例: "0.80" → "80")。
     */
    private function formatBusinessUsageRatio(string|int|float $ratio): string
    {
        $normalized = number_format((float) $ratio * 100, 2, '.', '');

        return rtrim(rtrim($normalized, '0'), '.');
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
