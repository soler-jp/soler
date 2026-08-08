<?php

return [
    'title' => '定期収入を登録',
    'fields' => [
        'counterparty_id' => '相手先',
        'name' => '名称',
        'interval' => '売上の頻度',
        'day_of_month' => '入金予定日',
        'month_of_year' => '入金予定月',
        'debit_sub_account_id' => '予定入金先',
        'gross_amount' => '金額(予定)',
        'tax_option' => '税区分',
        'is_withholding' => '源泉徴収あり',
        'withholding_tax_amount' => '源泉徴収額(予定)',
    ],
    'placeholders' => [
        'counterparty_id' => '相手先を選択してください',
        'name' => '例)講師代, HPメンテナンス代など',
    ],
    'options' => [
        'interval' => [
            'monthly' => '毎月',
            'yearly' => '毎年',
        ],
        'tax_option' => [
            '10' => '10%',
            '8' => '8%',
        ],
    ],
    'help' => [
        'counterparty_id' => '新しい相手先の登録は別画面で先に行ってください。',
    ],
    'errors' => [
        'month_of_year_required' => '毎年を選んだ場合は入金予定月を入力してください。',
        'withholding_required' => '源泉徴収額を入力してください。',
        'withholding_less_than_gross' => '源泉徴収額は税込金額より小さくしてください。',
    ],
    'validation' => [
        'required' => ':attributeを入力してください。',
        'integer' => ':attributeは数字で入力してください。',
        'amount_min' => '金額(予定)は1円以上で入力してください。',
        'month_range' => '入金予定月は1から12の範囲で入力してください。',
        'day_range' => '入金予定日は1から31の範囲で入力してください。',
        'withholding_min' => '源泉徴収額(予定)は0円以上で入力してください。',
        'counterparty_required' => '相手先を選択してください。',
        'counterparty_invalid' => '選択した相手先が不正です。',
        'tax_option_invalid' => '税区分を選択してください。',
    ],
    'actions' => [
        'review' => '登録内容を確認する',
        'back' => '戻る',
        'submit' => '定期収入を登録する',
    ],
    'messages' => [
        'created' => '定期収入を登録しました。',
    ],
    'confirm' => [
        'title' => 'この内容で登録します',
        'no_counterparty' => '未選択',
        'schedule_monthly' => '毎月 1日',
        'schedule_yearly' => '毎年 :month/:day',
    ],
];
