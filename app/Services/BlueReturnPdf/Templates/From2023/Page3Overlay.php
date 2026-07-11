<?php

// 3ページ(FA3051)の骨格定義。
// 売上・仕入の座標は暫定値(後で正式な座標に差し替える)。
// 減価償却費の計算は明細7行+計。列のx座標は全行共通なので $depreciationColumns にまとめ、行のyだけ $depreciationRowYs で変える。

$depreciationColumns = [
    'asset_name' => ['type' => 'text', 'x0' => 62.0, 'x1' => 118.0, 'align' => 'L', 'size' => 7.0],
    'quantity' => ['type' => 'text', 'x0' => 120.0, 'x1' => 148.0, 'align' => 'C', 'size' => 7.0],
    'acquisition_year_month' => ['type' => 'text', 'x0' => 150.0, 'x1' => 178.0, 'align' => 'C', 'size' => 7.0],
    'base_amount' => ['type' => 'box', 'x0' => 229.0, 'x1' => 295.0, 'align' => 'R', 'size' => 8.0],
    'method' => ['type' => 'text', 'x0' => 315.0, 'x1' => 337.0, 'align' => 'L', 'size' => 7.0],
    'useful_life' => ['type' => 'text', 'x0' => 338.0, 'x1' => 366.0, 'align' => 'C', 'size' => 7.0],
    'depreciation_rate' => ['type' => 'text', 'x0' => 368.0, 'x1' => 393.0, 'align' => 'C', 'size' => 7.0],
    // 償却期間は「n/12」の分子側に載せるため、行の基準yより4.0pt上に印字する
    'months' => ['type' => 'text', 'x0' => 395.0, 'x1' => 424.0, 'align' => 'C', 'size' => 7.0, 'y_offset' => -4.0],
    'ordinary_amount' => ['type' => 'box', 'x0' => 416.4, 'x1' => 475.4, 'align' => 'R', 'size' => 8.0],
    'total_amount' => ['type' => 'box', 'x0' => 548.0, 'x1' => 599.0, 'align' => 'R', 'size' => 8.0],
    'business_usage_ratio' => ['type' => 'text', 'x0' => 601.0, 'x1' => 631.0, 'align' => 'C', 'size' => 7.0],
    'deductible_amount' => ['type' => 'box', 'x0' => 622.0, 'x1' => 680.0, 'align' => 'R', 'size' => 8.0],
    'ending_undepreciated_balance' => ['type' => 'box', 'x0' => 685.0, 'x1' => 740.0, 'align' => 'R', 'size' => 8.0],
];

// 1行目 343.6pt、行ピッチ 17.9pt
$depreciationRowYs = [
    1 => 343.6,
    2 => 361.5,
    3 => 379.4,
    4 => 397.3,
    5 => 415.2,
    6 => 433.1,
    7 => 451.0,
];

$fields = [
    // 整理番号(桁マスの座標は geometry/page3.json の抽出値)
    'filing_number' => [
        'amount' => [
            'type' => 'digit_cells',
            'top' => 54.7,
            'bottom' => 68.67,
            'cells' => [
                ['x0' => 699.98, 'x1' => 711.04],
                ['x0' => 713.95, 'x1' => 725.01],
                ['x0' => 727.92, 'x1' => 738.98],
                ['x0' => 741.89, 'x1' => 752.95],
                ['x0' => 755.86, 'x1' => 766.91],
                ['x0' => 769.83, 'x1' => 780.88],
                ['x0' => 783.79, 'x1' => 794.85],
                ['x0' => 797.76, 'x1' => 808.82],
            ],
        ],
    ],

    // 売上(収入)金額の明細: 「上記以外の売上先の計(雑収入を含む)」と「計」に同じ金額を印字する
    'sales_amount_other_total' => [
        'amount' => ['type' => 'box', 'x0' => 478.0, 'x1' => 588.0, 'y' => 130.0, 'align' => 'R', 'size' => 9.0],
    ],
    'sales_amount_total' => [
        'amount' => ['type' => 'box', 'x0' => 478.0, 'x1' => 588.0, 'y' => 149.0, 'align' => 'R', 'size' => 9.0],
    ],

    // 仕入金額の明細: 「上記以外の仕入先の計」と「計」に同じ金額を印字する
    'purchases_amount_other_total' => [
        'amount' => ['type' => 'box', 'x0' => 478.0, 'x1' => 588.0, 'y' => 258.0, 'align' => 'R', 'size' => 9.0],
    ],
    'purchases_amount_total' => [
        'amount' => ['type' => 'box', 'x0' => 478.0, 'x1' => 588.0, 'y' => 277.0, 'align' => 'R', 'size' => 9.0],
    ],
];

// 減価償却費の計算(明細7行)
foreach ($depreciationRowYs as $rowNumber => $rowY) {
    foreach ($depreciationColumns as $columnKey => $column) {
        $fieldKey = "depreciation_{$rowNumber}_{$columnKey}";
        $y = $rowY + ($column['y_offset'] ?? 0.0);

        if ($column['type'] === 'box') {
            $fields[$fieldKey] = [
                'amount' => ['type' => 'box', 'x0' => $column['x0'], 'x1' => $column['x1'], 'y' => $y, 'align' => $column['align'], 'size' => $column['size']],
            ];

            continue;
        }

        $fields[$fieldKey] = [
            'text' => ['x0' => $column['x0'], 'x1' => $column['x1'], 'y' => $y, 'align' => $column['align'], 'size' => $column['size']],
        ];
    }
}

// 減価償却費の計算(計: 普通償却費・償却費合計・必要経費算入額のみ)。明細8行目にあたる位置
$depreciationTotalRowY = 468.9;

foreach ([
    'depreciation_total_ordinary_amount' => $depreciationColumns['ordinary_amount'],
    'depreciation_total_amount' => $depreciationColumns['total_amount'],
    'depreciation_total_deductible_amount' => $depreciationColumns['deductible_amount'],
] as $fieldKey => $column) {
    $fields[$fieldKey] = [
        'amount' => ['type' => 'box', 'x0' => $column['x0'], 'x1' => $column['x1'], 'y' => $depreciationTotalRowY, 'align' => $column['align'], 'size' => $column['size']],
    ];
}

return [
    'page' => 3,
    'fields' => $fields,
];
