<?php

namespace App\Services;

use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Builder;

class BlueReturnStatementCalculator
{
    public function calculate(FiscalYear $fiscalYear, int $blueReturnDeduction): array
    {
        return [
            'profit_and_loss' => $this->calculateProfitAndLoss($fiscalYear, $blueReturnDeduction),
        ];
    }

    /**
     * @return array{
     *     sales_amount: int,
     *     beginning_inventory: int,
     *     purchases_amount: int,
     *     purchases_subtotal: int,
     *     ending_inventory: int,
     *     cost_of_goods_sold: int,
     *     gross_profit: int,
     *     taxes_and_dues: int,
     *     packing_and_freight: int,
     *     utilities: int,
     *     travel_expenses: int,
     *     communication_expenses: int,
     *     advertising_expenses: int,
     *     entertainment_expenses: int,
     *     casualty_insurance: int,
     *     repair_expenses: int,
     *     supplies_expenses: int,
     *     depreciation_expense: int,
     *     welfare_expenses: int,
     *     wages: int,
     *     outsourcing_costs: int,
     *     interest_and_discounts: int,
     *     rent_expenses: int,
     *     bad_debts: int,
     *     custom_expense_1: int,
     *     custom_expense_2: int,
     *     custom_expense_3: int,
     *     custom_expense_4: int,
     *     custom_expense_5: int,
     *     custom_expense_6: int,
     *     miscellaneous_expenses: int,
     *     total_expenses: int,
     *     profit_before_reserves: int,
     *     bad_debt_reserve_reversal: int,
     *     reserve_reversal_1: int,
     *     reserve_reversal_2: int,
     *     total_reserve_reversals: int,
     *     family_employee_salaries: int,
     *     bad_debt_reserve_provision: int,
     *     reserve_provision_1: int,
     *     reserve_provision_2: int,
     *     total_reserve_provisions: int,
     *     income_before_blue_return_deduction: int,
     *     blue_return_deduction: int,
     *     business_income: int
     * }
     */
    private function calculateProfitAndLoss(FiscalYear $fiscalYear, int $blueReturnDeduction): array
    {
        if ($blueReturnDeduction < 0) {
            throw new \InvalidArgumentException("青色申告特別控除額が不正です: {$blueReturnDeduction}");
        }

        $totalsByAccountName = $this->summarizeSignedGrossAmounts(
            $fiscalYear,
            array_merge(
                self::revenueAccountNames(),
                self::inventoryAccountNames(),
                self::expenseAccountNames()
            )
        );

        $salesAmount = $this->sumAccountNames($totalsByAccountName, self::revenueAccountNames());
        $beginningInventory = $this->amountForAccount($totalsByAccountName, '期首商品（棚卸高）');
        $purchasesAmount = $this->amountForAccount($totalsByAccountName, '仕入金額');
        // ⑤は貸方残高（負の費用）なので、欄には符号を反転して正の値で出す
        $endingInventory = -$this->amountForAccount($totalsByAccountName, '期末商品（棚卸高）');
        $purchasesSubtotal = $beginningInventory + $purchasesAmount;
        $costOfGoodsSold = $purchasesSubtotal - $endingInventory;
        $grossProfit = $salesAmount - $costOfGoodsSold;

        $taxesAndDues = $this->amountForAccount($totalsByAccountName, '租税公課');
        $packingAndFreight = $this->amountForAccount($totalsByAccountName, '荷造運賃');
        $utilities = $this->amountForAccount($totalsByAccountName, '水道光熱費');
        $travelExpenses = $this->amountForAccount($totalsByAccountName, '旅費交通費');
        $communicationExpenses = $this->amountForAccount($totalsByAccountName, '通信費');
        $advertisingExpenses = $this->amountForAccount($totalsByAccountName, '広告宣伝費');
        $entertainmentExpenses = $this->amountForAccount($totalsByAccountName, '接待交際費');
        $casualtyInsurance = $this->amountForAccount($totalsByAccountName, '損害保険料');
        $repairExpenses = $this->amountForAccount($totalsByAccountName, '修繕費');
        $suppliesExpenses = $this->amountForAccount($totalsByAccountName, '消耗品費');
        $depreciationExpense = $this->amountForAccount($totalsByAccountName, '減価償却費');
        $welfareExpenses = $this->amountForAccount($totalsByAccountName, '福利厚生費');
        $wages = $this->amountForAccount($totalsByAccountName, '給料賃金');
        $outsourcingCosts = $this->amountForAccount($totalsByAccountName, '外注工賃');
        $interestAndDiscounts = $this->amountForAccount($totalsByAccountName, '利子割引料');
        $rentExpenses = $this->amountForAccount($totalsByAccountName, '地代家賃');
        $badDebts = $this->amountForAccount($totalsByAccountName, '貸倒金');
        $miscellaneousExpenses = $this->amountForAccount($totalsByAccountName, '雑費');

        $totalExpenses = array_sum([
            $taxesAndDues,
            $packingAndFreight,
            $utilities,
            $travelExpenses,
            $communicationExpenses,
            $advertisingExpenses,
            $entertainmentExpenses,
            $casualtyInsurance,
            $repairExpenses,
            $suppliesExpenses,
            $depreciationExpense,
            $welfareExpenses,
            $wages,
            $outsourcingCosts,
            $interestAndDiscounts,
            $rentExpenses,
            $badDebts,
            $miscellaneousExpenses,
        ]);

        $profitBeforeReserves = $grossProfit - $totalExpenses;

        $badDebtReserveReversal = 0;
        $reserveReversal1 = 0;
        $reserveReversal2 = 0;
        $totalReserveReversals = $badDebtReserveReversal + $reserveReversal1 + $reserveReversal2;

        $familyEmployeeSalaries = $this->amountForAccount($totalsByAccountName, '専従者給与');
        $badDebtReserveProvision = 0;
        $reserveProvision1 = 0;
        $reserveProvision2 = 0;
        $totalReserveProvisions = $familyEmployeeSalaries + $badDebtReserveProvision + $reserveProvision1 + $reserveProvision2;

        $incomeBeforeBlueReturnDeduction = $profitBeforeReserves + $totalReserveReversals - $totalReserveProvisions;
        $blueReturnDeduction = min($blueReturnDeduction, max($incomeBeforeBlueReturnDeduction, 0));
        $businessIncome = $incomeBeforeBlueReturnDeduction - $blueReturnDeduction;

        return [
            'sales_amount' => $salesAmount,
            'beginning_inventory' => $beginningInventory,
            'purchases_amount' => $purchasesAmount,
            'purchases_subtotal' => $purchasesSubtotal,
            'ending_inventory' => $endingInventory,
            'cost_of_goods_sold' => $costOfGoodsSold,
            'gross_profit' => $grossProfit,
            'taxes_and_dues' => $taxesAndDues,
            'packing_and_freight' => $packingAndFreight,
            'utilities' => $utilities,
            'travel_expenses' => $travelExpenses,
            'communication_expenses' => $communicationExpenses,
            'advertising_expenses' => $advertisingExpenses,
            'entertainment_expenses' => $entertainmentExpenses,
            'casualty_insurance' => $casualtyInsurance,
            'repair_expenses' => $repairExpenses,
            'supplies_expenses' => $suppliesExpenses,
            'depreciation_expense' => $depreciationExpense,
            'welfare_expenses' => $welfareExpenses,
            'wages' => $wages,
            'outsourcing_costs' => $outsourcingCosts,
            'interest_and_discounts' => $interestAndDiscounts,
            'rent_expenses' => $rentExpenses,
            'bad_debts' => $badDebts,
            'custom_expense_1' => 0,
            'custom_expense_2' => 0,
            'custom_expense_3' => 0,
            'custom_expense_4' => 0,
            'custom_expense_5' => 0,
            'custom_expense_6' => 0,
            'miscellaneous_expenses' => $miscellaneousExpenses,
            'total_expenses' => $totalExpenses,
            'profit_before_reserves' => $profitBeforeReserves,
            'bad_debt_reserve_reversal' => $badDebtReserveReversal,
            'reserve_reversal_1' => $reserveReversal1,
            'reserve_reversal_2' => $reserveReversal2,
            'total_reserve_reversals' => $totalReserveReversals,
            'family_employee_salaries' => $familyEmployeeSalaries,
            'bad_debt_reserve_provision' => $badDebtReserveProvision,
            'reserve_provision_1' => $reserveProvision1,
            'reserve_provision_2' => $reserveProvision2,
            'total_reserve_provisions' => $totalReserveProvisions,
            'income_before_blue_return_deduction' => $incomeBeforeBlueReturnDeduction,
            'blue_return_deduction' => $blueReturnDeduction,
            'business_income' => $businessIncome,
        ];
    }

