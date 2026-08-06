<?php

return [
    'heading' => ':previous_year年のデータを:current_year年の初期データとして読み込みますか？',
    'description' => ':previous_year年の貸借対照表残高と所得をもとに、:current_year年の期首仕訳を作成します。',
    'note' => '既に登録済みの売上・経費・仕入には影響しません。',
    'start_button' => ':previous_year年のデータを:current_year年の初期データとして読み込む',
    'confirm_heading' => 'この内容で初期データを作成します。良いですか？',
    'confirm_description' => '以下の Transaction（予定）を :current_year年の期首仕訳として登録します。',
    'confirm_button' => 'この内容で読み込む',
    'cancel_button' => 'キャンセル',
    'loading' => '読み込んでいます...',
    'go_to_fiscal_years' => '年度管理で締め作業をする',
    'not_closed' => ':year年のデータが完了になっていないので初期データを作成できません。:year年のデータを締めてください。',
    'already_loaded' => 'この年度の初期データはすでに読み込み済みです。',
    'completed' => ':previous_year年のデータを:current_year年の初期データとして読み込みました。',
    'table' => [
        'account' => '勘定科目',
        'sub_account' => '補助科目',
        'type' => '区分',
        'amount' => '金額',
        'debit' => '借方',
        'credit' => '貸方',
        'debit_total' => '借方合計',
        'credit_total' => '貸方合計',
    ],
];
