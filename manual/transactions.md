# 仕訳を登録する

このアプリでは、売上も経費も「取引を登録すると、対応する仕訳も同時に作成される」という形で扱います。

共通の入口は `TransactionRegistrar` です。

## 前提

- 事業体が作成済みであること
- 現在の `FiscalYear` があること
- 仕訳に使う補助科目が事業体に属していること

## 取引先を登録

`TransactionRegistrar` では、取引先を次の 3 パターンで扱えます。

- `counterparty_id` を指定する
- `counterparty_name` を指定して自動作成する
- 何も指定しない

注意点:

- `counterparty_id` と `counterparty_name` は同時に指定できません
- `counterparty_name` は前後の空白を `trim` し、空文字なら未指定として扱います
- `counterparty_registration_number` は任意です
- `counterparty_registration_number` は空白を除去して正規化し、`1234567890123` のような 13 桁数字は `T1234567890123` に変換して保存します
- `T` 付きの値はそのまま保存できます
- 取引先名だけを指定した場合は、同名の取引先がなければ自動でマスター登録されます
- 取引先を指定しない登録も可能です

```php
app(TransactionRegistrar::class)->register(
    $fiscalYear,
    [
        'date' => '2025-04-01',
        'description' => 'ABC商店への支払い',
        'counterparty_name' => 'ABC商店',
        'counterparty_registration_number' => '1234567890123',
    ],
    [
        // journal entries...
    ],
);
```

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



## 登録済み仕訳を修正する

登録済み仕訳の修正は、`JournalEntry` 1 行だけを直接書き換えるのではなく、`Transaction` 全体の改訂として扱います。

- 修正対象は、初期実装では通常取引のみです
- 修正できる内容は、主に金額と勘定科目です
- 修正前の取引は履歴として残り、無効化されます
- 修正後の内容は、新しい `Transaction` と `JournalEntry` として再登録されます

そのため、見た目上は 1 件の修正でも、保存上は「旧版の無効化 + 新版の作成」になります。

補足:

- 修正前の伝票番号は履歴として残ります
- 修正後の取引には新しい伝票番号が付きます
- 元帳や集計では、有効な最新版だけが通常表示されます
- 期首仕訳、予定取引、定期取引由来、減価償却仕訳、クレジットカード取込由来取引は、この修正フローの対象外です

### code例

```php
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Services\TransactionRevisor;

$transaction = Transaction::with('journalEntries')->findOrFail($transactionId);
$businessUnit = auth()->user()->selectedBusinessUnit;

$revisedExpenseSubAccount = $businessUnit->getAccountByName('消耗品費')->subAccounts()->first();
$revisedCreditSubAccount = $businessUnit->getAccountByName('事業主借')->subAccounts()->first();

$revised = app(TransactionRevisor::class)->revise(
    $transaction,
    auth()->user(),
    [
        'transaction' => [
            'revision_reason' => '金額入力ミスの修正',
        ],
        'journal_entries' => [
            [
                'sub_account_id' => $revisedExpenseSubAccount->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 2000,
                'tax_amount' => 200,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            ],
            [
                'sub_account_id' => $revisedCreditSubAccount->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => 2200,
                'tax_amount' => 0,
                'tax_type' => JournalEntry::TAX_TYPE_NON_TAXABLE,
            ],
        ],
    ],
);
```

この例では、既存取引を改訂し、借方の金額と勘定科目、貸方の金額と勘定科目をまとめて修正しています。

## 補足

- 売上登録と経費登録は、どちらも同じ登録処理のバリエーションです
- 画面上の入力フォームも、内部ではこの登録処理を使っています
- 取引と仕訳の金額は一致している必要があります
