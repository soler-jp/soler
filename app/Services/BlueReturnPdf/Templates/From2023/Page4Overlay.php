<?php

// 4ページ(FA3076 貸借対照表)の骨格定義。
// 整理番号の桁マスは3ページと同寸で、位置だけ top=30pt・左端 x=575.3pt に平行移動している。
// 資産の部・負債・資本の部は 109.2pt〜545.5pt の高さに25行が等間隔で並ぶ。

// 資産の部の行キー(様式の上から下への並び順)
$assetRowKeys = [
    'cash',                     // 現金
    'checking_deposits',        // 当座預金
    'time_deposits',            // 定期預金
    'other_deposits',           // その他の預金
    'notes_receivable',         // 受取手形
    'accounts_receivable',      // 売掛金
    'securities',               // 有価証券
    'inventory',                // 棚卸資産
    'advance_payments',         // 前払金
    'loans_receivable',         // 貸付金
    'buildings',                // 建物
    'building_improvements',    // 建物付属設備
    'machinery',                // 機械装置
    'vehicles',                 // 車両運搬具
    'tools_and_equipment',      // 工具器具備品
    'land',                     // 土地
    'blank_1',
    'blank_2',
    'blank_3',
    'blank_4',
    'blank_5',
    'blank_6',
    'blank_7',
    'owner_drawings',           // 事業主貸
    'total',                    // 合計
];

// 負債・資本の部の行キー(様式の上から下への並び順)
$liabilityRowKeys = [
    'notes_payable',            // 支払手形
    'accounts_payable',         // 買掛金
    'borrowings',               // 借入金
    'accrued_payables',         // 未払金
    'advances_received',        // 前受金
    'deposits_received',        // 預り金
    'blank_1',
    'blank_2',
    'blank_3',
    'blank_4',
    'blank_5',
    'blank_6',
    'blank_7',
    'bad_debt_reserve',         // 貸倒引当金
    // 空欄8〜14(貸倒引当金より下)は様式上の行として定義だけ持つ。
    // 個人事業主で資本の科目を追加することはほぼないため、FieldFormatter からは割り当てない
    'blank_8',
    'blank_9',
    'blank_10',
    'blank_11',
    'blank_12',
    'blank_13',
    'blank_14',
    'owner_borrowings',         // 事業主借
    'capital',                  // 元入金
    'income_before_blue_return_deduction', // 青色申告特別控除前の所得金額
    'total',                    // 合計
];

$balanceRowAreaTop = 109.2;
$balanceRowAreaBottom = 545.5;
$balanceRowPitch = ($balanceRowAreaBottom - $balanceRowAreaTop) / count($assetRowKeys);
$balanceAmountFontSize = 9.0;

$balanceColumns = [
    'asset' => [
        'opening' => ['x0' => 149.0, 'x1' => 223.0],
        'ending' => ['x0' => 230.0, 'x1' => 310.0],
    ],
    'liability' => [
        'opening' => ['x0' => 400.0, 'x1' => 475.0],
        'ending' => ['x0' => 485.0, 'x1' => 558.0],
    ],
];

// 空欄行の科目名(ラベル)列。右端は期首列の手前まで
$balanceLabelFontSize = 8.0;
$balanceLabelColumns = [
    'asset' => ['x0' => 73.4, 'x1' => 145.0],
    'liability' => ['x0' => 327.8, 'x1' => 396.0],
];

$fields = [
    // 整理番号(3ページと同寸の桁マスを top=30pt・x=575.3pt 始まりへ平行移動した座標)
    'filing_number' => [
        'amount' => [
            'type' => 'digit_cells',
            'top' => 30.0,
            'bottom' => 43.97,
            'cells' => [
                ['x0' => 575.3, 'x1' => 586.36],
                ['x0' => 589.27, 'x1' => 600.33],
                ['x0' => 603.24, 'x1' => 614.3],
                ['x0' => 617.21, 'x1' => 628.27],
                ['x0' => 631.18, 'x1' => 642.23],
                ['x0' => 645.15, 'x1' => 656.2],
                ['x0' => 659.11, 'x1' => 670.17],
                ['x0' => 673.08, 'x1' => 684.14],
            ],
        ],
    ],

    // 期首(1月1日)・期末(12月31日)の日付。各列の見出しに月・日を左寄せで印字する
    'opening_month_asset' => [
        'text' => ['x0' => 160.0, 'x1' => 176.0, 'y' => 95.0, 'align' => 'L', 'size' => 9.0],
    ],
    'opening_day_asset' => [
        'text' => ['x0' => 180.0, 'x1' => 196.0, 'y' => 95.0, 'align' => 'L', 'size' => 9.0],
    ],
    'ending_month_asset' => [
        'text' => ['x0' => 242.0, 'x1' => 258.0, 'y' => 95.0, 'align' => 'L', 'size' => 9.0],
    ],
    'ending_day_asset' => [
        'text' => ['x0' => 267.2, 'x1' => 283.2, 'y' => 95.0, 'align' => 'L', 'size' => 9.0],
    ],
    'opening_month_liability' => [
        'text' => ['x0' => 409.0, 'x1' => 425.0, 'y' => 95.0, 'align' => 'L', 'size' => 9.0],
    ],
    'opening_day_liability' => [
        'text' => ['x0' => 437.0, 'x1' => 453.0, 'y' => 95.0, 'align' => 'L', 'size' => 9.0],
    ],
    'ending_month_liability' => [
        'text' => ['x0' => 494.0, 'x1' => 510.0, 'y' => 95.0, 'align' => 'L', 'size' => 9.0],
    ],
    'ending_day_liability' => [
        'text' => ['x0' => 518.6, 'x1' => 534.6, 'y' => 95.0, 'align' => 'L', 'size' => 9.0],
    ],
];

// 資産の部・負債・資本の部の各行(期首・期末の2列)
foreach (['asset' => $assetRowKeys, 'liability' => $liabilityRowKeys] as $section => $rowKeys) {
    foreach ($rowKeys as $index => $rowKey) {
        // box(高さ = フォントサイズ + 2pt)が行の垂直中央に重なるように y を決める
        $y = round($balanceRowAreaTop + $index * $balanceRowPitch + ($balanceRowPitch - ($balanceAmountFontSize + 2.0)) / 2, 2);

        foreach ($balanceColumns[$section] as $column => $range) {
            $fields["balance_{$section}_{$rowKey}_{$column}"] = [
                'amount' => ['type' => 'box', 'x0' => $range['x0'], 'x1' => $range['x1'], 'y' => $y, 'align' => 'R', 'size' => $balanceAmountFontSize],
            ];
        }

        // 空欄行は科目名(ラベル)も印字する。text はフォントサイズ×1.5 の行高で描画されるため、その高さで垂直中央に合わせる
        if (str_starts_with($rowKey, 'blank_')) {
            $labelY = round($balanceRowAreaTop + $index * $balanceRowPitch + ($balanceRowPitch - $balanceLabelFontSize * 1.5) / 2, 2);
            $labelColumn = $balanceLabelColumns[$section];

            $fields["balance_{$section}_{$rowKey}_label"] = [
                'text' => ['x0' => $labelColumn['x0'], 'x1' => $labelColumn['x1'], 'y' => $labelY, 'align' => 'L', 'size' => $balanceLabelFontSize],
            ];
        }
    }
}

return [
    'page' => 4,
    'fields' => $fields,
];
