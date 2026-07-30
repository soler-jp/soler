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

## 注意点

- `BusinessUnit` は必ず `$user->businessUnits()->findOrFail(...)` などで取得し、他ユーザーの事業体を直接触らないこと
- 同一事業体で同名の `Account` を `addCustomAccount()` しようとすると例外になります
- 同一 `Account` で同名の `SubAccount` を追加すると DB の一意制約で失敗します
- `addCustomSubAccount('')` のような空文字は許可しません

## 参考

- [app/Models/BusinessUnit.php](../app/Models/BusinessUnit.php)
- [app/Models/Account.php](../app/Models/Account.php)
- [tests/Unit/BusinessUnitTest.php](../tests/Unit/BusinessUnitTest.php)
- [tests/Feature/SubAccountTest.php](../tests/Feature/SubAccountTest.php)
