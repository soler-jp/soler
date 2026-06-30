# 定期取引を登録する

定期取引は、`RecurringTransactionPlan` を作成して、そこから `is_planned = true` の取引を生成する仕組みです。

固定費のように毎月・隔月・毎年発生する取引は、この手順で登録します。

## 前提

- 事業体が作成済みであること
- 現在の `FiscalYear` があること
- 借方と貸方に使う補助科目が事業体に属していること

## 定期取引計画を作るには

定期取引計画は `BusinessUnit::createRecurringTransactionPlan()` で作ります。

### code例

```php
use App\Models\JournalEntry;

$businessUnit = auth()->user()->selectedBusinessUnit;

$debitSubAccount = $businessUnit->getAccountByName('通信費')->subAccounts()->firstOrFail();
$creditSubAccount = $businessUnit->getAccountByName('普通預金')->subAccounts()->firstOrFail();

$plan = $businessUnit->createRecurringTransactionPlan([
    'name' => 'ひかり回線利用料',
    'interval' => 'monthly',
    'day_of_month' => 10,
    'is_income' => false,
    'debit_sub_account_id' => $debitSubAccount->id,
    'credit_sub_account_id' => $creditSubAccount->id,
    'amount' => 5000,
    'tax_amount' => 500,
    'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
]);
```

返り値は作成された `RecurringTransactionPlan` です。

### 非課税の定期取引の例

例えば、年1回の損害保険料は非課税として登録できます。

```php
use App\Models\JournalEntry;

$businessUnit = auth()->user()->selectedBusinessUnit;

$debitSubAccount = $businessUnit->getAccountByName('損害保険料')->subAccounts()->firstOrFail();
$creditSubAccount = $businessUnit->getAccountByName('普通預金')->subAccounts()->firstOrFail();

$plan = $businessUnit->createRecurringTransactionPlan([
    'name' => '火災保険料',
    'interval' => 'yearly',
    'month_of_year' => 4,
    'day_of_month' => 1,
    'is_income' => false,
    'debit_sub_account_id' => $debitSubAccount->id,
    'credit_sub_account_id' => $creditSubAccount->id,
    'amount' => 20000,
    'tax_amount' => 0,
    'tax_type' => JournalEntry::TAX_TYPE_NON_TAXABLE,
]);
```

## 予定取引を生成するには

定期取引計画から、その年度に属する予定取引を生成するには `BusinessUnit::generatePlannedTransactionsForPlan()` を使います。

### code例

```php
$fiscalYear = auth()->user()->selectedBusinessUnit->currentFiscalYear;
$transactions = $businessUnit->generatePlannedTransactionsForPlan($plan, $fiscalYear);
```

このとき生成される取引は `is_planned = true` になります。  
また、`recurring_transaction_plan_id` に元の計画IDが入ります。

### 月次プランの例

`interval = monthly` の場合は、年度内の各月に1件ずつ予定取引が生成されます。

```php
$transactions = $businessUnit->generatePlannedTransactionsForPlan($plan, $fiscalYear);
```

`day_of_month = 10` の月次プランでは、各月の10日に予定取引が生成されます。

### 隔月プランの例

`interval = bimonthly` の場合は、`start_month` に応じて奇数月または偶数月に予定取引を生成します。

```php
$plan = $businessUnit->createRecurringTransactionPlan([
    'name' => '隔月プラン',
    'interval' => 'bimonthly',
    'day_of_month' => 15,
    'is_income' => false,
    'debit_sub_account_id' => $debitSubAccount->id,
    'credit_sub_account_id' => $creditSubAccount->id,
    'amount' => 5000,
    'tax_amount' => 500,
    'start_month' => 1,
]);
```

`start_month = 1` の場合は、次の月に生成されます。

- `2025-01-15`
- `2025-03-15`
- `2025-05-15`
- `2025-07-15`
- `2025-09-15`
- `2025-11-15`

`start_month = 2` の場合は、偶数月に生成されます。

## 予定取引を確定するには

生成済みの予定取引は、`RecurringTransactionPlan::confirmTransaction()` で本登録に変更できます。

生成された予定取引のうち、特定の日付のものを取り出して確定する場合は、`transactions()` から取得できます。

### code例

```php
$transaction = $plan->transactions()
    ->where('is_planned', true)
    ->whereDate('date', '2025-12-10')
    ->firstOrFail();

$confirmed = $plan->confirmTransaction($transaction->id, [
    'date' => '2025-12-10',
    'amount' => 1400,
    'credit_sub_account_id' => $newCreditSubAccount->id,
]);
```

確定すると、次のように変わります。

- `is_planned` が `false` になる
- 仕訳の `net_amount` が指定金額に更新される
- 貸方補助科目を変更できる
- 日付も指定した値に更新できる

## `is_income` について

定期取引計画は `is_income` で収入と支出を分けます。

- `is_income = false`
  - 固定費などの支出
- `is_income = true`
  - 定期収入

現状の manual では、固定費の登録を主な用途として扱います。

## 補足

- `is_active = false` の計画は予定取引を生成しません
- 同じ日付に別のプランがあっても、別プランなら生成されます
- 同じプランで同じ日付の予定取引が既にある場合は、新規生成されません

## 参考

- `app/Models/RecurringTransactionPlan.php`
- `app/Models/BusinessUnit.php`
- `app/Livewire/Recurring/Form.php`
- `app/Livewire/Recurring/TabList.php`
- `tests/Feature/RecurringTransactionPlanTest.php`
