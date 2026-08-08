# 定期取引を登録する

定期取引は、`RecurringTransactionPlan` を作成し、そこから `is_planned = true` の予定取引を生成する仕組みです。

毎月・隔月・毎年発生する支出だけでなく、定期収入や源泉徴収付き収入もモデル/API で扱えます。

詳しい技術仕様は [`docs/recurring-transaction-design.md`](../docs/recurring-transaction-design.md) を参照してください。

## 前提

- 事業体が作成済みであること
- 現在の `FiscalYear` があること
- 借方・貸方に使う補助科目が対象事業体に属していること

## 定期取引計画を作る

計画の作成には `BusinessUnit::createRecurringTransactionPlan()` を使います。

### 支出の例

```php
use App\Models\JournalEntry;
use App\Models\RecurringTransactionPlan;

$actor = auth()->user();
$businessUnit = $actor->selectedBusinessUnitOrFail();

$debitSubAccount = $businessUnit->getAccountByName('通信費')->subAccounts()->firstOrFail();
$creditSubAccount = $businessUnit->getAccountByName('普通預金')->subAccounts()->firstOrFail();

$plan = $businessUnit->createRecurringTransactionPlan([
    'name' => 'ひかり回線利用料',
    'interval' => 'monthly',
    'day_of_month' => 10,
    'type' => RecurringTransactionPlan::TYPE_EXPENSE,
    'debit_sub_account_id' => $debitSubAccount->id,
    'credit_sub_account_id' => $creditSubAccount->id,
    'amount' => 5000,
    'tax_amount' => 500,
    'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
], $actor);
```

支出では、借方に経費科目、貸方に支払元を指定します。

### 収入の例

```php
use App\Models\JournalEntry;
use App\Models\RecurringTransactionPlan;

$depositSubAccount = $businessUnit->getAccountByName('その他の預金')->subAccounts()->firstOrFail();
$salesSubAccount = $businessUnit->getAccountByName('売上高')->subAccounts()->firstOrFail();

$plan = $businessUnit->createRecurringTransactionPlan([
    'name' => '月額保守料',
    'interval' => 'monthly',
    'day_of_month' => 31,
    'type' => RecurringTransactionPlan::TYPE_INCOME,
    'debit_sub_account_id' => $depositSubAccount->id,
    'credit_sub_account_id' => $salesSubAccount->id,
    'amount' => 100_000,
    'tax_amount' => 10_000,
    'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_SALES_10,
], $actor);
```

収入では、借方に入金先、貸方に売上科目を指定します。

### 源泉徴収付き収入の例

```php
use App\Models\RecurringTransactionPlan;

$depositSubAccount = $businessUnit->getAccountByName('その他の預金')->subAccounts()->firstOrFail();
$salesSubAccount = $businessUnit->getAccountByName('売上高')->subAccounts()->firstOrFail();
$withholdingSubAccount = $businessUnit
    ->getSubAccountByName('事業主貸', '源泉徴収');

$plan = $businessUnit->createRecurringTransactionPlan([
    'name' => '月次報酬',
    'interval' => 'monthly',
    'day_of_month' => 25,
    'type' => RecurringTransactionPlan::TYPE_INCOME,
    'is_withholding' => true,
    'debit_sub_account_id' => $depositSubAccount->id,
    'credit_sub_account_id' => $salesSubAccount->id,
    'amount' => 100_000,
    'tax_amount' => 0,
    'withholding_tax_amount' => 10_210,
    'withholding_sub_account_id' => $withholdingSubAccount->id,
], $actor);
```

この場合、予定取引は次の形で生成されます。

- 借方: 入金額
- 借方: 源泉徴収税
- 貸方: 売上

### 家事按分つき支出

支出計画では、借方の経費行に `business_ratio` を指定できます。

```php
use App\Models\JournalEntry;
use App\Models\RecurringTransactionPlan;

$plan = $businessUnit->createRecurringTransactionPlan([
    'name' => '通信費',
    'interval' => 'monthly',
    'day_of_month' => 10,
    'type' => RecurringTransactionPlan::TYPE_EXPENSE,
    'debit_sub_account_id' => $debitSubAccount->id,
    'credit_sub_account_id' => $creditSubAccount->id,
    'amount' => 10_000,
    'tax_amount' => 0,
    'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
    'business_ratio' => 60,
], $actor);
```

`business_ratio` を省略した場合は全額事業分として扱われます。  
収入計画では `business_ratio` は指定できません。

## 主な入力項目

- `interval`
  - `monthly`
  - `bimonthly`
  - `yearly`
