# 年度Summary

`FiscalYear` には、年度の売上・経費・利益をまとめて確認するための集計 API があります。

この manual では、`calculateSummary()` と `calculateAmountSummary()` の使い方を説明します。

## 前提

- 事業体が作成済みであること
- 確認したい `FiscalYear` が存在すること
- 取引が登録済みであること

## Summary を取得するには

年度の総額ベースのサマリーは `calculateSummary()` で取得します。

### code例

```php
$fiscalYear = auth()->user()->selectedBusinessUnit->currentFiscalYear;

$summary = $fiscalYear->calculateSummary();
```

返り値は次の形です。

```php
[
    'actual' => [
        'total_income' => 10000,
        'total_expense' => 5000,
        'profit' => 5000,
    ],
    'planned' => [
        'total_income' => 20000,
        'total_expense' => 3000,
        'profit' => 17000,
    ],
]
```

`actual` は実績、`planned` は予定です。

`total_income` と `total_expense` は税込ベースの総額です。  
`profit` は `total_income - total_expense` で算出されます。

`planned` の元になる定期取引は [`manual/recurring-transactions.md`](recurring-transactions.md) を参照してください。

## 金額の内訳を取得するには

売上と経費を、`net_amount` / `tax_amount` / `gross_amount` で見たい場合は `calculateAmountSummary()` を使います。

### code例

```php
$fiscalYear = auth()->user()->selectedBusinessUnit->currentFiscalYear;

$amountSummary = $fiscalYear->calculateAmountSummary();
```

返り値は次の形です。

```php
[
    'actual' => [
        'sales' => [
            'net_amount' => 15000,
            'tax_amount' => 1500,
            'gross_amount' => 16500,
        ],
        'expenses' => [
            'net_amount' => 8000,
            'tax_amount' => 800,
            'gross_amount' => 8800,
        ],
    ],
    'planned' => [
        'sales' => [
            'net_amount' => 24000,
            'tax_amount' => 2400,
            'gross_amount' => 26400,
        ],
        'expenses' => [
            'net_amount' => 4000,
            'tax_amount' => 400,
            'gross_amount' => 4400,
        ],
    ],
]
```

このメソッドでは、実績 / 予定、売上 / 経費、税抜 / 消費税 / 税込の内訳を確認できます。

## 特定年度を取得するには

`currentFiscalYear` ではなく年度を指定して確認したい場合は、`BusinessUnit` から取得します。

### code例

```php
$businessUnit = auth()->user()->selectedBusinessUnit;
$fiscalYear = $businessUnit->fiscalYears()->where('year', 2025)->firstOrFail();

$summary = $fiscalYear->calculateSummary();
```

## 補足

- 売上は `account.type = revenue` かつ `journal_entries.type = credit` を対象にします
- 経費は `account.type = expense` かつ `journal_entries.type = debit` を対象にします
- `Transaction.is_planned = true` の取引は `planned` に入ります
- `Transaction.is_active = false` の取引は集計に含めません

## 参考

- `app/Models/FiscalYear.php`
- `app/Services/FiscalYearSummaryCalculator.php`
- `manual/recurring-transactions.md`
- `docs/fiscal-year-design.md`
