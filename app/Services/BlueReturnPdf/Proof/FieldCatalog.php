<?php

namespace App\Services\BlueReturnPdf\Proof;

use App\Concerns\SkipActorGuard;

#[SkipActorGuard('PDF 校正用のフィールドメタデータ。認可対象のリソースを持たない。')]
class FieldCatalog
{
    /**
     * 損益計算書の各欄。field_number は様式に印字された欄番号（①〜㊺）。
     *
     * 校正PDFでは欄番号を繰り返した数字をテスト値として描画するため、
     * 様式の丸数字と見比べるだけで欄の取り違え・位置ズレを目視検出できる。
     *
     * @return array<string, array{label: string, field_number: int}>
     */
    public static function profitAndLossFields(): array
    {
        return [
            'sales_amount' => ['label' => '売上（収入）金額（雑収入を含む）', 'field_number' => 1],
            'beginning_inventory' => ['label' => '期首商品（製品）棚卸高', 'field_number' => 2],
            'purchases_amount' => ['label' => '仕入金額（製品製造原価）', 'field_number' => 3],
            'purchases_subtotal' => ['label' => '小計', 'field_number' => 4],
            'ending_inventory' => ['label' => '期末商品（製品）棚卸高', 'field_number' => 5],
            'cost_of_goods_sold' => ['label' => '差引原価', 'field_number' => 6],
            'gross_profit' => ['label' => '差引金額', 'field_number' => 7],
            'taxes_and_dues' => ['label' => '租税公課', 'field_number' => 8],
            'packing_and_freight' => ['label' => '荷造運賃', 'field_number' => 9],
            'utilities' => ['label' => '水道光熱費', 'field_number' => 10],
            'travel_expenses' => ['label' => '旅費交通費', 'field_number' => 11],
            'communication_expenses' => ['label' => '通信費', 'field_number' => 12],
            'advertising_expenses' => ['label' => '広告宣伝費', 'field_number' => 13],
            'entertainment_expenses' => ['label' => '接待交際費', 'field_number' => 14],
            'casualty_insurance' => ['label' => '損害保険料', 'field_number' => 15],
            'repair_expenses' => ['label' => '修繕費', 'field_number' => 16],
            'supplies_expenses' => ['label' => '消耗品費', 'field_number' => 17],
            'depreciation_expense' => ['label' => '減価償却費', 'field_number' => 18],
            'welfare_expenses' => ['label' => '福利厚生費', 'field_number' => 19],
            'wages' => ['label' => '給料賃金', 'field_number' => 20],
            'outsourcing_costs' => ['label' => '外注工賃', 'field_number' => 21],
            'interest_and_discounts' => ['label' => '利子割引料', 'field_number' => 22],
            'rent_expenses' => ['label' => '地代家賃', 'field_number' => 23],
            'bad_debts' => ['label' => '貸倒金', 'field_number' => 24],
            'custom_expense_1' => ['label' => '任意科目1', 'field_number' => 25],
            'custom_expense_2' => ['label' => '任意科目2', 'field_number' => 26],
            'custom_expense_3' => ['label' => '任意科目3', 'field_number' => 27],
            'custom_expense_4' => ['label' => '任意科目4', 'field_number' => 28],
            'custom_expense_5' => ['label' => '任意科目5', 'field_number' => 29],
            'custom_expense_6' => ['label' => '任意科目6', 'field_number' => 30],
            'miscellaneous_expenses' => ['label' => '雑費', 'field_number' => 31],
            'total_expenses' => ['label' => '計', 'field_number' => 32],
            'profit_before_reserves' => ['label' => '差引金額', 'field_number' => 33],
            'bad_debt_reserve_reversal' => ['label' => '貸倒引当金(繰戻額等)', 'field_number' => 34],
            'reserve_reversal_1' => ['label' => '繰戻額等1', 'field_number' => 35],
            'reserve_reversal_2' => ['label' => '繰戻額等2', 'field_number' => 36],
            'total_reserve_reversals' => ['label' => '計', 'field_number' => 37],
            'family_employee_salaries' => ['label' => '専従者給与', 'field_number' => 38],
            'bad_debt_reserve_provision' => ['label' => '貸倒引当金(繰入額等)', 'field_number' => 39],
            'reserve_provision_1' => ['label' => '繰入額等1', 'field_number' => 40],
            'reserve_provision_2' => ['label' => '繰入額等2', 'field_number' => 41],
            'total_reserve_provisions' => ['label' => '計', 'field_number' => 42],
            'income_before_blue_return_deduction' => ['label' => '青色申告特別控除前の所得金額', 'field_number' => 43],
            'blue_return_deduction' => ['label' => '青色申告特別控除額', 'field_number' => 44],
            'business_income' => ['label' => '所得金額', 'field_number' => 45],
        ];
    }
}
