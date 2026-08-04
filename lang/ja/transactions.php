<?php

return [
    'revenue_form' => [
        'title' => '売上の入力',
        'sections' => [
            'receipt_method' => '入金先',
        ],
        'fields' => [
            'date' => '日付',
            'amount' => '金額（税込）',
            'tax_option' => '消費税',
            'note' => '何の売上か',
            'counterparty_name' => '取引先',
            'withholding' => '源泉徴収あり',
            'withholding_amount' => '源泉徴収額',
        ],
        'placeholders' => [
            'date' => 'MMDD',
            'note' => '◯◯サービス利用料 / 商品△△ の販売 など',
            'counterparty_name' => '株式会社△△ / ◯◯さん',
        ],
        'actions' => [
            'submit' => '登録する',
        ],
        'tax_options' => [
            '10' => '10%',
            '8' => '8%',
            'exempt' => '非課税',
        ],
        'messages' => [
            'registered' => '売上を登録しました',
        ],
        'errors' => [
            'registration_failed' => '売上の登録に失敗しました',
            'invalid_date' => '日付が正しくありません。MMDD形式で入力してください。',
        ],
    ],

    'expense_form' => [
        'title' => '経費の入力',
        'sections' => [
            'expense_type' => '経費の種類',
            'unclassified' => '未設定',
            'payment_method' => '支払方法',
        ],
        'fields' => [
            'date' => '日付',
            'amount' => '金額（税込）',
            'tax_option' => '消費税',
            'note' => '何に使ったか',
            'counterparty_name' => '支払い先',
        ],
        'placeholders' => [
            'date' => 'MMDD',
            'note' => '◯◯さんと打ち合わせ / ノートの購入 など',
            'counterparty_name' => '喫茶△△ / □□ 文房具店',
        ],
        'actions' => [
            'show_more' => 'もっと見る',
            'show_less' => '折りたたむ',
            'add_account' => '勘定科目を追加する',
            'submit' => '登録する',
        ],
        'tax_options' => [
            '10' => '10%',
            '8' => '8%',
            'exempt' => '非課税',
        ],
        'messages' => [
            'registered' => '経費を登録しました',
        ],
        'errors' => [
            'registration_failed' => '経費の登録に失敗しました',
            'invalid_date' => '日付が正しくありません。MMDD形式で入力してください。',
        ],
    ],
];
