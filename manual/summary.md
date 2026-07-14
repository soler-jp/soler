# 年度Summary

`FiscalYear` には、年度の売上・経費・利益をまとめて確認するための集計 API があります。

この manual では、`calculateSummary()`、`calculateAmountSummary()`、月別の勘定タイプ別集計 API の使い方を説明します。

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

## 月別の売上・経費を取得するには

売上や経費を月別に一覧表示したい場合は、`FiscalYear` の月別勘定タイプ別 API を使います。

主な入口は次の 3 つです。

- `monthlyAccountTypeSummaries()` は、月ごとの合計を返します
- `monthlyAccountTypeTransactions()` は、指定月の取引明細を返します
- `monthlyAccountTypeTransactionGroups()` は、月ごとの合計と、その月の取引明細をまとめて返します

### 月別合計を取得する

```php
use App\Models\Account;

$fiscalYear = auth()->user()->selectedBusinessUnit->currentFiscalYear;

$months = $fiscalYear->monthlyAccountTypeSummaries(Account::TYPE_REVENUE);
```

返り値は次の形です。月は `year_month` の昇順です。

```php
[
    [
        'year_month' => '2025-01',
        'label' => '2025年1月',
        'amount' => 100000,
    ],
    [
        'year_month' => '2025-02',
        'label' => '2025年2月',
        'amount' => 80000,
    ],
]
```

`amount` は税込ベースです。売上は貸方を加算・借方を控除し、経費は借方を加算・貸方を控除します。合計が 0 の月は返しません。

### 指定月の取引明細を取得する

```php
use App\Models\Account;

$fiscalYear = auth()->user()->selectedBusinessUnit->currentFiscalYear;

$transactions = $fiscalYear->monthlyAccountTypeTransactions(
    Account::TYPE_EXPENSE,
    '2025-01',
);
```

返り値は次の形です。

```php
[
    [
        'id' => 1,
        'date' => '2025-01-10',
        'amount' => 6000,
        'payment_amount' => 10000,
        'description' => '在宅作業用品',
        'allocation_note' => '支払い10,000円の60％分',
        'debit_label' => '消耗品費',
        'debit_badge_class' => 'border-sky-200 bg-sky-50 text-sky-700',
        'credit_label' => '現金',
        'credit_badge_class' => 'border-blue-200 bg-blue-50 text-blue-700',
        'tax_type_label' => '非課税',
        'tax_type_badge_class' => 'border-slate-200 bg-slate-50 text-slate-700',
        'counterparty_name' => '文具店',
    ],
]
```

明細は `date`、`id` の昇順です。逆仕訳の場合、`amount` はマイナスになることがあります。

家事按分がある経費では、`amount` に経費計上額を返します。`payment_amount` は支払い総額、`allocation_note` は「支払いxxx円のnn％分」の形式です。家事按分用の補助科目名は `debit_label` には含めません。

### 月別合計と明細をまとめて取得する

```php
use App\Models\Account;

$fiscalYear = auth()->user()->selectedBusinessUnit->currentFiscalYear;

$groups = $fiscalYear->monthlyAccountTypeTransactionGroups(Account::TYPE_REVENUE);
```

返り値は、月別合計に `transactions` を加えた形です。

```php
[
    [
        'year_month' => '2025-01',
        'label' => '2025年1月',
        'amount' => 100000,
        'transactions' => [
            // monthlyAccountTypeTransactions() と同じ形
        ],
    ],
]
```

### 勘定科目で絞り込む

第 3 引数の `accountNames` で対象勘定科目を限定できます。例えば仕入れだけを表示する場合は、`仕入金額` を指定します。

```php
$purchases = $fiscalYear->monthlyAccountTypeTransactionGroups(
    Account::TYPE_EXPENSE,
    accountNames: ['仕入金額'],
);
```

第 4 引数の `excludedAccountNames` で対象から除外できます。例えば経費一覧から仕入れを除外する場合は次のようにします。

```php
$expenses = $fiscalYear->monthlyAccountTypeTransactionGroups(
    Account::TYPE_EXPENSE,
    excludedAccountNames: ['仕入金額'],
);
```

## 特定年度を取得するには

`currentFiscalYear` ではなく年度を指定して確認したい場合は、`BusinessUnit` から取得します。

### code例

```php
$businessUnit = auth()->user()->selectedBusinessUnit;
$fiscalYear = $businessUnit->fiscalYears()->where('year', 2025)->firstOrFail();

$summary = $fiscalYear->calculateSummary();
```

## 補足

- 売上は `account.type = revenue` を対象にし、貸方を加算・借方を控除した純額で集計します
- 経費は `account.type = expense` を対象にし、借方を加算・貸方を控除した純額で集計します
- `Transaction.is_planned = true` の取引は `planned` に入ります
- `Transaction.is_active = false` の取引は集計に含めません
- 月別勘定タイプ別 API は `account.type = revenue` または `account.type = expense` だけを受け付けます
- 月別勘定タイプ別 API は実績取引だけを対象にし、`Transaction.is_planned = true` と `Transaction.is_active = false` は含めません
- 月別勘定タイプ別 API で `accountNames` と `excludedAccountNames` を同時に指定した場合は、指定した勘定科目のうち除外対象ではないものだけが対象です

## 参考

- `app/Models/FiscalYear.php`
- `app/Services/FiscalYearSummaryCalculator.php`
- `tests/Feature/FiscalYearAccountTypeTransactionSummaryTest.php`
- [`manual/balance.md`](balance.md)
- `manual/recurring-transactions.md`
- `docs/fiscal-year-design.md`
