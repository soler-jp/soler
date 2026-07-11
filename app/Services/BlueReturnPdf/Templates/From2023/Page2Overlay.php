<?php

// 2ページ(FA3026)の骨格定義。
// digit_cells の座標は geometry/page2.json の抽出値、box / text の座標は暫定値(後で正式な座標に差し替える)。

return [
    'page' => 2,
    'fields' => [
        // 令和年号(先頭マスは様式に「0」が印字済み。1桁の値が右詰めで2マス目に載る)
        'era_year' => [
            'amount' => [
                'type' => 'digit_cells',
                'top' => 32.71,
                'bottom' => 47.08,
                'cells' => [
                    ['x0' => 100.6, 'x1' => 113.63],
                    ['x0' => 114.57, 'x1' => 127.6],
                ],
            ],
        ],
        'filing_number' => [
            'amount' => [
                'type' => 'digit_cells',
                'top' => 30.1,
                'bottom' => 44.46,
                'cells' => [
                    ['x0' => 576.27, 'x1' => 587.71],
                    ['x0' => 590.24, 'x1' => 601.68],
                    ['x0' => 604.2, 'x1' => 615.65],
                    ['x0' => 618.17, 'x1' => 629.62],
                    ['x0' => 632.14, 'x1' => 643.59],
                    ['x0' => 646.11, 'x1' => 657.55],
                    ['x0' => 660.08, 'x1' => 671.52],
                    ['x0' => 674.04, 'x1' => 685.49],
                ],
            ],
        ],
        'name_kana' => [
            'text' => ['x0' => 200.2, 'x1' => 359.2, 'y' => 44.8, 'align' => 'L', 'size' => 6.0],
        ],
        'name' => [
            'text' => ['x0' => 200.2, 'x1' => 359.2, 'y' => 52.8, 'align' => 'L', 'size' => 9.0],
        ],

        // 月別売上(収入)金額及び仕入金額(1〜12月は桁ガイドなしの box 型)
        'monthly_sales_1' => [
            'amount' => ['type' => 'box', 'x0' => 87.3, 'x1' => 209.8, 'y' => 111.9, 'align' => 'R'],
        ],
        'monthly_sales_2' => [
            'amount' => ['type' => 'box', 'x0' => 87.3, 'x1' => 209.8, 'y' => 129.4, 'align' => 'R'],
        ],
        'monthly_sales_3' => [
            'amount' => ['type' => 'box', 'x0' => 87.3, 'x1' => 209.8, 'y' => 146.9, 'align' => 'R'],
        ],
        'monthly_sales_4' => [
            'amount' => ['type' => 'box', 'x0' => 87.3, 'x1' => 209.8, 'y' => 164.3, 'align' => 'R'],
        ],
        'monthly_sales_5' => [
            'amount' => ['type' => 'box', 'x0' => 87.3, 'x1' => 209.8, 'y' => 181.8, 'align' => 'R'],
        ],
        'monthly_sales_6' => [
            'amount' => ['type' => 'box', 'x0' => 87.3, 'x1' => 209.8, 'y' => 199.2, 'align' => 'R'],
        ],
        'monthly_sales_7' => [
            'amount' => ['type' => 'box', 'x0' => 87.3, 'x1' => 209.8, 'y' => 216.7, 'align' => 'R'],
        ],
        'monthly_sales_8' => [
            'amount' => ['type' => 'box', 'x0' => 87.3, 'x1' => 209.8, 'y' => 234.2, 'align' => 'R'],
        ],
        'monthly_sales_9' => [
            'amount' => ['type' => 'box', 'x0' => 87.3, 'x1' => 209.8, 'y' => 251.6, 'align' => 'R'],
        ],
        'monthly_sales_10' => [
            'amount' => ['type' => 'box', 'x0' => 87.3, 'x1' => 209.8, 'y' => 269.1, 'align' => 'R'],
        ],
        'monthly_sales_11' => [
            'amount' => ['type' => 'box', 'x0' => 87.3, 'x1' => 209.8, 'y' => 286.5, 'align' => 'R'],
        ],
        'monthly_sales_12' => [
            'amount' => ['type' => 'box', 'x0' => 87.3, 'x1' => 209.8, 'y' => 304.0, 'align' => 'R'],
        ],
        'monthly_purchases_1' => [
            'amount' => ['type' => 'box', 'x0' => 214.8, 'x1' => 335.8, 'y' => 111.9, 'align' => 'R'],
        ],
        'monthly_purchases_2' => [
            'amount' => ['type' => 'box', 'x0' => 214.8, 'x1' => 335.8, 'y' => 129.4, 'align' => 'R'],
        ],
        'monthly_purchases_3' => [
            'amount' => ['type' => 'box', 'x0' => 214.8, 'x1' => 335.8, 'y' => 146.9, 'align' => 'R'],
        ],
        'monthly_purchases_4' => [
            'amount' => ['type' => 'box', 'x0' => 214.8, 'x1' => 335.8, 'y' => 164.3, 'align' => 'R'],
        ],
        'monthly_purchases_5' => [
            'amount' => ['type' => 'box', 'x0' => 214.8, 'x1' => 335.8, 'y' => 181.8, 'align' => 'R'],
        ],
        'monthly_purchases_6' => [
            'amount' => ['type' => 'box', 'x0' => 214.8, 'x1' => 335.8, 'y' => 199.2, 'align' => 'R'],
        ],
        'monthly_purchases_7' => [
            'amount' => ['type' => 'box', 'x0' => 214.8, 'x1' => 335.8, 'y' => 216.7, 'align' => 'R'],
        ],
        'monthly_purchases_8' => [
            'amount' => ['type' => 'box', 'x0' => 214.8, 'x1' => 335.8, 'y' => 234.2, 'align' => 'R'],
        ],
        'monthly_purchases_9' => [
            'amount' => ['type' => 'box', 'x0' => 214.8, 'x1' => 335.8, 'y' => 251.6, 'align' => 'R'],
        ],
        'monthly_purchases_10' => [
            'amount' => ['type' => 'box', 'x0' => 214.8, 'x1' => 335.8, 'y' => 269.1, 'align' => 'R'],
        ],
        'monthly_purchases_11' => [
            'amount' => ['type' => 'box', 'x0' => 214.8, 'x1' => 335.8, 'y' => 286.5, 'align' => 'R'],
        ],
        'monthly_purchases_12' => [
            'amount' => ['type' => 'box', 'x0' => 214.8, 'x1' => 335.8, 'y' => 304.0, 'align' => 'R'],
        ],
        'monthly_house_consumption' => [
            'amount' => [
                'type' => 'digit_cells',
                'top' => 320.81,
                'bottom' => 335.16,
                'cells' => [
                    ['x0' => 94.37, 'x1' => 119.2],
                    ['x0' => 122.02, 'x1' => 133.46],
                    ['x0' => 135.99, 'x1' => 147.43],
                    ['x0' => 149.95, 'x1' => 161.4],
                    ['x0' => 163.92, 'x1' => 175.37],
                    ['x0' => 177.89, 'x1' => 189.34],
                    ['x0' => 191.86, 'x1' => 203.3],
                    ['x0' => 205.83, 'x1' => 217.27],
                ],
            ],
        ],
        'monthly_misc_income' => [
            'amount' => [
                'type' => 'digit_cells',
                'top' => 338.27,
                'bottom' => 352.62,
                'cells' => [
                    ['x0' => 94.37, 'x1' => 119.2],
                    ['x0' => 122.02, 'x1' => 133.46],
                    ['x0' => 135.99, 'x1' => 147.43],
                    ['x0' => 149.95, 'x1' => 161.4],
                    ['x0' => 163.92, 'x1' => 175.37],
                    ['x0' => 177.89, 'x1' => 189.34],
                    ['x0' => 191.86, 'x1' => 203.3],
                    ['x0' => 205.83, 'x1' => 217.27],
                ],
            ],
        ],
        'monthly_sales_total' => [
            'amount' => [
                'type' => 'digit_cells',
                'top' => 364.46,
                'bottom' => 378.81,
                'cells' => [
                    ['x0' => 94.37, 'x1' => 119.2],
                    ['x0' => 122.02, 'x1' => 133.46],
                    ['x0' => 135.99, 'x1' => 147.43],
                    ['x0' => 149.95, 'x1' => 161.4],
                    ['x0' => 163.92, 'x1' => 175.37],
                    ['x0' => 177.89, 'x1' => 189.34],
                    ['x0' => 191.86, 'x1' => 203.3],
                    ['x0' => 205.83, 'x1' => 217.27],
                ],
            ],
        ],
        'monthly_purchases_total' => [
            'amount' => [
                'type' => 'digit_cells',
                'top' => 364.46,
                'bottom' => 378.81,
                'cells' => [
                    ['x0' => 220.08, 'x1' => 245.5],
                    ['x0' => 247.73, 'x1' => 259.18],
                    ['x0' => 261.7, 'x1' => 273.14],
                    ['x0' => 275.66, 'x1' => 287.11],
                    ['x0' => 289.63, 'x1' => 301.08],
                    ['x0' => 303.6, 'x1' => 315.05],
                    ['x0' => 317.57, 'x1' => 329.01],
                    ['x0' => 331.54, 'x1' => 342.98],
                ],
            ],
        ],

        // 専従者給与の内訳(明細4行 + 計)
        'family_employee_salary_1_name' => [
            'text' => ['x0' => 360.0, 'x1' => 434.0, 'y' => 227.7, 'align' => 'L', 'size' => 8.0],
        ],
        'family_employee_salary_1_age' => [
            'text' => ['x0' => 458.3, 'x1' => 480.8, 'y' => 227.7, 'align' => 'C', 'size' => 8.0],
        ],
        'family_employee_salary_1_months' => [
            'text' => ['x0' => 480.2, 'x1' => 501.2, 'y' => 227.7, 'align' => 'C', 'size' => 8.0],
        ],
        'family_employee_salary_1_salary' => [
            'amount' => ['type' => 'box', 'x0' => 505.0, 'x1' => 554.0, 'y' => 226.1, 'align' => 'R', 'size' => 9.0],
        ],
        'family_employee_salary_1_bonus' => [
            'amount' => ['type' => 'box', 'x0' => 560.3, 'x1' => 609.3, 'y' => 226.1, 'align' => 'R', 'size' => 9.0],
        ],
        'family_employee_salary_1_total' => [
            'amount' => ['type' => 'box', 'x0' => 612.8, 'x1' => 671.3, 'y' => 226.1, 'align' => 'R', 'size' => 9.0],
        ],
        'family_employee_salary_1_withheld_tax' => [
            'amount' => ['type' => 'box', 'x0' => 674.8, 'x1' => 770.3, 'y' => 226.1, 'align' => 'R', 'size' => 9.0],
        ],
        'family_employee_salary_2_name' => [
            'text' => ['x0' => 360.0, 'x1' => 434.0, 'y' => 245.2, 'align' => 'L', 'size' => 8.0],
        ],
        'family_employee_salary_2_age' => [
            'text' => ['x0' => 458.3, 'x1' => 480.8, 'y' => 245.2, 'align' => 'C', 'size' => 8.0],
        ],
        'family_employee_salary_2_months' => [
            'text' => ['x0' => 480.2, 'x1' => 501.2, 'y' => 245.2, 'align' => 'C', 'size' => 8.0],
        ],
        'family_employee_salary_2_salary' => [
            'amount' => ['type' => 'box', 'x0' => 505.0, 'x1' => 554.0, 'y' => 243.6, 'align' => 'R', 'size' => 9.0],
        ],
        'family_employee_salary_2_bonus' => [
            'amount' => ['type' => 'box', 'x0' => 560.3, 'x1' => 609.3, 'y' => 243.6, 'align' => 'R', 'size' => 9.0],
        ],
        'family_employee_salary_2_total' => [
            'amount' => ['type' => 'box', 'x0' => 612.8, 'x1' => 671.3, 'y' => 243.6, 'align' => 'R', 'size' => 9.0],
        ],
        'family_employee_salary_2_withheld_tax' => [
            'amount' => ['type' => 'box', 'x0' => 674.8, 'x1' => 770.3, 'y' => 243.6, 'align' => 'R', 'size' => 9.0],
        ],
        'family_employee_salary_3_name' => [
            'text' => ['x0' => 360.0, 'x1' => 434.0, 'y' => 262.6, 'align' => 'L', 'size' => 8.0],
        ],
        'family_employee_salary_3_age' => [
            'text' => ['x0' => 458.3, 'x1' => 480.8, 'y' => 262.6, 'align' => 'C', 'size' => 8.0],
        ],
        'family_employee_salary_3_months' => [
            'text' => ['x0' => 480.2, 'x1' => 501.2, 'y' => 262.6, 'align' => 'C', 'size' => 8.0],
        ],
        'family_employee_salary_3_salary' => [
            'amount' => ['type' => 'box', 'x0' => 505.0, 'x1' => 554.0, 'y' => 261.0, 'align' => 'R', 'size' => 9.0],
        ],
        'family_employee_salary_3_bonus' => [
            'amount' => ['type' => 'box', 'x0' => 560.3, 'x1' => 609.3, 'y' => 261.0, 'align' => 'R', 'size' => 9.0],
        ],
        'family_employee_salary_3_total' => [
            'amount' => ['type' => 'box', 'x0' => 612.8, 'x1' => 671.3, 'y' => 261.0, 'align' => 'R', 'size' => 9.0],
        ],
        'family_employee_salary_3_withheld_tax' => [
            'amount' => ['type' => 'box', 'x0' => 674.8, 'x1' => 770.3, 'y' => 261.0, 'align' => 'R', 'size' => 9.0],
        ],
        'family_employee_salary_4_name' => [
            'text' => ['x0' => 360.0, 'x1' => 434.0, 'y' => 280.1, 'align' => 'L', 'size' => 8.0],
        ],
        'family_employee_salary_4_age' => [
            'text' => ['x0' => 458.3, 'x1' => 480.8, 'y' => 280.1, 'align' => 'C', 'size' => 8.0],
        ],
        'family_employee_salary_4_months' => [
            'text' => ['x0' => 480.2, 'x1' => 501.2, 'y' => 280.1, 'align' => 'C', 'size' => 8.0],
        ],
        'family_employee_salary_4_salary' => [
            'amount' => ['type' => 'box', 'x0' => 505.0, 'x1' => 554.0, 'y' => 278.5, 'align' => 'R', 'size' => 9.0],
        ],
        'family_employee_salary_4_bonus' => [
            'amount' => ['type' => 'box', 'x0' => 560.3, 'x1' => 609.3, 'y' => 278.5, 'align' => 'R', 'size' => 9.0],
        ],
        'family_employee_salary_4_total' => [
            'amount' => ['type' => 'box', 'x0' => 612.8, 'x1' => 671.3, 'y' => 278.5, 'align' => 'R', 'size' => 9.0],
        ],
        'family_employee_salary_4_withheld_tax' => [
            'amount' => ['type' => 'box', 'x0' => 674.8, 'x1' => 770.3, 'y' => 278.5, 'align' => 'R', 'size' => 9.0],
        ],
        'family_employee_salary_total_months' => [
            'amount' => [
                'type' => 'digit_cells',
                'top' => 295.28,
                'bottom' => 309.64,
                'cells' => [
                    ['x0' => 478.49, 'x1' => 489.94],
                    ['x0' => 492.46, 'x1' => 503.91],
                ],
            ],
        ],
        'family_employee_salary_total_salary' => [
            'amount' => ['type' => 'box', 'x0' => 505.0, 'x1' => 554.0, 'y' => 295.9, 'align' => 'R', 'size' => 9.0],
        ],
        'family_employee_salary_total_bonus' => [
            'amount' => ['type' => 'box', 'x0' => 560.3, 'x1' => 609.3, 'y' => 295.9, 'align' => 'R', 'size' => 9.0],
        ],
        'family_employee_salary_total_amount' => [
            'amount' => ['type' => 'box', 'x0' => 612.8, 'x1' => 671.3, 'y' => 295.9, 'align' => 'R', 'size' => 9.0],
        ],
        'family_employee_salary_total_withheld_tax' => [
            'amount' => [
                'type' => 'digit_cells',
                'top' => 295.28,
                'bottom' => 309.64,
                'cells' => [
                    ['x0' => 681.32, 'x1' => 706.15],
                    ['x0' => 708.67, 'x1' => 720.12],
                    ['x0' => 722.64, 'x1' => 734.09],
                    ['x0' => 736.61, 'x1' => 748.06],
                    ['x0' => 750.58, 'x1' => 762.02],
                    ['x0' => 764.54, 'x1' => 775.99],
                ],
            ],
        ],

        // 地代家賃の内訳(明細2行。賃借料は「賃」の段に印字する)
        'rent_expense_1_address' => [
            'text' => ['x0' => 360.0, 'x1' => 550.0, 'y' => 349.1, 'align' => 'L', 'size' => 8.0],
        ],
        'rent_expense_1_name' => [
            'text' => ['x0' => 360.0, 'x1' => 550.0, 'y' => 362.1, 'align' => 'L', 'size' => 8.0],
        ],
        'rent_expense_1_rent_amount' => [
            'amount' => ['type' => 'box', 'x0' => 649.0, 'x1' => 711.5, 'y' => 363.1, 'align' => 'R', 'size' => 8.0],
        ],
        'rent_expense_1_deductible_amount' => [
            'amount' => ['type' => 'box', 'x0' => 717.0, 'x1' => 775.0, 'y' => 355.6, 'align' => 'R', 'size' => 9.0],
        ],
        'rent_expense_2_address' => [
            'text' => ['x0' => 360.0, 'x1' => 550.0, 'y' => 379.3, 'align' => 'L', 'size' => 8.0],
        ],
        'rent_expense_2_name' => [
            'text' => ['x0' => 360.0, 'x1' => 550.0, 'y' => 392.3, 'align' => 'L', 'size' => 8.0],
        ],
        'rent_expense_2_rent_amount' => [
            'amount' => ['type' => 'box', 'x0' => 649.0, 'x1' => 711.5, 'y' => 393.3, 'align' => 'R', 'size' => 8.0],
        ],
        'rent_expense_2_deductible_amount' => [
            'amount' => ['type' => 'box', 'x0' => 717.0, 'x1' => 775.0, 'y' => 385.8, 'align' => 'R', 'size' => 9.0],
        ],

        // 青色申告特別控除額の計算 ⑦(1ページ㊸と同じ金額)
        'income_before_blue_return_deduction' => [
            'amount' => ['type' => 'box', 'x0' => 660.0, 'x1' => 774.0, 'y' => 463.3, 'align' => 'R', 'size' => 10.0],
        ],
    ],
];