- `type`
  - `RecurringTransactionPlan::TYPE_EXPENSE`
  - `RecurringTransactionPlan::TYPE_INCOME`
- `day_of_month`
  - 対象月に存在しない日は月末日に丸められます
- `month_of_year`
  - `yearly` の対象月
- `start_month`
  - `bimonthly` の開始月
  - `1` なら奇数月、`2` なら偶数月
- `is_withholding`
  - 源泉徴収付き収入かどうか
- `withholding_tax_amount`
  - 源泉徴収税額
- `withholding_sub_account_id`
  - 源泉徴収税の借方補助科目
- `counterparty_id`
  - 生成する取引へ自動付与する取引先
  - 省略可能
  - 指定する場合は同じ `BusinessUnit` に属する `Counterparty` を指定します

## バリデーション上の注意

- `type = expense` のとき `is_withholding = true` は指定できません
- `type = income` のとき `business_ratio` は指定できません
- `is_withholding = true` のとき `withholding_tax_amount > 0` と `withholding_sub_account_id` が必須です
- `is_withholding = true` のとき `withholding_tax_amount < amount + tax_amount` が必要です
- `is_withholding = false` のとき源泉カラムは空にしてください
- `counterparty_id` を指定する場合は、計画の `BusinessUnit` に属する取引先でなければなりません

## 予定取引を生成する

その年度に属する予定取引の生成には `BusinessUnit::generatePlannedTransactionsForPlan()` を使います。

```php
$fiscalYear = $businessUnit->currentFiscalYear;
$transactions = $businessUnit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $actor);
```

生成された取引は `is_planned = true` になり、`recurring_transaction_plan_id` に元の計画IDが入ります。
また、計画に `counterparty_id` が設定されていれば、生成された予定取引にも同じ `counterparty_id` が入ります。

### 月次

`interval = monthly` なら、年度内の各月に 1 件ずつ生成されます。

### 隔月

`interval = bimonthly` なら、`start_month` に応じて奇数月または偶数月に生成されます。

- `start_month = 1`
  - 1, 3, 5, 7, 9, 11 月
- `start_month = 2`
  - 2, 4, 6, 8, 10, 12 月
- `start_month = null`
  - 1 月開始として扱われます

### 毎年

`interval = yearly` なら、`month_of_year` と `day_of_month` に従って年 1 件だけ生成されます。

## 予定取引を確定する

生成済みの予定取引は `RecurringTransactionPlan::confirmTransaction()` で本登録に変更できます。

```php
$transaction = $plan->transactions()
    ->where('is_planned', true)
    ->whereDate('date', '2025-12-10')
    ->firstOrFail();
```

### 支出を確定する例

```php
$confirmed = $plan->confirmTransaction($transaction->id, [
    'date' => '2025-12-10',
    'amount' => 1400,
    'credit_sub_account_id' => $newCreditSubAccount->id,
    'business_ratio' => 80,
], $actor);
```

支出計画では次を上書きできます。

- `date`
- `amount`
- `credit_sub_account_id`
- `business_ratio`

### 収入を確定する例

```php
$confirmed = $plan->confirmTransaction($transaction->id, [
    'date' => '2025-12-10',
    'amount' => 22_000,
    'debit_sub_account_id' => $newDepositSubAccount->id,
], $actor);
```

収入計画では次を上書きできます。

- `date`
- `amount`
- `debit_sub_account_id`

課税収入では、確定後も貸方の売上税区分と税額が保持されます。

`counterparty_id` は予定取引の生成時点で引き継がれるため、確定時もそのまま保持されます。

### 源泉徴収付き収入を確定する例

```php
$confirmed = $plan->confirmTransaction($transaction->id, [
    'date' => '2025-12-10',
    'amount' => 100_000,
    'debit_sub_account_id' => $newDepositSubAccount->id,
], $actor);
```

この場合も、借方 2 行は維持されます。  
また、確定時に `amount` を変更する場合でも、`amount` は `withholding_tax_amount` より大きい必要があります。

## 定期収入の予定取引を実現する

定期収入は、単純な `confirmTransaction()` だけでなく `RecurringIncomeRealizationService` を使って実現できます。

このサービスは、入金日が予定日と同じ月か、翌月以降かで処理を分けます。

- 同じ月の受取
  - 受取日をそのまま売上日として確定します
- 別の月の受取
  - 予定日で売上を確定し、受取日に売掛金回収の取引を追加します
- 年払い
  - 受取日をそのまま売上日として確定します

### 同月受取の例

