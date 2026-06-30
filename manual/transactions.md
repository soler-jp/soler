# 仕訳を登録する

このアプリでは、売上も経費も「取引を登録すると、対応する仕訳も同時に作成される」という形で扱います。

共通の入口は `TransactionRegistrar` です。

## 前提

- 事業体が作成済みであること
- 現在の `FiscalYear` があること
- 仕訳に使う補助科目が事業体に属していること

## 売上を登録

売上登録では、通常は以下の2行を作ります。

- 借方: 現金や売掛金などの入金先
- 貸方: 売上高

### 免税業者の売上

免税業者では、`gross_amount` を入力して登録できます。  
売上側は `deemed_taxable_sales_10` として扱われ、内部的に本体額と消費税額へ分解されます。

```php
use App\Models\JournalEntry;
use App\Services\TransactionRegistrar;

$fiscalYear = auth()->user()->selectedBusinessUnit->currentFiscalYear;
$businessUnit = $fiscalYear->businessUnit;

$receiptSubAccount = $businessUnit->getSubAccountByName('現金', '現金');
$revenueSubAccount = $businessUnit->getSubAccountByName('売上高', '売上高');

app(TransactionRegistrar::class)->register(
    $fiscalYear,
    [
        'date' => '2025-04-01',
        'description' => '免税業者の売上',
    ],
    [
        [
            'sub_account_id' => $receiptSubAccount->id,
            'type' => 'debit',
            'gross_amount' => 10_000,
            'tax_type' => JournalEntry::TAX_TYPE_NON_TAXABLE,
        ],
        [
            'sub_account_id' => $revenueSubAccount->id,
            'type' => 'credit',
            'gross_amount' => 10_000,
            'tax_type' => JournalEntry::TAX_TYPE_DEEMED_TAXABLE_SALES_10,
        ],
    ],
);
```

### 課税業者の売上

課税業者では、`gross_amount` と `tax_type` を指定して登録します。  
売上側の `tax_type` によって、預かり消費税の額が決まります。

```php
use App\Models\JournalEntry;
use App\Services\TransactionRegistrar;

$fiscalYear = auth()->user()->selectedBusinessUnit->currentFiscalYear;
$businessUnit = $fiscalYear->businessUnit;

$receiptSubAccount = $businessUnit->getSubAccountByName('現金', '現金');
$revenueSubAccount = $businessUnit->getSubAccountByName('売上高', '売上高');

app(TransactionRegistrar::class)->register(
    $fiscalYear,
    [
        'date' => '2025-04-01',
        'description' => '課税業者の売上',
    ],
    [
        [
            'sub_account_id' => $receiptSubAccount->id,
            'type' => 'debit',
            'gross_amount' => 11_000,
            'tax_type' => JournalEntry::TAX_TYPE_NON_TAXABLE,
        ],
        [
            'sub_account_id' => $revenueSubAccount->id,
            'type' => 'credit',
            'gross_amount' => 11_000,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_SALES_10,
        ],
    ],
);
```

この例では、借方の入金先は `non_taxable` 扱いで、貸方の売上側に `taxable_sales_10` を付けます。
そのため、11,000 円の税込売上は 10,000 円の本体額と 1,000 円の預かり消費税に分解されます。
軽減税率 8% の売上を登録する場合は、貸方の `tax_type` を `JournalEntry::TAX_TYPE_TAXABLE_SALES_8` に変えます。

### 8% と 10% が混在する売上

1 件の売上に 8% と 10% が混在する場合は、貸方を税率ごとに分けます。

```php
use App\Models\JournalEntry;
use App\Services\TransactionRegistrar;

$fiscalYear = auth()->user()->selectedBusinessUnit->currentFiscalYear;
$businessUnit = $fiscalYear->businessUnit;

$cashSubAccount = $businessUnit->getSubAccountByName('現金', '現金');
$salesSubAccount = $businessUnit->getSubAccountByName('売上高', '売上高');

app(TransactionRegistrar::class)->register(
    $fiscalYear,
    [
        'date' => '2025-06-01',
        'description' => '8% と 10% が混在する売上',
    ],
    [
        [
            'sub_account_id' => $cashSubAccount->id,
            'type' => 'debit',
            'gross_amount' => 7_660,
            'tax_type' => JournalEntry::TAX_TYPE_NON_TAXABLE,
        ],
        [
            'sub_account_id' => $salesSubAccount->id,
            'type' => 'credit',
            'gross_amount' => 2_160,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_SALES_8,
        ],
        [
            'sub_account_id' => $salesSubAccount->id,
            'type' => 'credit',
            'gross_amount' => 5_500,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_SALES_10,
        ],
    ],
);
```

