<?php

return [
    'shared' => [
        'submit_suffix' => 'を登録する',
        'invalid_amount_submit' => '金額が不正なので登録できません',
    ],

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
            'confirm' => '登録する',
            'back' => '修正する',
        ],
        'tax_options' => [
            '10' => '10%',
            '8' => '8%',
            'exempt' => '非課税',
        ],
        'confirm' => [
            'title' => '内容を確認してください',
            'amount_net_taxable' => '売上金額（税抜）',
            'amount_net_exempt' => '売上金額',
            'tax_10' => '消費税（10%）',
            'tax_8' => '消費税（8%）',
            'withholding' => '源泉徴収',
            'connector' => 'なので、',
            'settlements' => [
                'cash' => '差し引き :amount 円が :date に :receipt に入金された。',
                'bank' => '差し引き :amount 円が :date に :receipt に振り込まれた。',
                'owner_draw' => '差し引き :amount 円を :date に受け取って、個人の財布に入れた。',
                'accounts_receivable' => '差し引き :amount 円を、後日入金予定で計上する。',
                'default' => '差し引き :amount 円が :date に :receipt に入金された。',
            ],
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
        'picker' => [
            'help_aria' => '経費の種類の説明を見る',
            'title' => '経費の種類を選ぶ',
            'lead' => '具体例や注意事項を確認しながら選べます。',
            'columns' => [
                'name' => '勘定科目',
                'example' => '具体例',
            ],
            'no_description' => '（説明なし）',
            'caution_label' => '注意',
            'source' => '出典: 国税庁「帳簿の記帳のしかた（事業所得者用）」',
            'close' => '閉じる',
        ],
        'messages' => [
            'registered' => '経費を登録しました',
        ],
        'errors' => [
            'registration_failed' => '経費の登録に失敗しました',
            'invalid_date' => '日付が正しくありません。MMDD形式で入力してください。',
        ],
    ],

    'purchase_form' => [
        'title' => '仕入の入力',
        'sections' => [
            'payment_method' => '支払方法',
        ],
        'fields' => [
            'date' => '日付',
            'amount' => '金額（税込）',
            'tax_option' => '消費税',
            'note' => '何を購入したか',
            'counterparty_name' => '支払い先',
        ],
        'placeholders' => [
            'date' => 'MMDD',
            'note' => '食材の仕入れ / 商品△△の購入 など',
            'counterparty_name' => '株式会社△△ / ◯◯市場',
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
            'registered' => '仕入を登録しました',
        ],
        'errors' => [
            'registration_failed' => '仕入の登録に失敗しました',
            'invalid_date' => '日付が正しくありません。MMDD形式で入力してください。',
        ],
    ],

    'transfer_form' => [
        'title' => 'お金の移動',
        'fields' => [
            'date' => '日付',
            'amount' => '金額',
            'from_account' => 'どこから出したか',
            'to_account' => 'どこへ入れたか',
            'note' => 'メモ',
        ],
        'placeholders' => [
            'date' => 'MMDD',
            'note' => 'レジ用の現金補充 / 個人口座へ移動 など',
        ],
        'actions' => [
            'submit' => '登録する',
        ],
        'messages' => [
            'registered' => 'お金の移動を登録しました',
        ],
        'errors' => [
            'registration_failed' => 'お金の移動の登録に失敗しました',
            'invalid_date' => '日付が正しくありません。MMDD形式で入力してください。',
            'same_account' => '移動元と移動先には別の口座を選んでください。',
            'invalid_transfer_account' => '選択できない勘定科目です。',
        ],
    ],
];