    /**
     * @param  array<int, string>  $accountNames
     * @return array<string, int>
     */
    private function summarizeSignedGrossAmounts(FiscalYear $fiscalYear, array $accountNames): array
    {
        if ($accountNames === []) {
            return [];
        }

        $rows = JournalEntry::query()
            ->join('sub_accounts', 'sub_accounts.id', '=', 'journal_entries.sub_account_id')
            ->join('accounts', 'accounts.id', '=', 'sub_accounts.account_id')
            ->whereHas('transaction', function (Builder $query) use ($fiscalYear): void {
                $query
                    ->whereBelongsTo($fiscalYear)
                    ->where('is_active', true)
                    ->where('is_planned', false);
            })
            ->whereIn('accounts.name', $accountNames)
            ->groupBy('accounts.name')
            ->select('accounts.name as account_name')
            ->selectRaw(
                'COALESCE(SUM(CASE'
                .' WHEN (accounts.type = ? AND journal_entries.type = ?)'
                .' OR (accounts.type != ? AND journal_entries.type = ?)'
                .' THEN journal_entries.net_amount + COALESCE(journal_entries.tax_amount, 0)'
                .' ELSE -(journal_entries.net_amount + COALESCE(journal_entries.tax_amount, 0))'
                .' END), 0) as summary_signed_gross_amount',
                [
                    Account::TYPE_REVENUE,
                    JournalEntry::TYPE_CREDIT,
                    Account::TYPE_REVENUE,
                    JournalEntry::TYPE_DEBIT,
                ]
            )
            ->get();

        $totals = array_fill_keys($accountNames, 0);

        foreach ($rows as $row) {
            $totals[$row->account_name] = (int) $row->summary_signed_gross_amount;
        }

        return $totals;
    }

