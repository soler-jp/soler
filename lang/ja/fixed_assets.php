<?php

return [
    'panel' => [
        'title' => '固定資産',
        'sections' => [
            'list' => '固定資産一覧',
            'new' => '新規登録',
        ],
        'notes' => [
            'straight_line_only' => '※ 定額法しかサポートしていません',
        ],
        'empty' => 'まだ固定資産は登録されていません。',
        'category_advanced' => 'その他',
    ],

    'list' => [
        'columns' => [
            'name' => '名称',
            'category' => '区分',
            'acquisition_date' => '取得日',
            'acquisition_cost' => '取得価額',
            'useful_life' => '耐用月数',
            'status' => '状態',
        ],
        'status' => [
            'disposed' => '除却済',
            'in_use' => '使用中',
        ],
    ],

    'units' => [
        'yen' => '円',
        'months' => 'ヶ月',
    ],

    'fields' => [
        'name' => '名称',
        'acquisition_date' => '取得日',
        'first_registration_date' => '初年度登録日',
        'gross_amount' => '税込価格',
        'tax_amount' => '消費税額',
        'taxable_amount' => '税抜価格',
        'useful_life' => '耐用月数',
        'asset_account' => '計上先',
        'payment_account' => '支払元',
        'description' => '取得仕訳の摘要',
    ],

    'placeholders' => [
        'description' => '省略時は「名称 を取得」',
    ],

    'actions' => [
        'confirm' => '入力内容を確認',
        'submit' => 'この内容で登録する',
        'back' => '戻って修正',
    ],

    'confirm' => [
        'heading' => 'この内容で登録します',
        'past_acquisition_badge' => '取得日が期首より前 (過年度取得として登録)',
        'labels' => [
            'category' => 'カテゴリ',
            'name' => '名称',
            'acquisition_date' => '取得日',
            'first_registration_date' => '初年度登録日',
            'gross_amount' => '税込価格',
            'tax_amount' => '消費税額',
            'taxable_amount' => '税抜価格',
            'payment' => '支払元',
            'asset_account' => '計上先',
            'useful_life' => '耐用月数',
            'description' => '摘要',
        ],
    ],

    'messages' => [
        'registered' => '固定資産を登録しました。',
        'registration_failed' => '固定資産の登録に失敗しました',
    ],

    'auto_description' => ':name を取得',
];