```php
use App\Services\RecurringIncomeRealizationService;

$plannedTransaction = $plan->transactions()
    ->where('is_planned', true)
    ->whereDate('date', '2025-01-25')
    ->firstOrFail();

$realizedTransactions = app(RecurringIncomeRealizationService::class)->realize(
    $plannedTransaction,
    [
        'amount' => 110_000,
        'withholding_tax_amount' => 10_210,
        'receipt_date' => '2025-01-31',
        'receipt_sub_account_id' => $depositSubAccount->id,
    ],
    $actor,
);
```

この場合は 1 件の取引だけが返り、`receipt_date` がそのまま売上日になります。

### 翌月入金の例

```php
use App\Services\RecurringIncomeRealizationService;

$plannedTransaction = $plan->transactions()
    ->where('is_planned', true)
    ->whereDate('date', '2025-01-25')
    ->firstOrFail();

$realizedTransactions = app(RecurringIncomeRealizationService::class)->realize(
    $plannedTransaction,
    [
        'amount' => 110_000,
        'withholding_tax_amount' => 10_210,
        'receipt_date' => '2025-02-10',
        'receipt_sub_account_id' => $depositSubAccount->id,
    ],
    $actor,
);
```

この場合は 2 件の取引が返ります。

- 1 件目
  - 予定日で売上を確定した取引
  - 借方は `売掛金`
- 2 件目
  - 受取日に売掛金を回収する取引
  - `settled_transaction_id` に 1 件目の取引 ID が入ります

### 税抜金額と消費税額で入力する

税込金額（`amount`）の代わりに、税抜金額と消費税額を分けて入力できます。請求書に記載された税抜金額・消費税額を、そのまま貸方の売上仕訳に反映したいときに使います。

```php
use App\Services\RecurringIncomeRealizationService;

$realizedTransactions = app(RecurringIncomeRealizationService::class)->realize(
    $plannedTransaction,
    [
        'input_mode' => 'net_tax',
        'net_amount' => 493_812,
        'tax_amount' => 49_381,
        'withholding_tax_amount' => 10_210,
        'receipt_date' => '2025-01-25',
        'receipt_sub_account_id' => $depositSubAccount->id,
    ],
    $actor,
);
```

- 税込金額は `net_amount + tax_amount` として扱われます
- 貸方の売上仕訳には `net_amount` と `tax_amount` がそのまま記録されます（プラン既定の税率での再計算は行いません）
- 税率（8% / 10%）は `net_amount` と `tax_amount` から自動判定されるため `tax_option` は指定できません
  - 判定は `net_amount × 税率 ≒ tax_amount`（1 円までの差を許容）で行います
  - どちらの税率にも一致しない場合や、両方の税率で完全一致してしまう場合はエラーになります
- 翌月受取（売掛金経由）や年払いの実現でも同じ形式で使えます
- 課税年度のみで利用できます。非課税年度は `input_mode` を省略するか `'gross'` を指定してください

### 入力項目

- `input_mode`
  - `'gross'`（既定）または `'net_tax'`
  - `'gross'` のときは `amount` を、`'net_tax'` のときは `net_amount` と `tax_amount` を使います
- `amount`
  - `input_mode = 'gross'` のときの税込受取額
- `net_amount`
  - `input_mode = 'net_tax'` のときの税抜金額
- `tax_amount`
  - `input_mode = 'net_tax'` のときの消費税額
- `tax_option`
  - `'8'` または `'10'`
  - 課税年度で `input_mode = 'gross'` のときのみ指定します
  - `input_mode = 'net_tax'` のときは自動判定されるため指定できません
- `withholding_tax_amount`
  - 源泉徴収税額
  - 現時点では計画どおりの金額だけ実現できます
- `receipt_date`
  - 実際の受取日または入金日
- `receipt_sub_account_id`
  - 入金先・受取先の補助科目

### 注意

- `receipt_date` が別の月で、かつ予定日より前の日付は実現できません
- 将来日を指定して翌月回収にした場合、回収側の取引は `is_planned = true` の予定取引として残ります
- 売掛経由の実現は 1 つの DB トランザクションで処理されるため、回収取引の登録に失敗した場合は売上確定もロールバックされます

## 補足

- `is_active = false` の計画は予定取引を生成しません
- 同じプラン・同じ日付の予定取引が既にある場合は再生成されません
- 同じ日付でも別プランなら生成されます
- `day_of_month = 31` のように対象月に存在しない日を指定した場合は月末日に丸められます
- 現在の UI コンポーネントは主に支出向けで、収入用 UI は別途実装対象です

## 参考

- [`docs/recurring-transaction-design.md`](../docs/recurring-transaction-design.md)
- [`docs/transaction-registration.md`](../docs/transaction-registration.md)
