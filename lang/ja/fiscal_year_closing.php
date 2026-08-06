<?php

return [
    'heading' => ':year年度 期末処理',
    'description' => '期末に必要な決算整理と年度締めを行います。',

    'inventory' => [
        'section_title' => '棚卸',
        'explanation' => '年度初めに :opening 円分の在庫があり、今年は :purchases 円の仕入を行いました。:year年末時点での在庫の残りの金額を入力してください。',
        'already_registered' => '登録済みです。修正する場合は棚卸の決算整理仕訳を取り消してから再登録してください。',
        'no_sub_accounts' => '棚卸資産の補助科目がありません。',
        'closing_amount_placeholder' => '0',
        'yen_unit' => '円',
        'register_button' => '棚卸の決算整理仕訳を登録',
        'registering' => '登録しています...',
        'success' => '棚卸の決算整理仕訳を登録しました。',
        'noop' => '期首・期末とも在庫がないため、登録は不要でした。',
    ],

    'depreciation' => [
        'section_title' => '減価償却',
        'no_assets' => '今年度に償却する固定資産はありません。',
        'explanation' => ':amount 円のうち、何パーセントを経費として計上しますか？',
        'percent_unit' => '%',
        'post_button' => 'この内容で計上',
        'posting' => '計上しています...',
        'already_posted' => '計上済みです（経費計上額 :amount 円）。',
        'success' => ':name の減価償却費を計上しました。',
        'invalid_percent' => '割合は 0 〜 100 の数値で入力してください。',
    ],

    'planned' => [
        'section_title' => '予定取引',
        'description' => '年度内に予定として登録した取引です。実際に発生したものは「確定」、発生しなかったものは「削除」を選んでください。',
        'no_items' => '未処理の予定取引はありません。',
        'confirm_button' => '確定',
        'confirming' => '確定しています...',
        'cancel_button' => '削除',
        'canceling' => '削除しています...',
        'confirm_success' => ':description を確定しました。',
        'cancel_success' => ':description を削除しました。',
    ],
];
