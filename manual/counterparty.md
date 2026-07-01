# 取引先の適格判定と集計を扱う

この手順書は、取引先の適格判定をどう入力し、どう過去日時点の状態を確認するかと、取引先の集計をどう取得するかをまとめたものです。

## できること

- 取引先の現在の適格判定を登録する
- 過去日にさかのぼった適格判定を登録する
- 特定日時点の判定を確認する

## 取引先の初期状態

取引先を新規作成した直後の適格判定は `unknown` です。

ただし、`unknown` は初期状態としてのみ使います。
登録後に `unknown` へ戻すことはできません。

## 適格判定を更新する

現在の判定を更新するには、事業体経由で取引先を取得してから `setQualificationStatus()` を使います。

```php
use App\Models\Counterparty;

$businessUnit = auth()->user()->selectedBusinessUnit;
$counterparty = $businessUnit->counterparties()->findOrFail($id);

$counterparty->setQualificationStatus(Counterparty::QUALIFICATION_STATUS_QUALIFIED);
```

登録できるのは `qualified` と `non_qualified` だけです。

`unknown` は初期状態としてのみ使います。

```php
$counterparty->setQualificationStatus(Counterparty::QUALIFICATION_STATUS_NON_QUALIFIED);
```

## 過去日にさかのぼって登録する

報告を受けた日と、実際に有効になった日が違う場合は、`effectiveFrom` を指定します。

```php
use App\Models\Counterparty;
use Carbon\Carbon;

$businessUnit = auth()->user()->selectedBusinessUnit;
$counterparty = $businessUnit->counterparties()->findOrFail($id);

$counterparty->setQualificationStatus(
    Counterparty::QUALIFICATION_STATUS_QUALIFIED,
    Carbon::parse('2026-01-04'),
);
```

この場合は、3/12 に入力しても、1/4 から適格だった扱いにできます。

## ある日時点の状態を確認する

`qualificationStatusAt()` で、特定日時点の適格判定を確認できます。

```php
use Carbon\Carbon;

$status = $counterparty->qualificationStatusAt(
    Carbon::parse('2026-03-15'),
);
```

## 判定の考え方

現在の実装では、次のルールで判定します。

- `unknown` の履歴は判定に使わない
- 最初に確定した状態を基準にする
- 指定日時以前で最新の有効化イベントを採用する
- 指定日時以前に確定イベントがなければ、最初の確定状態を遡及適用する

### 例

- 1/3 に `qualified` を登録
  - 1/1, 1/3, 1/8 は `qualified`
- 1/3 に `non_qualified` を登録
  - 1/1, 1/3, 1/8 は `non_qualified`
- 1/3 に `unknown` で作成し、1/10 に `qualified` を登録
  - 1/1, 1/3, 1/8, 1/10 は `qualified`

## 運用上の注意

- `unknown` を入力したい場合は、新規作成時の初期状態として扱う
- 既存の判定を未確定に戻す運用はしない
- 「いつから適格だったか」が分かる場合は、`effectiveFrom` を指定して登録する

## 取引先の集計を取る

集計には2つの入口があります。

- `calculateAmountSummary()` は、全体集計と年別集計を返す
- `calculateAmountSummaryForFiscalYear(2025)` は、指定した年だけの集計を返す

```php
$summary = $counterparty->calculateAmountSummary();
$summary2025 = $counterparty->calculateAmountSummaryForFiscalYear(2025);
```

### 全体集計と年別集計

`calculateAmountSummary()` の返り値は次の形です。

```php
[
    'all' => [
        'expense' => [
            'accounts' => [
                [
                    'account_id' => 12,
                    'account_name' => '消耗品費',
                    'amount' => 3300,
                ],
                [
                    'account_id' => 13,
                    'account_name' => '通信費',
                    'amount' => 2200,
                ],
            ],
            'total_amount' => 5500,
        ],
        'income' => [
            'accounts' => [
                [
                    'account_id' => 8,
                    'account_name' => '売上高',
                    'amount' => 9900,
                ],
            ],
            'total_amount' => 9900,
        ],
    ],
    'fiscal_years' => [
        2024 => [
            'expense' => [
                'accounts' => [
                    [
                        'account_id' => 12,
                        'account_name' => '消耗品費',
                        'amount' => 1100,
                    ],
                    [
                        'account_id' => 13,
                        'account_name' => '通信費',
                        'amount' => 2200,
                    ],
                ],
                'total_amount' => 3300,
            ],
            'income' => [
                'accounts' => [
                    [
                        'account_id' => 8,
                        'account_name' => '売上高',
                        'amount' => 4400,
                    ],
                ],
                'total_amount' => 4400,
            ],
        ],
        2025 => [
            'expense' => [
                'accounts' => [],
                'total_amount' => 0,
            ],
            'income' => [
                'accounts' => [
                    [
                        'account_id' => 8,
                        'account_name' => '売上高',
                        'amount' => 5500,
                    ],
                ],
                'total_amount' => 5500,
            ],
        ],
    ],
]
```

### 全体集計から読む場合

```php
$summary = $counterparty->calculateAmountSummary();
$expenseTotal = $summary['all']['expense']['total_amount'];
$year2025IncomeTotal = $summary['fiscal_years'][2025]['income']['total_amount'];
```

### 指定年だけ欲しい場合

`calculateAmountSummaryForFiscalYear(2025)` の返り値は次の形です。

```php
[
    'expense' => [
        'accounts' => [
            [
                'account_id' => 12,
                'account_name' => '消耗品費',
                'amount' => 3300,
            ],
            [
                'account_id' => 13,
                'account_name' => '通信費',
                'amount' => 2200,
            ],
        ],
        'total_amount' => 5500,
    ],
    'income' => [
        'accounts' => [
            [
                'account_id' => 8,
                'account_name' => '売上高',
                'amount' => 9900,
            ],
        ],
        'total_amount' => 9900,
    ],
]
```

```php
$summary2025 = $counterparty->calculateAmountSummaryForFiscalYear(2025);
$summary2025Expense = $summary2025['expense']['total_amount'];
```

## 補足

- 集計対象は有効な取引のみです
- 年別集計は `fiscal_years.year` をキーにします
- 取引がない場合は、`accounts` は空配列、`total_amount` は `0` が返ります
- 年指定集計は、指定した年の `expense` と `income` を直接返します

## 使う場面

- 取引先のマスターを作るとき
- 税務上の報告を受けたとき
- 取引日時点の適格判定を確認したいとき
