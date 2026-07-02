# 年度残高

`FiscalYear` には、資産・負債・資本の帳簿残高を取得するための API があります。

この manual では、`calculateBalanceSummary()` の使い方を説明します。

## 前提

- 事業体が作成済みであること
- 会計年度が作成済みであること
- 取引が登録済みであること

## 残高を取得するには

年度の残高サマリーは `calculateBalanceSummary()` で取得します。

### code例

```php
$fiscalYear = auth()->user()->selectedBusinessUnit->currentFiscalYear;

$balanceSummary = $fiscalYear->calculateBalanceSummary();
```

返り値は次の形です。

```php
[
    'asset' => [
        'total_balance' => 2800,
        'accounts' => [
            [
                'account_id' => 1,
                'account_name' => '現金',
                'balance' => 600,
                'sub_accounts' => [
                    [
                        'sub_account_id' => 11,
                        'sub_account_name' => 'レジ現金',
                        'balance' => 600,
                    ],
                ],
            ],
        ],
    ],
    'liability' => [
        'total_balance' => 0,
        'accounts' => [],
    ],
    'equity' => [
        'total_balance' => 2800,
        'accounts' => [
            [
                'account_id' => 3,
                'account_name' => '事業主借',
                'balance' => 3300,
                'sub_accounts' => [
                    [
                        'sub_account_id' => 31,
                        'sub_account_name' => '事業主借',
                        'balance' => 3300,
                    ],
                ],
            ],
            [
                'account_id' => 4,
                'account_name' => '事業主貸',
                'balance' => -500,
                'sub_accounts' => [
                    [
                        'sub_account_id' => 41,
                        'sub_account_name' => '事業主貸',
                        'balance' => -500,
                    ],
                ],
            ],
        ],
    ],
]
```

`asset`、`liability`、`equity` の 3 分類で返ります。  
各分類の中では、`accounts` が勘定科目、`sub_accounts` が補助科目です。

## 返り値の見方

- `total_balance`
  - その分類全体の残高です
- `account_name`
  - 勘定科目名です
- `balance`
  - 勘定科目の残高です
- `sub_accounts`
  - 補助科目ごとの残高です

残高は `net_amount + tax_amount` を基準に集計されます。  
また、`Transaction.is_planned = true` の取引は含めません。

`事業主貸` のように、分類の正方向と逆向きになる科目は負の残高で返ることがあります。  
これは異常ではなく、帳簿上の向きをそのまま表しています。

## 突合するには

この API は帳簿残高を返すだけなので、実残高との比較は画面側で行います。

### code例

```php
$balanceSummary = $fiscalYear->calculateBalanceSummary();

$cashAccount = collect($balanceSummary['asset']['accounts'])
    ->firstWhere('account_name', '現金');

$cashBookBalance = $cashAccount['balance'] ?? 0;

$actualCashBalance = 5000;
$difference = $actualCashBalance - $cashBookBalance;
```

この `difference` を表示すれば、現金や預金の突合ができます。

## 特定年度を取得するには

`currentFiscalYear` ではなく年度を指定して確認したい場合は、`BusinessUnit` から取得します。

### code例

```php
$businessUnit = auth()->user()->selectedBusinessUnit;
$fiscalYear = $businessUnit->fiscalYears()->where('year', 2025)->firstOrFail();

$balanceSummary = $fiscalYear->calculateBalanceSummary();
```

## 補足

- 集計対象は `Transaction.fiscal_year_id` が対象年度のものです
- `Transaction.is_active = false` の取引は集計に含めません
- `Transaction.is_planned = true` の取引は集計に含めません
- 期首仕訳も通常の取引として残高に含めます

## 参考

- `app/Models/FiscalYear.php`
- `app/Services/FiscalYearBalanceCalculator.php`
- `docs/fiscal-year-balance-design.md`
- [`manual/summary.md`](summary.md)
