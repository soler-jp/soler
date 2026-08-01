# 勘定科目と補助科目を追加する

この manual では、事業体ごとのカスタム `Account` / `SubAccount` を追加する方法を説明します。

入口は次の 2 つです。

- `BusinessUnit::addCustomAccount()`
- `Account::addCustomSubAccount()`

## 前提

- 対象の `User` があること
- 対象の `BusinessUnit` がその `User` に属していること
- 操作は `vendor/bin/sail artisan tinker` など、アプリケーションコンテキスト内で行うこと

## 勘定科目を追加する

`BusinessUnit::addCustomAccount()` は、勘定科目を追加し、あわせて初期の補助科目を 1 件作成します。

### 同名の補助科目を自動作成する

第3引数に `null` を渡すと、`Account` と同じ名前の `SubAccount` が作成されます。

```php
$user = App\Models\User::findOrFail(1);
$businessUnit = $user->businessUnits()->findOrFail(10);

$account = $businessUnit->addCustomAccount(
    App\Models\Account::TYPE_EXPENSE,
    '会議費',
    null,
    $user,
);
```

この場合は次の 2 件が作成されます。

- `Account: 会議費`
- `SubAccount: 会議費`

### 別名の補助科目を同時に作成する

第3引数に補助科目名を指定すると、初期補助科目名を上書きできます。

```php
$user = App\Models\User::findOrFail(1);
$businessUnit = $user->businessUnits()->findOrFail(10);

$account = $businessUnit->addCustomAccount(
    App\Models\Account::TYPE_EXPENSE,
    '会議費',
    '役員会議',
    $user,
);
```

この場合は次の 2 件が作成されます。

- `Account: 会議費`
- `SubAccount: 役員会議`

## 既存の勘定科目に補助科目を追加する

既存 `Account` に補助科目だけを追加したい場合は、`Account::addCustomSubAccount()` を使います。

```php
$user = App\Models\User::findOrFail(1);
$businessUnit = $user->businessUnits()->findOrFail(10);
$account = $businessUnit->getAccountByName('会議費');

$subAccount = $account->addCustomSubAccount('定例会議', $user);
```

## 銀行口座を登録する

`その他の預金` 配下に銀行口座を追加し、必要に応じて期首仕訳も整えたい場合は、`Account::addCustomSubAccount()` ではなく `BankAccountRegistrationService` を使います。

### `addCustomSubAccount()` との違い

- `addCustomSubAccount()` は補助科目を 1 件追加するだけ
- `BankAccountRegistrationService` は銀行口座登録という業務操作をまとめて扱う
- `opening_balance > 0` のときは、期首仕訳の新規作成または改訂まで行う
- `opening_balance = 0` のときは、補助科目だけを追加して期首仕訳は触らない

### code例

```php
use App\Services\BankAccountRegistrationService;

$actor = auth()->user();
$businessUnit = $actor->selectedBusinessUnitOrFail();
$fiscalYear = $businessUnit->currentFiscalYear;

$subAccount = app(BankAccountRegistrationService::class)->register(
    $businessUnit,
    $fiscalYear,
    'ひかり青空銀行',
    120000,
    $actor,
);
```

### 0円で登録する例

```php
use App\Services\BankAccountRegistrationService;

$subAccount = app(BankAccountRegistrationService::class)->register(
    $businessUnit,
    $fiscalYear,
    'みらい星銀行',
    0,
    $actor,
);
```

このケースでは `SubAccount` は作成されますが、0円の期首仕訳は作成しません。

## 注意点

- `BusinessUnit` は必ず `$user->businessUnits()->findOrFail(...)` などで取得し、他ユーザーの事業体を直接触らないこと
- 同一事業体で同名の `Account` を `addCustomAccount()` しようとすると例外になります
- 同一 `Account` で同名の `SubAccount` を追加すると DB の一意制約で失敗します
- `addCustomSubAccount('')` のような空文字は許可しません
- `BankAccountRegistrationService` は actor が対象 `BusinessUnit` / `FiscalYear` にアクセスできない場合、`AuthorizationException` を投げます
- `BankAccountRegistrationService` では、銀行名が空文字または空白のみの場合は `DomainException` になります
- `BankAccountRegistrationService` の `opening_balance` は 0 円以上でなければなりません

## 参考

- [app/Models/BusinessUnit.php](../app/Models/BusinessUnit.php)
- [app/Models/Account.php](../app/Models/Account.php)
- [app/Services/BankAccountRegistrationService.php](../app/Services/BankAccountRegistrationService.php)
- [tests/Unit/BusinessUnitTest.php](../tests/Unit/BusinessUnitTest.php)
- [tests/Feature/SubAccountTest.php](../tests/Feature/SubAccountTest.php)
- [tests/Feature/Setup/BankAccountRegistrationServiceTest.php](../tests/Feature/Setup/BankAccountRegistrationServiceTest.php)
