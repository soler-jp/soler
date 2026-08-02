<?php

return [
    'todo_card' => [
        'intro' => 'サイドメニューの「固定費」で、後から追加・削除・編集ができます。',
        'fields' => [
            'credit_source' => '主な支払い元',
            'gross_amount' => '支払いの見込み金額(税込)',
            'business_ratio' => '経費計上する割合',
            'tax_type' => '消費税',
            'interval' => '頻度',
            'payment_month' => '支払い予定月',
            'payment_day' => '支払い予定日',
            'bimonthly_month_type' => '支払い月',
        ],
        'help' => [
            'amount' => '支払った金額を入力する時に変更できるので、仮の金額で大丈夫です。',
            'business_ratio' => '事業で使う割合を 1 から 100 で入力します。たとえば自宅家賃の 40% を事業用に使っているなら 40 です。全額が事業用なら空欄のままでも問題ありません。支払った金額を入力するときに変更できるので、仮の割合で構いません。',
            'rent_tax_type_residential' => '住宅用として契約している家賃は非課税を選んでください。',
            'rent_tax_type_business' => '事業専用として契約している家賃は 10% を選んでください。',
            'rent_tax_type_source_label' => '根拠: 国税庁 No.6226 住宅の貸付け',
            'rent_tax_type_source_url' => 'https://www.nta.go.jp/taxes/shiraberu/taxanswer/shohi/6226.htm',
        ],
        'defaults' => [
            'rent' => '家賃',
            'electricity' => '電気代',
            'gas' => 'ガス代',
            'water' => '水道代',
            'mobile_phone' => '携帯電話代',
            'internet' => 'インターネット料金',
            'vehicle_inspection' => '車検代',
        ],
        'options' => [
            'interval' => [
                'monthly' => '毎月',
                'bimonthly' => '隔月',
                'yearly' => '毎年',
            ],
            'tax_type' => [
                'exempt' => '非課税',
                'taxable_8' => '8%',
                'taxable_10' => '10%',
            ],
            'month_type' => [
                'odd' => '奇数月',
                'even' => '偶数月',
            ],
        ],
        'errors' => [
            'bimonthly_month_type_required' => '隔月を選んだ場合は、奇数月か偶数月を選択してください。',
            'payment_month_required' => '年1回を選んだ場合は、支払い予定月を入力してください。',
            'locked_tax_type' => 'この定期支出では消費税区分を変更できません。',
        ],
        'actions' => [
            'submit' => '固定費を登録する',
        ],
    ],
];