この場合は、8% と 10% の売上を別の仕訳明細として登録します。

## 経費を登録

経費登録では、以下の2行を作ります。

- 借方: 経費科目
- 貸方: 現金や普通預金などの支払先

### 免税業者の経費

免税業者でも、登録方法は同じです。  
借方の経費科目に `deemed_taxable_purchases_10` を使うと、内部的に本体額と消費税額へ分解されます。

```php
use App\Models\JournalEntry;
use App\Services\TransactionRegistrar;

$fiscalYear = auth()->user()->selectedBusinessUnit->currentFiscalYear;
$businessUnit = $fiscalYear->businessUnit;

$expenseSubAccount = $businessUnit->getSubAccountByName('消耗品費', '消耗品費');
$paymentSubAccount = $businessUnit->getSubAccountByName('現金', '現金');

app(TransactionRegistrar::class)->register(
    $fiscalYear,
    [
        'date' => '2025-05-10',
        'description' => '免税業者の経費',
    ],
    [
        [
            'sub_account_id' => $expenseSubAccount->id,
            'type' => 'debit',
            'gross_amount' => 1_100,
            'tax_type' => JournalEntry::TAX_TYPE_DEEMED_TAXABLE_PURCHASES_10,
        ],
        [
            'sub_account_id' => $paymentSubAccount->id,
            'type' => 'credit',
            'gross_amount' => 1_100,
            'tax_type' => JournalEntry::TAX_TYPE_NON_TAXABLE,
        ],
    ],
);
```

### 課税業者の経費

課税業者では、借方の経費科目に課税仕入の税区分を指定します。

```php
use App\Models\JournalEntry;
use App\Services\TransactionRegistrar;

$fiscalYear = auth()->user()->selectedBusinessUnit->currentFiscalYear;
$businessUnit = $fiscalYear->businessUnit;

$expenseSubAccount = $businessUnit->getSubAccountByName('消耗品費', '消耗品費');
$paymentSubAccount = $businessUnit->getSubAccountByName('現金', '現金');

app(TransactionRegistrar::class)->register(
    $fiscalYear,
    [
        'date' => '2025-05-10',
        'description' => '課税業者の経費',
    ],
    [
        [
            'sub_account_id' => $expenseSubAccount->id,
            'type' => 'debit',
            'gross_amount' => 1_100,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
        ],
        [
            'sub_account_id' => $paymentSubAccount->id,
            'type' => 'credit',
            'gross_amount' => 1_100,
            'tax_type' => JournalEntry::TAX_TYPE_NON_TAXABLE,
        ],
    ],
);
```

消費税 10% の経費は `JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10` を使います。  
軽減税率 8% の経費を登録する場合は、借方の `tax_type` を `JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_8` に変えます。

### 8% と 10% が混在する経費

1 枚のレシートに 8% と 10% の経費が混在する場合は、借方を税率ごとに分けます。

```php
use App\Models\JournalEntry;
use App\Services\TransactionRegistrar;

$fiscalYear = auth()->user()->selectedBusinessUnit->currentFiscalYear;
$businessUnit = $fiscalYear->businessUnit;

$expenseSubAccount = $businessUnit->getSubAccountByName('仕入金額', '仕入金額');
$cashSubAccount = $businessUnit->getSubAccountByName('現金', '現金');

app(TransactionRegistrar::class)->register(
    $fiscalYear,
    [
        'date' => '2025-06-01',
        'description' => '8% と 10% が混在する経費',
    ],
    [
        [
            'sub_account_id' => $expenseSubAccount->id,
            'type' => 'debit',
            'gross_amount' => 2_160,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_8,
        ],
        [
            'sub_account_id' => $expenseSubAccount->id,
            'type' => 'debit',
            'gross_amount' => 5_500,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
        ],
        [
            'sub_account_id' => $cashSubAccount->id,
            'type' => 'credit',
            'gross_amount' => 7_660,
            'tax_type' => JournalEntry::TAX_TYPE_NON_TAXABLE,
        ],
    ],
);
```

この場合は、8% と 10% の経費を別の仕訳明細として登録します。

## 補足

- 売上登録と経費登録は、どちらも同じ登録処理のバリエーションです
- 画面上の入力フォームも、内部ではこの登録処理を使っています
- 取引と仕訳の金額は一致している必要があります
