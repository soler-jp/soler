# 元帳を取得する

`GeneralLedgerService` を使うと、勘定科目ごとの元帳を取得できます。

この manual は、`transaction-registration.md` で登録した取引を、閲覧用の元帳として取り出す方法をまとめたものです。

## 前提

- 事業体が作成済みであること
- 会計年度が作成済みであること
- 取得したい勘定科目または補助科目に対して、仕訳が登録済みであること

## 勘定科目の元帳を取得するには

`generate()` は、指定した勘定科目に属する仕訳を、元帳行の配列として返します。  
`Transaction` 1件を1行で返すのではなく、`JournalEntry` 1件ごとに元帳行が返るので、通常は複数レコードになります。

`App\Services\GeneralLedgerService::generate()` に `Account` と `FiscalYear` を渡します。

### code例

```php
use App\Services\GeneralLedgerService;

$businessUnit = auth()->user()->selectedBusinessUnit;
$fiscalYear = $businessUnit->currentFiscalYear;
$cashAccount = $businessUnit->getAccountByName('現金');

$ledger = app(GeneralLedgerService::class)->generate($cashAccount, $fiscalYear);
```

返り値は、行ごとの配列です。

```php
[
    [
        'date' => '2025-01-10',
        'description' => '資本金の預け入れ',
        'debit' => 100000,
        'credit' => null,
        'balance' => 100000,
    ],
    [
        'date' => '2025-01-15',
        'description' => '備品の購入',
        'debit' => null,
        'credit' => 30000,
        'balance' => 70000,
    ],
    [
        'date' => '2025-01-20',
        'description' => '追加出資',
        'debit' => 50000,
        'credit' => null,
        'balance' => 120000,
    ],
]
```

例えば、[tests/Feature/GeneralLedgerServiceTest.php](../tests/Feature/GeneralLedgerServiceTest.php) の複数仕訳ケースのように、同じ勘定科目に関係する仕訳が時系列で複数行返ります。  
借方と貸方が同じ元帳に混在することもありますが、それは「同じ勘定科目が借方にも貸方にも使われた別々の取引がある」ときです。

## 補助科目の元帳を取得するには

`generateForSubAccount()` を使うと、補助科目単位で元帳を取得できます。

```php
use App\Services\GeneralLedgerService;

$businessUnit = auth()->user()->selectedBusinessUnit;
$fiscalYear = $businessUnit->currentFiscalYear;
$subAccount = $businessUnit->getSubAccountByName('現金', 'レジ現金');

$ledger = app(GeneralLedgerService::class)->generateForSubAccount($subAccount, $fiscalYear);
```

## 現金出納帳を取得するには

`generateCashbook()` は、`現金` 勘定の元帳を返すショートカットです。

```php
use App\Services\GeneralLedgerService;

$fiscalYear = auth()->user()->selectedBusinessUnit->currentFiscalYear;

$cashbook = app(GeneralLedgerService::class)->generateCashbook($fiscalYear);
```

## 返り値の見方

各行は次の項目を持ちます。

- `date`
  - 取引日
- `description`
  - 取引摘要
- `debit`
  - 借方金額。貸方行では `null`
- `credit`
  - 貸方金額。借方行では `null`
- `balance`
  - 対象勘定科目の残高推移

残高は、借方で加算、貸方で減算します。

## 補足

- 取得対象は、`FiscalYear` の開始日から終了日までの取引です
- 元帳は `transaction.date` の昇順で並びます
- 同じ日付の複数仕訳については、日付以外の順序は保証しません
- `Transaction.is_active` を明示的に除外する処理は、現時点の `GeneralLedgerService` にはありません
- `generateForSubAccount()` は補助科目単位で絞る派生 API です
- `generateCashbook()` は `現金` 勘定のショートカットです

## 参考

- `app/Services/GeneralLedgerService.php`
- `tests/Feature/GeneralLedgerServiceTest.php`
- [`manual/transactions.md`](transactions.md)
- [`manual/setup.md`](setup.md)
