<?php

return [
    'title' => '定期収入の実現',
    'empty_plans' => '定期収入の計画はまだありません。',
    'empty_transactions' => 'この計画の未実現の予定取引はありません。',
    'no_counterparty' => '相手先未設定',
    'realized_default' => '実現済みです。',
    'scheduled_label' => ':date 予定 / :amount円',
    'notices' => [
        'realized' => '定期収入を登録しました。',
    ],
    'validation' => [
        'net_tax_invalid_rate' => '入力した税抜金額と消費税額は、8%/10% のどちらの明細とも一致しません。',
    ],
    'fields' => [
        'input_mode' => '入力方法',
        'amount' => '売上金額(税込)',
        'net_amount' => '消費税対象額',
        'tax_amount' => '消費税額',
        'tax_rate' => '消費税率',
        'withholding_tax_amount' => '源泉徴収額',
        'receipt_date' => '受取日・入金日',
        'receipt_sub_account' => '受け取り先',
    ],
    'options' => [
        'input_mode' => [
            'gross' => '税込',
            'net_tax' => '税抜+消費税',
            'gross_full' => '税込価格で入力',
            'net_tax_full' => '税抜+消費税で入力',
        ],
    ],
    'actions' => [
        'submit' => '実現する',
        'submitting' => '登録中...',
    ],
    'messages' => [
        'same_month' => ':date に :amount円 を :receipt で登録します',
        'cross_month_future' => ':date に :amount円 を :receipt で受け取る予定として登録します',
        'cross_month_past' => ':date に :amount円 を :receipt で受け取りました',
    ],
    'preview' => [
        'taxable_summary' => ':periodの:name :gross円のうち、消費税(:tax_rate)は:tax円です。',
        'taxable_net_tax_summary' => ':periodの:nameの代金は、:net円 + 消費税(:tax_rate) :tax円の、合計:gross円です。',
        'non_taxable_summary' => ':periodの:name :gross円です。',
        'withholding_summary' => '源泉徴収の:withholding円を差し引いて:net円が:when:destination。',
        'no_withholding_summary' => ':net円が:when:destination。',
        'when_today' => '本日',
        'when_date' => ':dateに',
        'destination' => [
            'bank_future' => ':receiptに振り込まれる予定です',
            'bank_past' => ':receiptに振り込まれました',
            'bank_today' => ':receiptに振り込まれる予定です',
            'receive_future' => ':receiptで受け取る予定です',
            'receive_past' => ':receiptで受け取りました',
            'receive_today' => ':receiptで受け取る予定です',
            'receivable_future' => ':receiptとして計上する予定です',
            'receivable_past' => ':receiptとして計上しました',
            'receivable_today' => ':receiptとして計上する予定です',
        ],
    ],
];
