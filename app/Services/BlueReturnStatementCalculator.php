<?php

namespace App\Services;

use App\Models\Account;
use App\Models\DepreciationEntry;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class BlueReturnStatementCalculator
{
    public function __construct(
        private readonly DepreciationService $depreciationService,
        private readonly FiscalYearBalanceCalculator $balanceCalculator
    ) {}

    /**
     * @return array{
     *     profit_and_loss: array{
     *         sales_amount: int,
     *         beginning_inventory: int,
     *         purchases_amount: int,
     *         purchases_subtotal: int,
     *         ending_inventory: int,
     *         cost_of_goods_sold: int,
     *         gross_profit: int,
     *         taxes_and_dues: int,
     *         packing_and_freight: int,
     *         utilities: int,
     *         travel_expenses: int,
     *         communication_expenses: int,
     *         advertising_expenses: int,
     *         entertainment_expenses: int,
     *         casualty_insurance: int,
     *         repair_expenses: int,
     *         supplies_expenses: int,
     *         depreciation_expense: int,
     *         welfare_expenses: int,
     *         wages: int,
     *         outsourcing_costs: int,
     *         interest_and_discounts: int,
     *         rent_expenses: int,
     *         bad_debts: int,
     *         custom_expense_1: int,
     *         custom_expense_2: int,
     *         custom_expense_3: int,
     *         custom_expense_4: int,
     *         custom_expense_5: int,
     *         custom_expense_6: int,
     *         miscellaneous_expenses: int,
     *         total_expenses: int,
     *         profit_before_reserves: int,
     *         bad_debt_reserve_reversal: int,
     *         reserve_reversal_1: int,
     *         reserve_reversal_2: int,
     *         total_reserve_reversals: int,
     *         family_employee_salaries: int,
     *         bad_debt_reserve_provision: int,
     *         reserve_provision_1: int,
     *         reserve_provision_2: int,
     *         total_reserve_provisions: int,
     *         income_before_blue_return_deduction: int,
     *         blue_return_deduction: int,
     *         business_income: int
     *     },
     *     custom_expense_labels: array{
     *         custom_expense_1_label: string,
     *         custom_expense_2_label: string,
     *         custom_expense_3_label: string,
     *         custom_expense_4_label: string,
     *         custom_expense_5_label: string,
     *         custom_expense_6_label: string
     *     },
     *     monthly_sales_and_purchases: array{
     *         months: array<int, array{
     *             year_month: string,
     *             label: string,
     *             sales_amount: int,
     *             house_consumption_amount: int,
     *             misc_income_amount: int,
     *             purchases_amount: int
     *         }>,
     *         totals: array{
     *             sales_amount: int,
     *             house_consumption_amount: int,
     *             misc_income_amount: int,
     *             purchases_amount: int
     *         }
     *     },
     *     depreciation_calculation: array{
     *         entries: array<int, array{
     *             fixed_asset_name: string,
     *             quantity: int,
     *             acquisition_year_month: ?string,
     *             depreciation_base_amount: ?int,
     *             depreciation_method: ?string,
     *             useful_life: ?int,
     *             depreciation_rate: ?string,
     *             months: int,
     *             ordinary_amount: int,
     *             total_amount: int,
     *             business_usage_ratio: string|int|float,
     *             deductible_amount: int,
     *             ending_undepreciated_balance: ?int
     *         }>,
     *         totals: array{
     *             ordinary_amount: int,
     *             total_amount: int,
     *             deductible_amount: int,
     *             ledger_depreciation_expense: int,
     *             difference: int
     *         }
     *     },
     *     balance_sheet: array{
     *         income_before_blue_return_deduction: int,
     *         sections: array{
     *             asset: array{
     *                 type: string,
     *                 label: string,
     *                 opening_total_balance: int,
     *                 ending_total_balance: int,
     *                 rows: array<int, array{
     *                     account_id: int,
     *                     account_name: string,
     *                     opening_balance: int,
     *                     ending_balance: int,
     *                     rows: array<int, array{
     *                         sub_account_id: int,
     *                         sub_account_name: string,
     *                         opening_balance: int,
     *                         ending_balance: int
     *                     }>
     *                 }>
     *             },
     *             liability: array{
     *                 type: string,
     *                 label: string,
     *                 opening_total_balance: int,
     *                 ending_total_balance: int,
     *                 rows: array<int, array{
     *                     account_id: int,
     *                     account_name: string,
     *                     opening_balance: int,
     *                     ending_balance: int,
     *                     rows: array<int, array{
     *                         sub_account_id: int,
     *                         sub_account_name: string,
     *                         opening_balance: int,
     *                         ending_balance: int
     *                     }>
     *                 }>
     *             },
     *             equity: array{
     *                 type: string,
     *                 label: string,
     *                 opening_total_balance: int,
     *                 ending_total_balance: int,
     *                 rows: array<int, array{
     *                     account_id: int,
     *                     account_name: string,
     *                     opening_balance: int,
     *                     ending_balance: int,
     *                     rows: array<int, array{
     *                         sub_account_id: int,
     *                         sub_account_name: string,
     *                         opening_balance: int,
     *                         ending_balance: int
     *                     }>
     *                 }>
     *             }
     *         },
     *         totals: array{
     *             opening: array{
     *                 asset: int,
     *                 liability: int,
     *                 equity: int
     *             },
     *             ending: array{
     *                 asset: int,
     *                 liability: int,
     *                 equity: int
     *             }
     *         }
     *     }
     * }
     */
    public function calculate(FiscalYear $fiscalYear, int $blueReturnDeduction): array
    {
        $customExpenseAccountNames = $this->customExpenseAccountNames($fiscalYear);
        $totalsByAccountName = $this->summarizeSignedGrossAmounts(
            $fiscalYear,
            array_merge(
                self::revenueAccountNames(),
                self::inventoryAccountNames(),
                self::expenseAccountNames(),
                $customExpenseAccountNames
            )
        );

        $profitAndLoss = $this->calculateProfitAndLoss($totalsByAccountName, $customExpenseAccountNames, $blueReturnDeduction);
        $openingBalanceSummary = $this->balanceCalculator->calculateOpening($fiscalYear);
        $endingBalanceSummary = $this->balanceCalculator->calculate($fiscalYear);

        return [
            'profit_and_loss' => $profitAndLoss,
            'custom_expense_labels' => $this->customExpenseLabelMap($customExpenseAccountNames, $profitAndLoss),
            'monthly_sales_and_purchases' => $this->calculateMonthlySalesAndPurchases($fiscalYear),
            'depreciation_calculation' => $this->calculateDepreciationCalculation($fiscalYear, $totalsByAccountName),
            'balance_sheet' => $this->calculateBalanceSheet($profitAndLoss, $openingBalanceSummary, $endingBalanceSummary),
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
    private function calculateProfitAndLoss(array $totalsByAccountName, array $customExpenseAccountNames, int $blueReturnDeduction): array
    {
        if ($blueReturnDeduction < 0) {
            throw new \InvalidArgumentException("青色申告特別控除額が不正です: {$blueReturnDeduction}");
        }

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
        $customExpenses = $this->customExpenseAmounts($totalsByAccountName, $customExpenseAccountNames);
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
            ...$customExpenses,
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
            'custom_expense_1' => $customExpenses[0],
            'custom_expense_2' => $customExpenses[1],
            'custom_expense_3' => $customExpenses[2],
            'custom_expense_4' => $customExpenses[3],
            'custom_expense_5' => $customExpenses[4],
            'custom_expense_6' => $customExpenses[5],
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
     * @param  array<string, int>  $totalsByAccountName
     * @return array{
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
     *     totals: array{
     *         ordinary_amount: int,
     *         total_amount: int,
     *         deductible_amount: int,
     *         ledger_depreciation_expense: int,
     *         difference: int
     *     }
     * }
     */
    private function calculateDepreciationCalculation(FiscalYear $fiscalYear, array $totalsByAccountName): array
    {
        $entries = DepreciationEntry::query()
            ->with(['fixedAsset'])
            ->whereHas('fiscalYear', function (Builder $query) use ($fiscalYear): void {
                $query
                    ->where('business_unit_id', $fiscalYear->business_unit_id)
                    ->where('year', '<=', $fiscalYear->year);
            })
            ->orderBy('fixed_asset_id')
            ->orderBy('fiscal_year_id')
            ->get();

        $currentYearEntries = $entries
            ->groupBy('fixed_asset_id')
            ->map(function ($assetEntries) use ($fiscalYear): ?array {
                $currentEntry = $assetEntries->firstWhere('fiscal_year_id', $fiscalYear->id);

                if ($currentEntry === null) {
                    return null;
                }

                return [
                    'fixed_asset_name' => (string) ($currentEntry->fixedAsset?->name ?? ''),
                    'quantity' => 1,
                    'acquisition_year_month' => $currentEntry->acquisition_year_month,
                    'depreciation_base_amount' => $currentEntry->depreciation_base_amount,
                    'depreciation_method' => $currentEntry->depreciation_method,
                    'useful_life' => $currentEntry->useful_life,
                    'depreciation_rate' => $currentEntry->depreciation_rate,
                    'months' => (int) $currentEntry->months,
                    'ordinary_amount' => (int) $currentEntry->ordinary_amount,
                    'total_amount' => (int) $currentEntry->total_amount,
                    'business_usage_ratio' => $currentEntry->business_usage_ratio,
                    'deductible_amount' => (int) $currentEntry->deductible_amount,
                    'ending_undepreciated_balance' => $this->depreciationService->calculateEndingUndepreciatedBalance(
                        $currentEntry->fixedAsset,
                        $fiscalYear
                    ),
                ];
            })
            ->filter()
            ->values()
            ->all();

        $ordinaryAmountTotal = array_sum(array_column($currentYearEntries, 'ordinary_amount'));
        $totalAmountTotal = array_sum(array_column($currentYearEntries, 'total_amount'));
        $deductibleAmountTotal = array_sum(array_column($currentYearEntries, 'deductible_amount'));
        $ledgerDepreciationExpense = $this->amountForAccount($totalsByAccountName, '減価償却費');

        return [
            'entries' => $currentYearEntries,
            'totals' => [
                'ordinary_amount' => $ordinaryAmountTotal,
                'total_amount' => $totalAmountTotal,
                'deductible_amount' => $deductibleAmountTotal,
                'ledger_depreciation_expense' => $ledgerDepreciationExpense,
                'difference' => $deductibleAmountTotal - $ledgerDepreciationExpense,
            ],
        ];
    }

    /**
     * @param  array<string, int>  $profitAndLoss
     * @return array{
     *     income_before_blue_return_deduction: int,
     *     sections: array{
     *         asset: array{
     *             type: string,
     *             label: string,
     *             opening_total_balance: int,
     *             ending_total_balance: int,
     *             rows: array<int, array{
     *                 account_id: int,
     *                 account_name: string,
     *                 opening_balance: int,
     *                 ending_balance: int,
     *                 rows: array<int, array{
     *                     sub_account_id: int,
     *                     sub_account_name: string,
     *                     opening_balance: int,
     *                     ending_balance: int
     *                 }>
     *             }>
     *         },
     *         liability: array{
     *             type: string,
     *             label: string,
     *             opening_total_balance: int,
     *             ending_total_balance: int,
     *             rows: array<int, array{
     *                 account_id: int,
     *                 account_name: string,
     *                 opening_balance: int,
     *                 ending_balance: int,
     *                 rows: array<int, array{
     *                     sub_account_id: int,
     *                     sub_account_name: string,
     *                     opening_balance: int,
     *                     ending_balance: int
     *                 }>
     *             }>
     *         },
     *         equity: array{
     *             type: string,
     *             label: string,
     *             opening_total_balance: int,
     *             ending_total_balance: int,
     *             rows: array<int, array{
     *                 account_id: int,
     *                 account_name: string,
     *                 opening_balance: int,
     *                 ending_balance: int,
     *                 rows: array<int, array{
     *                     sub_account_id: int,
     *                     sub_account_name: string,
     *                     opening_balance: int,
     *                     ending_balance: int
     *                 }>
     *             }>
     *         }
     *     },
     *     totals: array{
     *         opening: array{
     *             asset: int,
     *             liability: int,
     *             equity: int
     *         },
     *         ending: array{
     *             asset: int,
     *             liability: int,
     *             equity: int
     *         }
     *     }
     * }
     */
    private function calculateBalanceSheet(
        array $profitAndLoss,
        array $openingBalanceSummary,
        array $endingBalanceSummary
    ): array {
        return [
            'income_before_blue_return_deduction' => $profitAndLoss['income_before_blue_return_deduction'],
            'sections' => [
                Account::TYPE_ASSET => $this->mapBalanceSection(
                    $openingBalanceSummary[Account::TYPE_ASSET],
                    $endingBalanceSummary[Account::TYPE_ASSET],
                    '資産の部',
                    Account::TYPE_ASSET
                ),
                Account::TYPE_LIABILITY => $this->mapBalanceSection(
                    $openingBalanceSummary[Account::TYPE_LIABILITY],
                    $endingBalanceSummary[Account::TYPE_LIABILITY],
                    '負債の部',
                    Account::TYPE_LIABILITY
                ),
                Account::TYPE_EQUITY => $this->mapBalanceSection(
                    $openingBalanceSummary[Account::TYPE_EQUITY],
                    $endingBalanceSummary[Account::TYPE_EQUITY],
                    '純資産の部',
                    Account::TYPE_EQUITY
                ),
            ],
            'totals' => [
                'opening' => [
                    Account::TYPE_ASSET => $openingBalanceSummary[Account::TYPE_ASSET]['total_balance'],
                    Account::TYPE_LIABILITY => $openingBalanceSummary[Account::TYPE_LIABILITY]['total_balance'],
                    Account::TYPE_EQUITY => $openingBalanceSummary[Account::TYPE_EQUITY]['total_balance'],
                ],
                'ending' => [
                    Account::TYPE_ASSET => $endingBalanceSummary[Account::TYPE_ASSET]['total_balance'],
                    Account::TYPE_LIABILITY => $endingBalanceSummary[Account::TYPE_LIABILITY]['total_balance'],
                    Account::TYPE_EQUITY => $endingBalanceSummary[Account::TYPE_EQUITY]['total_balance'],
                ],
            ],
        ];
    }

    /**
     * @param  array{total_balance: int, accounts: array<int, array{account_id: int, account_name: string, balance: int, sub_accounts: array<int, array{sub_account_id: int, sub_account_name: string, balance: int}>}>}  $openingTypeSummary
     * @param  array{total_balance: int, accounts: array<int, array{account_id: int, account_name: string, balance: int, sub_accounts: array<int, array{sub_account_id: int, sub_account_name: string, balance: int}>}>}  $endingTypeSummary
     * @return array{
     *     type: string,
     *     label: string,
     *     opening_total_balance: int,
     *     ending_total_balance: int,
     *     rows: array<int, array{
     *         account_id: int,
     *         account_name: string,
     *         opening_balance: int,
     *         ending_balance: int,
     *         rows: array<int, array{
     *             sub_account_id: int,
     *             sub_account_name: string,
     *             opening_balance: int,
     *             ending_balance: int
     *         }>
     *     }>
     * }
     */
    private function mapBalanceSection(array $openingTypeSummary, array $endingTypeSummary, string $label, string $type): array
    {
        $openingAccounts = $this->indexAccounts($openingTypeSummary['accounts']);
        $endingAccounts = $this->indexAccounts($endingTypeSummary['accounts']);
        $accountIds = array_values(array_unique(array_merge(array_keys($openingAccounts), array_keys($endingAccounts))));
        $rows = [];

        foreach ($accountIds as $accountId) {
            $openingAccount = $openingAccounts[$accountId] ?? null;
            $endingAccount = $endingAccounts[$accountId] ?? null;
            $openingSubAccounts = $this->indexSubAccounts($openingAccount['sub_accounts'] ?? []);
            $endingSubAccounts = $this->indexSubAccounts($endingAccount['sub_accounts'] ?? []);
            $subAccountIds = array_values(array_unique(array_merge(array_keys($openingSubAccounts), array_keys($endingSubAccounts))));

            $rows[] = [
                'account_id' => $accountId,
                'account_name' => (string) ($endingAccount['account_name'] ?? $openingAccount['account_name'] ?? ''),
                'opening_balance' => (int) ($openingAccount['balance'] ?? 0),
                'ending_balance' => (int) ($endingAccount['balance'] ?? 0),
                'rows' => array_map(
                    static function (int $subAccountId) use ($openingSubAccounts, $endingSubAccounts): array {
                        $openingSubAccount = $openingSubAccounts[$subAccountId] ?? null;
                        $endingSubAccount = $endingSubAccounts[$subAccountId] ?? null;

                        return [
                            'sub_account_id' => $subAccountId,
                            'sub_account_name' => (string) ($endingSubAccount['sub_account_name'] ?? $openingSubAccount['sub_account_name'] ?? ''),
                            'opening_balance' => (int) ($openingSubAccount['balance'] ?? 0),
                            'ending_balance' => (int) ($endingSubAccount['balance'] ?? 0),
                        ];
                    },
                    $subAccountIds
                ),
            ];
        }

        return [
            'type' => $type,
            'label' => $label,
            'opening_total_balance' => $openingTypeSummary['total_balance'],
            'ending_total_balance' => $endingTypeSummary['total_balance'],
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<int, array{
     *     account_id: int,
     *     account_name: string,
     *     balance: int,
     *     sub_accounts: array<int, array{
     *         sub_account_id: int,
     *         sub_account_name: string,
     *         balance: int
     *     }>
     * }>  $accounts
     * @return array<int, array{
     *     account_id: int,
     *     account_name: string,
     *     balance: int,
     *     sub_accounts: array<int, array{
     *         sub_account_id: int,
     *         sub_account_name: string,
     *         balance: int
     *     }>
     * }>
     */
    private function indexAccounts(array $accounts): array
    {
        $indexed = [];

        foreach ($accounts as $account) {
            $indexed[$account['account_id']] = $account;
        }

        return $indexed;
    }

    /**
     * @param  array<int, array{
     *     sub_account_id: int,
     *     sub_account_name: string,
     *     balance: int
     * }>  $subAccounts
     * @return array<int, array{
     *     sub_account_id: int,
     *     sub_account_name: string,
     *     balance: int
     * }>
     */
    private function indexSubAccounts(array $subAccounts): array
    {
        $indexed = [];

        foreach ($subAccounts as $subAccount) {
            $indexed[$subAccount['sub_account_id']] = $subAccount;
        }

        return $indexed;
    }

    /**
     * @return array{
     *     months: array<int, array{
     *         year_month: string,
     *         label: string,
     *         sales_amount: int,
     *         house_consumption_amount: int,
     *         misc_income_amount: int,
     *         purchases_amount: int
     *     }>,
     *     totals: array{
     *         sales_amount: int,
     *         house_consumption_amount: int,
     *         misc_income_amount: int,
     *         purchases_amount: int
     *     }
     * }
     */
    private function calculateMonthlySalesAndPurchases(FiscalYear $fiscalYear): array
    {
        $months = $this->initializeMonthlySalesAndPurchasesMonths($fiscalYear);
        $monthIndexByYearMonth = [];

        foreach ($months as $index => $month) {
            $monthIndexByYearMonth[$month['year_month']] = $index;
        }

        $monthlyFieldMap = self::monthlyAccountFieldMap();

        $rows = JournalEntry::query()
            ->join('transactions', 'journal_entries.transaction_id', '=', 'transactions.id')
            ->join('sub_accounts', 'journal_entries.sub_account_id', '=', 'sub_accounts.id')
            ->join('accounts', 'sub_accounts.account_id', '=', 'accounts.id')
            ->where('transactions.fiscal_year_id', $fiscalYear->id)
            ->where('transactions.is_active', true)
            ->where('transactions.is_planned', false)
            ->whereBetween('transactions.date', [$fiscalYear->start_date, $fiscalYear->end_date])
            ->whereIn('accounts.name', array_keys($monthlyFieldMap))
            ->groupBy('transactions.date', 'accounts.name')
            ->selectRaw('transactions.date as transaction_date')
            ->selectRaw('accounts.name as account_name')
            ->selectRaw(
                self::signedGrossAmountSumSql('signed_gross_amount'),
                self::signedGrossAmountSumBindings()
            )
            ->get();

        foreach ($rows as $row) {
            $yearMonth = CarbonImmutable::parse((string) $row->transaction_date)->format('Y-m');
            $monthIndex = $monthIndexByYearMonth[$yearMonth] ?? null;

            if ($monthIndex === null) {
                continue;
            }

            $field = $monthlyFieldMap[(string) $row->account_name];

            $months[$monthIndex][$field] += (int) $row->signed_gross_amount;
        }

        $totals = [
            'sales_amount' => 0,
            'house_consumption_amount' => 0,
            'misc_income_amount' => 0,
            'purchases_amount' => 0,
        ];

        foreach ($months as $month) {
            foreach ($totals as $key => $value) {
                $totals[$key] += $month[$key];
            }
        }

        return [
            'months' => $months,
            'totals' => $totals,
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
                    ->whereBetween('date', [
                        $fiscalYear->start_date,
                        $fiscalYear->end_date,
                    ])
                    ->where('is_active', true)
                    ->where('is_planned', false);
            })
            ->whereIn('accounts.name', $accountNames)
            ->groupBy('accounts.name')
            ->select('accounts.name as account_name')
            ->selectRaw(
                self::signedGrossAmountSumSql('summary_signed_gross_amount'),
                self::signedGrossAmountSumBindings()
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
     * 収益科目は貸方、それ以外の科目は借方を正とする税込金額合計の select 式。
     * バインディングは signedGrossAmountSumBindings() とペアで使う。
     */
    private static function signedGrossAmountSumSql(string $alias): string
    {
        return 'COALESCE(SUM(CASE'
            .' WHEN (accounts.type = ? AND journal_entries.type = ?)'
            .' OR (accounts.type != ? AND journal_entries.type = ?)'
            .' THEN journal_entries.net_amount + COALESCE(journal_entries.tax_amount, 0)'
            .' ELSE -(journal_entries.net_amount + COALESCE(journal_entries.tax_amount, 0))'
            .' END), 0) as '.$alias;
    }

    /**
     * @return array<int, string>
     */
    private static function signedGrossAmountSumBindings(): array
    {
        return [
            Account::TYPE_REVENUE,
            JournalEntry::TYPE_CREDIT,
            Account::TYPE_REVENUE,
            JournalEntry::TYPE_DEBIT,
        ];
    }

    /**
     * @return array<int, array{year_month: string, label: string, sales_amount: int, house_consumption_amount: int, misc_income_amount: int, purchases_amount: int}>
     */
    private function initializeMonthlySalesAndPurchasesMonths(FiscalYear $fiscalYear): array
    {
        $months = [];
        $cursor = CarbonImmutable::parse($fiscalYear->start_date->toDateString())->startOfMonth();
        $endMonth = CarbonImmutable::parse($fiscalYear->end_date->toDateString())->startOfMonth();

        while ($cursor->lessThanOrEqualTo($endMonth)) {
            $months[] = [
                'year_month' => $cursor->format('Y-m'),
                'label' => $cursor->format('n').'月',
                'sales_amount' => 0,
                'house_consumption_amount' => 0,
                'misc_income_amount' => 0,
                'purchases_amount' => 0,
            ];

            $cursor = $cursor->addMonth();
        }

        return $months;
    }

    /**
     * @return array<string, string>
     */
    private static function monthlyAccountFieldMap(): array
    {
        return [
            '売上高' => 'sales_amount',
            '家事消費等' => 'house_consumption_amount',
            '雑収入' => 'misc_income_amount',
            '仕入金額' => 'purchases_amount',
        ];
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

    /**
     * @return array<int, string>
     */
    private function customExpenseAccountNames(FiscalYear $fiscalYear): array
    {
        return $fiscalYear->businessUnit
            ->accounts()
            ->where('type', Account::TYPE_EXPENSE)
            ->whereNotIn('name', array_merge(self::inventoryAccountNames(), self::expenseAccountNames()))
            ->orderBy('id')
            ->pluck('name')
            ->all();
    }

    /**
     * @param  array<string, int>  $totalsByAccountName
     * @param  array<int, string>  $customExpenseAccountNames
     * @return array<int, int>
     */
    private function customExpenseAmounts(array $totalsByAccountName, array $customExpenseAccountNames): array
    {
        $customExpenses = array_map(
            fn (string $accountName): int => $this->amountForAccount($totalsByAccountName, $accountName),
            $customExpenseAccountNames
        );

        $nonZeroCustomExpenseCount = count(array_filter(
            $customExpenses,
            static fn (int $amount): bool => $amount !== 0
        ));

        if ($nonZeroCustomExpenseCount > 6) {
            throw new RuntimeException('青色申告決算書の任意科目欄(6行)を超える費用勘定があります。');
        }

        return array_pad(array_slice($customExpenses, 0, 6), 6, 0);
    }

    /**
     * @param  array<int, string>  $customExpenseAccountNames
     * @param  array<string, int>  $profitAndLoss
     * @return array{
     *     custom_expense_1_label: string,
     *     custom_expense_2_label: string,
     *     custom_expense_3_label: string,
     *     custom_expense_4_label: string,
     *     custom_expense_5_label: string,
     *     custom_expense_6_label: string
     * }
     */
    private function customExpenseLabelMap(array $customExpenseAccountNames, array $profitAndLoss): array
    {
        $labels = [];

        foreach (range(1, 6) as $index) {
            $fieldKey = "custom_expense_{$index}";
            $labels["{$fieldKey}_label"] = $profitAndLoss[$fieldKey] === 0
                ? ''
                : ($customExpenseAccountNames[$index - 1] ?? '');
        }

        /** @var array{
         *     custom_expense_1_label: string,
         *     custom_expense_2_label: string,
         *     custom_expense_3_label: string,
         *     custom_expense_4_label: string,
         *     custom_expense_5_label: string,
         *     custom_expense_6_label: string
         * } $labels
         */
        return $labels;
    }
}
