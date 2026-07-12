<?php

// 2ページ(FA3025・令和二年分以降用)の骨格定義。
// digit_cells の座標は geometry/page2.json の抽出値、box / text の座標は罫線グリッド(geometry)からの算出値(校正で微調整する)。
// 令和五年分(From2023)との違い: 給料賃金の内訳がこのページにあり(印字対象外)、専従者給与の内訳は5行、
// 地代家賃の内訳は3ページへ移動、貸倒引当金繰入額の計算(印字対象外)と「うち軽減税率対象」欄がある。

// 月別売上(収入)金額及び仕入金額(1〜12月は桁ガイドなしの box 型)。行グリッドは From2023 と同一
$monthlyRowYs = [
    1 => 111.9,
    2 => 129.4,
    3 => 146.9,
    4 => 164.3,
    5 => 181.8,
    6 => 199.2,
    7 => 216.7,
    8 => 234.2,
    9 => 251.6,
    10 => 269.1,
    11 => 286.5,
    12 => 304.0,
];

// 専従者給与の内訳(明細5行 + 計)。続柄欄(x 442.2〜463.1)は入力を持たないため印字しない
$familyEmployeeSalaryColumns = [
    'name' => ['type' => 'text', 'x0' => 362.0, 'x1' => 440.0, 'align' => 'L', 'size' => 8.0],
    'age' => ['type' => 'text', 'x0' => 464.5, 'x1' => 483.0, 'align' => 'C', 'size' => 8.0],
    'months' => ['type' => 'text', 'x0' => 485.5, 'x1' => 504.0, 'align' => 'C', 'size' => 8.0],
    'salary' => ['type' => 'box', 'x0' => 507.0, 'x1' => 557.0, 'align' => 'R', 'size' => 9.0],
    'bonus' => ['type' => 'box', 'x0' => 563.0, 'x1' => 613.0, 'align' => 'R', 'size' => 9.0],
    'total' => ['type' => 'box', 'x0' => 619.0, 'x1' => 676.0, 'align' => 'R', 'size' => 9.0],
    'withheld_tax' => ['type' => 'box', 'x0' => 682.0, 'x1' => 774.0, 'align' => 'R', 'size' => 9.0],
];

// 明細行の上端(1行目は行内上部に「歳・月・円」の単位表記があるため、値は行中央に寄せる)
$familyEmployeeSalaryRowTops = [
    1 => 284.1,
    2 => 301.6,
    3 => 319.1,
    4 => 336.5,
    5 => 354.0,
];

