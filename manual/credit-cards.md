# クレジットカードを扱う

この manual では、`CreditCard` の作成と、クレジットカード明細CSVの取込方法を説明します。

現時点では、画面操作ではなくアプリケーションコンテキスト内からサービスを呼ぶ前提です。

## 関連モデル

- `CreditCard`
  - カード設定を表す
  - `parser_key` で利用する CSV parser を決める
- `CreditCardStatement`
  - 請求月単位の明細ヘッダ
- `CreditCardStatementLine`
  - 明細1行ごとの原本
- `CreditCardImportBatch`
  - 何のCSVをいつ取り込んだかの履歴
- `Transaction`
  - 明細レビュー後に会計登録された結果

## `CreditCard` を作成する

`CreditCard` は `BusinessUnit` に属します。

主に決める項目は次の通りです。

- `name`
  - 管理用のカード名
- `issuer_name`
  - 発行会社名
- `network`
  - `visa` / `mastercard` / `jcb` など
- `last_four`
  - 下4桁
- `ownership_type`
  - `business` または `personal`
- `parser_key`
  - どのCSV parser で読むか
- `liability_sub_account_id`
  - 事業用カード向けの既定貸方補助科目
- `owner_draw_sub_account_id`
  - 個人用カード向けの既定貸方補助科目

```php
use App\Models\CreditCard;

$creditCard = $businessUnit->createCreditCard([
    'name' => '事業用オリコ',
    'issuer_name' => 'Orico',
    'network' => 'visa',
    'last_four' => '9876',
    'ownership_type' => CreditCard::OWNERSHIP_TYPE_BUSINESS,
    'parser_key' => 'orico_csv_v1',
    'liability_sub_account_id' => $cardLiabilitySubAccount->id,
    'is_active' => true,
], auth()->user());
```

## `ownership_type` の使い分け

- `business`
  - 事業用カード
  - 既定の貸方補助科目は通常 `liability_sub_account_id`
- `personal`
  - 個人カード
  - 既定の貸方補助科目は通常 `owner_draw_sub_account_id`

`CreditCard::defaultCreditSubAccountId()` は、この `ownership_type` に応じて既定の貸方補助科目を返します。

## `parser_key` の使い方

- `CreditCard.parser_key` に応じて parser が選ばれます
- 対応済み parser は `orico_csv_v1` / `aeon_csv_v1` / `rakuten_csv_v1` / `generic_csv_v1` です
- `generic_csv_v1` は CSV 内容からカード会社別 parser を自動判別します

### 形式が一致しない場合

- `CreditCard.parser_key` が `orico_csv_v1` / `aeon_csv_v1` / `rakuten_csv_v1` の場合は、その形式と一致するCSVだけを受け付けます
- たとえば ORICO カード設定に AEON の CSV を渡すと、`import()` は例外を投げます
- 例外メッセージには、実際に渡された形式が含まれます

例:

- `カード設定のCSV形式と一致しません。渡された形式は aeon_csv_v1 形式です。`

- `generic_csv_v1` の場合だけは、実CSV形式を自動判別して取り込みます
- 未対応形式や判別不能なCSVを渡した場合も、`import()` は例外を投げます

## 明細CSVを取り込む

入口は `CreditCardImportService` です。

```php
use App\Models\CreditCard;
use App\Services\CreditCardImport\CreditCardImportService;

$creditCard = CreditCard::findOrFail($creditCardId);
$csvContents = file_get_contents($csvPath);

$batch = app(CreditCardImportService::class)->import(
    $creditCard,
    $csvContents,
    basename($csvPath),
    auth()->user(),
    [
        // CSVだけでは請求月や請求日を確定できない場合に補完する
        'statement_year' => 2026,
        'statement_month' => 7,
        'billed_on' => '2026-07-27',
        'paid_on' => '2026-08-27',
        'period_start_on' => '2026-06-01',
        'period_end_on' => '2026-06-30',
    ],
);
```

## 取込結果

取込が成功すると、次が保存されます。

- `CreditCardStatement`
  - `credit_card_id + statement_year + statement_month` 単位で作成または更新
- `CreditCardImportBatch`
  - 取込元ファイル名、ハッシュ、利用 parser、件数を保持
- `CreditCardStatementLine`
  - CSVの各明細行を原本として保存

補足:

- 取り込まれた明細行は、同一CSV内で内容が同じでも全件 `unreviewed` で保存されます
- `fingerprint` は行内容と出現順から作られます
- 同じ請求月を再取込すると、旧 `CreditCardImportBatch` とその batch 由来の明細・取引は無効化されます

## 明細から `Transaction` を登録する

CSV取込の次の段階では、`CreditCardStatementLine` から `Transaction` を登録できます。

入口は `CreditCardStatementLine::registerTransaction()` です。

```php
use App\Models\JournalEntry;

$transaction = $statementLine->registerTransaction([
    'debit_sub_account_id' => $expenseSubAccount->id,
    'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
    'description' => 'Amazonで文房具購入',
], auth()->user());
```

### 何がどこで行われるか

- `CreditCardStatementLine::registerTransaction()`
  - モデル側の薄い入口です
