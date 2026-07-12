<?php

// 3ページ(FA3050・令和二年分以降用)の骨格定義。
// digit_cells の座標は geometry/page3.json の抽出値、box / text の座標は罫線グリッド(geometry)からの算出値(校正で微調整する)。
// 令和五年分(From2023)との違い: 売上(収入)金額・仕入金額の明細(インボイス対応欄)がなく、
// 減価償却費の計算が明細11行(From2023 は7行)、地代家賃の内訳がこのページにある。
// 利子割引料・税理士等報酬の内訳と本年中における特殊事情は印字対象外。

// 減価償却費の計算は明細11行+計。列のx座標は全行共通なので $depreciationColumns にまとめ、行のyだけ $depreciationRowYs で変える。
$depreciationColumns = [
    'asset_name' => ['type' => 'text', 'x0' => 67.0, 'x1' => 119.0, 'align' => 'L', 'size' => 7.0],
    'quantity' => ['type' => 'text', 'x0' => 122.0, 'x1' => 147.0, 'align' => 'C', 'size' => 7.0],
    'acquisition_year_month' => ['type' => 'text', 'x0' => 150.0, 'x1' => 175.0, 'align' => 'C', 'size' => 7.0],
    'base_amount' => ['type' => 'box', 'x0' => 242.0, 'x1' => 299.5, 'align' => 'R', 'size' => 8.0],
    'method' => ['type' => 'text', 'x0' => 305.0, 'x1' => 328.0, 'align' => 'L', 'size' => 7.0],
    'useful_life' => ['type' => 'text', 'x0' => 332.0, 'x1' => 356.5, 'align' => 'C', 'size' => 7.0],
    'depreciation_rate' => ['type' => 'text', 'x0' => 360.0, 'x1' => 384.0, 'align' => 'C', 'size' => 7.0],
    // 償却期間は「n/12」の分子側に載せるため、行の基準yより4.0pt上に印字する
    'months' => ['type' => 'text', 'x0' => 388.0, 'x1' => 412.0, 'align' => 'C', 'size' => 7.0, 'y_offset' => -4.0],
    'ordinary_amount' => ['type' => 'box', 'x0' => 416.5, 'x1' => 467.0, 'align' => 'R', 'size' => 8.0],
    'total_amount' => ['type' => 'box', 'x0' => 528.0, 'x1' => 579.0, 'align' => 'R', 'size' => 8.0],
    'business_usage_ratio' => ['type' => 'text', 'x0' => 584.0, 'x1' => 607.0, 'align' => 'C', 'size' => 7.0],
    'deductible_amount' => ['type' => 'box', 'x0' => 612.0, 'x1' => 663.0, 'align' => 'R', 'size' => 8.0],
    'ending_undepreciated_balance' => ['type' => 'box', 'x0' => 668.0, 'x1' => 719.0, 'align' => 'R', 'size' => 8.0],
];

// 1行目 110.1pt、行ピッチ 20.1pt(行の上端 105.2pt + 4.9pt)
$depreciationRowYs = [
    1 => 110.1,
    2 => 130.2,
    3 => 150.2,
    4 => 170.3,
    5 => 190.4,
    6 => 210.5,
    7 => 230.6,
    8 => 250.6,
    9 => 270.7,
    10 => 290.8,
    11 => 310.9,
];

$fields = [
    // 整理番号(桁マスの座標は geometry/page3.json の抽出値)
    'filing_number' => [
        'amount' => [
            'type' => 'digit_cells',
            'top' => 30.10,
            'bottom' => 44.07,
            'cells' => [
                ['x0' => 576.35, 'x1' => 587.41],
                ['x0' => 590.32, 'x1' => 601.38],
                ['x0' => 604.29, 'x1' => 615.34],
                ['x0' => 618.25, 'x1' => 629.31],
                ['x0' => 632.23, 'x1' => 643.28],
                ['x0' => 646.20, 'x1' => 657.25],
                ['x0' => 660.16, 'x1' => 671.22],
                ['x0' => 674.13, 'x1' => 685.19],
            ],
        ],
    ],

    // 地代家賃の内訳(明細2行。行は 476.2〜511.1 / 511.1〜546.0。賃借料は点線下段の「賃」の段に印字する)
    'rent_expense_1_address' => [
        'text' => ['x0' => 68.0, 'x1' => 222.0, 'y' => 479.7, 'align' => 'L', 'size' => 8.0],
    ],
    'rent_expense_1_name' => [
        'text' => ['x0' => 68.0, 'x1' => 222.0, 'y' => 494.7, 'align' => 'L', 'size' => 8.0],
    ],
    'rent_expense_1_rent_amount' => [
        'amount' => ['type' => 'box', 'x0' => 291.0, 'x1' => 348.0, 'y' => 497.4, 'align' => 'R', 'size' => 8.0],
    ],
    'rent_expense_1_deductible_amount' => [
        'amount' => ['type' => 'box', 'x0' => 354.0, 'x1' => 411.0, 'y' => 488.2, 'align' => 'R', 'size' => 9.0],
    ],
    'rent_expense_2_address' => [
        'text' => ['x0' => 68.0, 'x1' => 222.0, 'y' => 514.6, 'align' => 'L', 'size' => 8.0],
    ],
    'rent_expense_2_name' => [
        'text' => ['x0' => 68.0, 'x1' => 222.0, 'y' => 529.6, 'align' => 'L', 'size' => 8.0],
    ],
    'rent_expense_2_rent_amount' => [
        'amount' => ['type' => 'box', 'x0' => 291.0, 'x1' => 348.0, 'y' => 532.3, 'align' => 'R', 'size' => 8.0],
    ],
    'rent_expense_2_deductible_amount' => [
        'amount' => ['type' => 'box', 'x0' => 354.0, 'x1' => 411.0, 'y' => 523.1, 'align' => 'R', 'size' => 9.0],
    ],
];

// 減価償却費の計算(明細11行)
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

// 減価償却費の計算(計: 普通償却費・償却費合計・必要経費算入額のみ)。計の行は 326.1〜343.5pt
$depreciationTotalRowY = 329.8;

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
