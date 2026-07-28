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

$creditCard = CreditCard::create([
    'business_unit_id' => $businessUnit->id,
    'name' => '事業用オリコ',
    'issuer_name' => 'Orico',
    'network' => 'visa',
    'last_four' => '9876',
    'ownership_type' => CreditCard::OWNERSHIP_TYPE_BUSINESS,
    'parser_key' => 'orico_csv_v1',
    'liability_sub_account_id' => $cardLiabilitySubAccount->id,
    'is_active' => true,
]);
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
- [tests/Feature/CreditCardImportServiceTest.php](../tests/Feature/CreditCardImportServiceTest.php)