- `CreditCardStatementLineRegistrar`
  - 明細の登録可否判定
  - `used_on` / `posted_on` からの取引日解決
  - 取引日に対応する `FiscalYear` 解決
  - カード設定からの既定貸方補助科目解決
  - 登録後の `CreditCardStatementLine` 更新
- `TransactionRegistrar`
  - 最終的な `Transaction` / `JournalEntry` の検証と保存

つまり、`TransactionRegistrar` は保存コアであり、カード明細固有の前処理は `CreditCardStatementLineRegistrar` が担当します。

### `description` と `remarks`

- `description`
  - 帳簿表示に使う会計上の摘要
- `remarks`
  - カード明細の原文や補足情報

元明細の正本は `CreditCardStatementLine` に残しつつ、`Transaction` 単体でも意味が通るように `description` と `remarks` へ必要な情報を保存します。

### 取消してやり直す

初期実装では、カード明細由来の取引は改訂機能ではなく「登録取消して再登録」で訂正します。

```php
$statementLine->cancelTransactionRegistration(
    auth()->user(),
    '誤登録のためやり直し',
);
```

これにより、紐づく `Transaction` は無効化され、明細行は `unreviewed` に戻ります。

## 年またぎ明細の運用

クレジットカード明細は、請求月ではなく `used_on` の年度で扱います。

たとえば、

- 利用日が `2024-12-28`
- 請求月が `2025-01`
- CSVを取り込むのが `2025-01` 末や `2025-02`

というケースでも、会計上は `2024` 年度の取引として登録します。

つまり、「2025年1月請求の明細」の中に、「2024年度に属する取引」が混ざることがあります。

### 締め前にやること

年度を締める前に、その年度に属するカード明細の取込とレビューを完了させてください。

特に注意が必要なのは、12月利用分が翌年1月請求で届くカードです。

この場合は、

- 1月請求だから翌年度の明細として後回しにする

のではなく、

- `used_on` が12月なら前年度の明細として締め前に確認する

必要があります。

### なぜ先に終わらせる必要があるか

同じ請求月のCSVを再取込すると、古い `CreditCardImportBatch` と、その batch 由来の `Transaction` をまとめて無効化してから新しいCSVへ置き換えます。

ただし、決算済み年度に属する `Transaction` は無効化できません。

そのため、

- 年またぎの明細を取り込んで登録した
- その取引が前年度に属している
- 前年度を締めた
- 後から同じ請求月の修正版CSVを再取込したい

という順序になると、再取込が失敗します。

### 実運用の前提

現時点では、次の運用を前提にしています。

- 年度を締める前に、その年度に属するカード明細の取込を終える
- 年度を締める前に、その年度に属するカード明細のレビューと登録を終える
- 年またぎの請求月は、`used_on` が前年に属する明細を締め前確認の対象に含める
- 締めた後は、その batch の再取込はできない場合がある

つまり、「カードの請求月」で締め確認するのではなく、「明細の利用日がどの年度に属するか」で締め前確認するのが基本です。

## override が必要な場面

カード会社によっては、CSVだけでは請求月や請求日を一意に決められないことがあります。

その場合は `import()` の第5引数で補完します。

- `statement_year`
- `statement_month`
- `billed_on`
- `paid_on`
- `period_start_on`
- `period_end_on`

たとえば楽天カードCSVのように、ファイル内に支払月しかなく請求年が確定できない場合は、`statement_year` を外から渡します。

## 例外になる主なケース

次のような場合、`CreditCardImportService::import()` は例外を投げ、途中データは保存しません。

- `CreditCard.parser_key` と実CSV形式が一致しない
- 未対応の `parser_key` を指定している
- 未対応形式または判別不能なCSVを渡した
- 必須ヘッダや必須列が壊れている
- 金額や日付を parser が読めない
- 再取込時に、旧 batch に紐づく `Transaction` が決算済み年度に属していて無効化できない

## 未実装

現時点では、次はまだ実装していません。

- HTTP のファイルアップロード受付
- Livewire / 画面からのアップロード
- Storage への原本ファイル保存

そのため、現時点では「CSV文字列をサービスに渡すところ」は CLI や別の呼び出し元で用意する前提です。

## 参考

- [app/Models/CreditCard.php](../app/Models/CreditCard.php)
- [app/Models/CreditCardStatement.php](../app/Models/CreditCardStatement.php)
- [app/Models/CreditCardStatementLine.php](../app/Models/CreditCardStatementLine.php)
- [app/Models/CreditCardImportBatch.php](../app/Models/CreditCardImportBatch.php)
- [app/Services/CreditCardImport/CreditCardImportService.php](../app/Services/CreditCardImport/CreditCardImportService.php)
- [app/Services/CreditCardStatementLineRegistrar.php](../app/Services/CreditCardStatementLineRegistrar.php)
- [tests/Feature/CreditCardImportServiceTest.php](../tests/Feature/CreditCardImportServiceTest.php)
- [tests/Feature/CreditCardStatementLineRegistrarTest.php](../tests/Feature/CreditCardStatementLineRegistrarTest.php)