    /**
     * @param  array<string, int>  $totalsByAccountName
     * @param  array<int, string>  $accountNames
     */
    private function sumAccountNames(array $totalsByAccountName, array $accountNames): int
    {
        return array_sum(array_map(
            static fn (string $accountName): int => $totalsByAccountName[$accountName] ?? 0,
            $accountNames
        ));
    }

    /**
     * @param  array<string, int>  $totalsByAccountName
     */
    private function amountForAccount(array $totalsByAccountName, string $accountName): int
    {
        return $totalsByAccountName[$accountName] ?? 0;
    }

    /**
     * @return array<int, string>
     */
    private static function revenueAccountNames(): array
    {
        return ['売上高', '雑収入', '家事消費等'];
    }

    /**
     * @return array<int, string>
     */
    private static function inventoryAccountNames(): array
    {
        return ['期首商品（棚卸高）', '仕入金額', '期末商品（棚卸高）'];
    }

    /**
     * @return array<int, string>
     */
    private static function expenseAccountNames(): array
    {
        return [
            '租税公課',
            '荷造運賃',
            '水道光熱費',
            '旅費交通費',
            '通信費',
            '広告宣伝費',
            '接待交際費',
            '損害保険料',
            '修繕費',
            '消耗品費',
            '減価償却費',
            '福利厚生費',
            '給料賃金',
            '外注工賃',
            '利子割引料',
            '地代家賃',
            '貸倒金',
            '専従者給与',
            '雑費',
        ];
    }
}