$fields = [
    // 令和年号(先頭マスは様式に「0」が印字済み。1桁の値が右詰めで2マス目に載る)
    'era_year' => [
        'amount' => [
            'type' => 'digit_cells',
            'top' => 32.72,
            'bottom' => 46.71,
            'cells' => [
                ['x0' => 100.67, 'x1' => 113.32],
                ['x0' => 114.64, 'x1' => 127.28],
            ],
        ],
    ],
    'filing_number' => [
        'amount' => [
            'type' => 'digit_cells',
            'top' => 58.92,
            'bottom' => 72.88,
            'cells' => [
                ['x0' => 667.14, 'x1' => 678.19],
                ['x0' => 681.10, 'x1' => 692.16],
                ['x0' => 695.07, 'x1' => 706.13],
                ['x0' => 709.04, 'x1' => 720.10],
                ['x0' => 723.01, 'x1' => 734.07],
                ['x0' => 736.98, 'x1' => 748.03],
                ['x0' => 750.94, 'x1' => 762.00],
                ['x0' => 764.91, 'x1' => 775.97],
            ],
        ],
    ],
    'name_kana' => [
        'text' => ['x0' => 200.0, 'x1' => 344.0, 'y' => 44.8, 'align' => 'L', 'size' => 6.0],
    ],
    'name' => [
        'text' => ['x0' => 200.0, 'x1' => 344.0, 'y' => 52.8, 'align' => 'L', 'size' => 9.0],
    ],

    'monthly_house_consumption' => [
        'amount' => [
            'type' => 'digit_cells',
            'top' => 320.82,
            'bottom' => 334.78,
            'cells' => [
                ['x0' => 94.45, 'x1' => 118.89],
                ['x0' => 122.09, 'x1' => 133.15],
                ['x0' => 136.06, 'x1' => 147.12],
                ['x0' => 150.03, 'x1' => 161.09],
                ['x0' => 164.00, 'x1' => 175.06],
                ['x0' => 177.97, 'x1' => 189.02],
                ['x0' => 191.93, 'x1' => 202.99],
                ['x0' => 205.90, 'x1' => 216.96],
            ],
        ],
    ],
    'monthly_misc_income' => [
        'amount' => [
            'type' => 'digit_cells',
            'top' => 338.28,
            'bottom' => 352.24,
            'cells' => [
                ['x0' => 94.45, 'x1' => 118.89],
                ['x0' => 122.09, 'x1' => 133.15],
                ['x0' => 136.06, 'x1' => 147.12],
                ['x0' => 150.03, 'x1' => 161.09],
                ['x0' => 164.00, 'x1' => 175.06],
                ['x0' => 177.97, 'x1' => 189.02],
                ['x0' => 191.93, 'x1' => 202.99],
                ['x0' => 205.90, 'x1' => 216.96],
            ],
        ],
    ],
    'monthly_sales_total' => [
        'amount' => [
            'type' => 'digit_cells',
            'top' => 364.47,
            'bottom' => 378.43,
            'cells' => [
                ['x0' => 94.45, 'x1' => 118.89],
                ['x0' => 122.09, 'x1' => 133.15],
                ['x0' => 136.06, 'x1' => 147.12],
                ['x0' => 150.03, 'x1' => 161.09],
                ['x0' => 164.00, 'x1' => 175.06],
                ['x0' => 177.97, 'x1' => 189.02],
                ['x0' => 191.93, 'x1' => 202.99],
                ['x0' => 205.90, 'x1' => 216.96],
            ],
        ],
    ],
    'monthly_purchases_total' => [
        'amount' => [
            'type' => 'digit_cells',
            'top' => 364.47,
            'bottom' => 378.43,
            'cells' => [
                ['x0' => 220.16, 'x1' => 245.19],
                ['x0' => 247.81, 'x1' => 258.86],
                ['x0' => 261.77, 'x1' => 272.83],
                ['x0' => 275.74, 'x1' => 286.80],
                ['x0' => 289.71, 'x1' => 300.77],
                ['x0' => 303.68, 'x1' => 314.74],
                ['x0' => 317.64, 'x1' => 328.70],
                ['x0' => 331.61, 'x1' => 342.67],
            ],
        ],
    ],

    // 専従者給与の内訳(計)。延べ従事月数と源泉徴収税額は桁ガイドあり
    'family_employee_salary_total_months' => [
        'amount' => [
            'type' => 'digit_cells',
            'top' => 373.20,
            'bottom' => 387.16,
            'cells' => [
                ['x0' => 478.57, 'x1' => 489.62],
                ['x0' => 492.54, 'x1' => 503.59],
            ],
        ],
    ],
    'family_employee_salary_total_salary' => [
        'amount' => ['type' => 'box', 'x0' => 507.0, 'x1' => 557.0, 'y' => 374.6, 'align' => 'R', 'size' => 9.0],
    ],
    'family_employee_salary_total_bonus' => [
        'amount' => ['type' => 'box', 'x0' => 563.0, 'x1' => 613.0, 'y' => 374.6, 'align' => 'R', 'size' => 9.0],
    ],
    'family_employee_salary_total_amount' => [
        'amount' => ['type' => 'box', 'x0' => 619.0, 'x1' => 676.0, 'y' => 374.6, 'align' => 'R', 'size' => 9.0],
    ],
    'family_employee_salary_total_withheld_tax' => [
        'amount' => [
            'type' => 'digit_cells',
            'top' => 373.20,
            'bottom' => 387.16,
            'cells' => [
                ['x0' => 681.39, 'x1' => 705.84],
                ['x0' => 708.75, 'x1' => 719.81],
                ['x0' => 722.72, 'x1' => 733.77],
                ['x0' => 736.68, 'x1' => 747.74],
                ['x0' => 750.65, 'x1' => 761.71],
                ['x0' => 764.62, 'x1' => 775.68],
            ],
        ],
    ],

    // 青色申告特別控除額の計算 ⑦(1ページ㊸と同じ金額)。欄内上部に「(赤字のときは0)」の印字があるため下寄せ
    'income_before_blue_return_deduction' => [
        'amount' => ['type' => 'box', 'x0' => 683.0, 'x1' => 774.0, 'y' => 463.4, 'align' => 'R', 'size' => 10.0],
    ],
];

foreach ($monthlyRowYs as $monthNumber => $rowY) {
    $fields["monthly_sales_{$monthNumber}"] = [
        'amount' => ['type' => 'box', 'x0' => 87.3, 'x1' => 209.8, 'y' => $rowY, 'align' => 'R'],
    ];
    $fields["monthly_purchases_{$monthNumber}"] = [
        'amount' => ['type' => 'box', 'x0' => 214.8, 'x1' => 335.8, 'y' => $rowY, 'align' => 'R'],
    ];
}

// 専従者給与の明細(様式は5行。FieldFormatter の入力上限が4行のため5行目は現状未使用)
foreach ($familyEmployeeSalaryRowTops as $rowNumber => $rowTop) {
    foreach ($familyEmployeeSalaryColumns as $columnKey => $column) {
        $fieldKey = "family_employee_salary_{$rowNumber}_{$columnKey}";

        if ($column['type'] === 'box') {
            $fields[$fieldKey] = [
                'amount' => ['type' => 'box', 'x0' => $column['x0'], 'x1' => $column['x1'], 'y' => $rowTop + 3.2, 'align' => $column['align'], 'size' => $column['size']],
            ];

            continue;
        }

        $fields[$fieldKey] = [
            'text' => ['x0' => $column['x0'], 'x1' => $column['x1'], 'y' => $rowTop + 2.7, 'align' => $column['align'], 'size' => $column['size']],
        ];
    }
}

return [
    'page' => 2,
    'fields' => $fields,
];
