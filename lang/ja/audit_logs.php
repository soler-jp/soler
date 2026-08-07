<?php

return [
    'title' => '監査ログ',
    'description' => '現在表示中の会計年度に記録された監査ログを時系列で確認できます。',
    'showing_fiscal_year' => '表示対象年度',
    'record_count' => '件数',
    'per_page' => '表示件数',
    'empty' => 'この会計年度に記録された監査ログはまだありません。',
    'columns' => [
        'recorded_at' => '記録日時',
        'event' => 'イベント',
        'actor' => '操作ユーザー',
        'reason' => '理由',
        'detail' => '内容',
    ],
    'actor_system' => 'システム',
    'reason_none' => '理由なし',
    'transaction' => [
        'no_description' => '摘要なし',
        'fields' => [
            'voucher_number' => '伝票番号',
            'date' => '日付',
            'description' => '摘要',
            'amount' => '金額',
        ],
    ],
    'event_labels' => [
        'transaction.created' => '取引を登録',
        'transaction.deactivated' => '取引を無効化',
        'transaction.revised' => '取引を改訂',
        'fiscal_year.closed' => '年度を締める',
        'fiscal_year.rolled_over' => '翌年度へ繰り越す',
    ],
    'diff' => [
        'currency_suffix' => '円',
        'entry_sides' => [
            'debit' => '借方',
            'credit' => '貸方',
            'unknown' => '不明',
        ],
        'subject' => [
            'date' => '日付: :old -> :new',
            'description' => '摘要: :old -> :new',
            'remarks' => '備考: :old -> :new',
            'business_ratio' => '事業割合: :old -> :new',
        ],
        'derived' => [
            'total_amount' => '金額: :old -> :new',
        ],
        'entries' => [
            'updated' => ':side変更: :old_account :old_amount -> :new_account :new_amount',
            'account_changed' => ':side変更: :old_account -> :new_account',
            'created' => ':side追加: :account :amount',
            'deleted' => ':side削除: :account :amount',
        ],
    ],
];
