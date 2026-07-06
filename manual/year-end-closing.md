# 期末処理（決算締め）

このマニュアルは、会計年度を締めて翌期へ繰り越すまでの期末処理の手順をまとめたものです。

この手順が前提とする API の設計は `docs/fiscal-year-closing-design.md` にまとめています（未実装のものを含みます）。

## 期末処理の流れ

期末処理は次の順で行います。

1. 予定取引の整理
2. 減価償却の計上
3. 棚卸の振替（棚卸資産がある場合）
4. 締め前チェック
5. 締め
6. 翌期繰越

1〜3 は仕訳を作る操作、4〜5 は検証と状態遷移だけの操作、6 は翌期の期首仕訳を作る操作です。

## 前提

- 締め対象の `FiscalYear` が存在すること
- その年度の取引の入力が終わっていること

## 1. 予定取引の整理

締める年度に未処理の予定取引（`is_planned = true`）が残っていると締められません。

実際に発生した予定取引は確定し、発生しなかったものは取消します。

### code例

```php
use App\Services\PlannedTransactionConfirmer;

$planned = $fiscalYear->transactions()
    ->active()
    ->where('is_planned', true)
    ->get();

// 発生していた場合: 確定する
app(PlannedTransactionConfirmer::class)->confirm(
    $transaction,
    $user,
    $overrides,
    $journalEntriesData,
);

// 発生しなかった場合: 取消する
$transaction->deactivate($user, '予定取消');
```

## 2. 減価償却の計上

その年度に償却が発生する固定資産の償却仕訳を計上します。

### code例

```php
use App\Services\DepreciationService;

$service = app(DepreciationService::class);

// その年度の償却予定を準備する（作成済みならスキップされます）
$service->prepareEntriesFor($fiscalYear);

// 未計上の償却予定を取引として計上する
foreach ($fiscalYear->businessUnit->depreciatingFixedAssets($fiscalYear) as $fixedAsset) {
    $entry = $fixedAsset->depreciationEntries()
        ->where('fiscal_year_id', $fiscalYear->id)
        ->whereNull('transaction_id')
        ->first();

    if ($entry === null) {
        continue;
    }

    $service->registerTransactionFor($entry);
}
```

## 3. 棚卸の振替

棚卸資産がある事業の場合、期末の実地棚卸高を決算整理仕訳として振り替えます。

実装上は、棚卸専用のサービス（`InventoryClosingService` 想定）から登録する前提です。

`期首商品（棚卸高）` と `期末商品（棚卸高）` は、帳簿上は在庫そのものではなく売上原価調整に使う科目として扱います。したがって勘定タイプは `expense` 前提です。

期首棚卸高は手入力せず、その年度の期首時点の `棚卸資産` 帳簿残高から導出する想定です。入力が必要なのは期末の実地棚卸高だけです。

- 期首分: 借方 `期首商品（棚卸高）` / 貸方 `棚卸資産`（期首の帳簿棚卸高）
- 期末分: 借方 `棚卸資産` / 貸方 `期末商品（棚卸高）`（期末の実地棚卸高）

### 「期首」の振替も決算時に登録する

期首分の振替は、名前に反して期首（1/1）ではなく、期末分とあわせて決算時に登録します。「期首」は仕訳を登録するタイミングではなく、金額が期首時点の在庫額を表すことを指す名前です。

決算時に登録する理由は、売上原価の計算構造にあります。

```
売上原価 = 期首商品棚卸高 + 仕入金額 − 期末商品棚卸高
```

- 期中は、商品を売っても原価の仕訳はせず、仕入れた金額を `仕入金額` に積むだけにします
- 売上原価は「期首の在庫」と「期末に残った在庫」の両方が揃って初めて確定するため、期首分・期末分は 2 本セットの決算整理仕訳として期末日付で登録します
- もし期首分を 1/1 付で登録すると、まだ何も売っていない期中の損益集計に前年の在庫額が費用として現れてしまいます（1 月の Summary が期首棚卸高分のマイナスから始まる）

期中の在庫は `棚卸資産`（asset）が残高として持ち続け、貸借対照表に載ります。`期首商品（棚卸高）` / `期末商品（棚卸高）` は在庫そのものではなく、決算時に売上原価を確定させるための計算用科目です。

### 期首棚卸高は前年から繰り越された金額

期首棚卸高は前年の期末実地棚卸高と一致します。ただし `期首商品（棚卸高）` という科目が翌期へ繰り越されるのではなく、金額は `棚卸資産` の残高として翌期繰越（手順 6）が運びます。

```
【N年 決算】
  期末分: 借方 棚卸資産 400 / 貸方 期末商品（棚卸高） 400
  → N年末の 棚卸資産 残高 = 期末実地棚卸高 400

【翌期繰越（N年 → N+1年）】
  期首仕訳: 借方 棚卸資産 400 / 貸方 元入金（の一部）
  ※ 期首商品・期末商品は損益科目のため繰り越さない（残高ゼロで開始）

【N+1年 決算】
  期首時点の 棚卸資産 残高 400 を導出して振り替える
  期首分: 借方 期首商品（棚卸高） 400 / 貸方 棚卸資産 400
```

`期首商品（棚卸高）` に仕訳が入るのは翌年の決算時が初めてで、期中はずっと残高ゼロのままです。初年度（前年データがない場合）は、翌期繰越の代わりにセットアップウィザードの期首仕訳が `棚卸資産` の期首残高を作ります。

### code例

期末実地棚卸高は `棚卸資産` 配下の SubAccount ごとに渡します（`[SubAccount ID => 期末実地棚卸高]`）。このとき、`棚卸資産` 配下の全 SubAccount について 0 を含めて明示入力が必要です。未入力は validation error になります。

```php
use App\Services\InventoryClosingService;

$inventory = $fiscalYear->businessUnit->getAccountByName('棚卸資産');

app(InventoryClosingService::class)->registerFor(
    $fiscalYear,
    [
        $inventory->subAccounts()->where('name', '棚卸資産')->value('id') => 0,
        $inventory->subAccounts()->where('name', '商品')->value('id') => 500_000,
        $inventory->subAccounts()->where('name', '製品')->value('id') => 150_000,
        $inventory->subAccounts()->where('name', '材料')->value('id') => 0,
    ],
);
```

SubAccount を分離していない場合は、既定の `棚卸資産` SubAccount 1 つを指定すれば十分です。

```php
app(InventoryClosingService::class)->registerFor(
    $fiscalYear,
    [$inventory->subAccounts()->where('name', '棚卸資産')->value('id') => 500_000],
);
```

### SubAccount 単位で振り替える

`棚卸資産` を `商品` / `製品` / `材料` などに分離している場合、振替も SubAccount 単位で行います。これにより各補助科目の貸借対照表残高が実地棚卸高と一致します。損益科目（`期首商品（棚卸高）` / `期末商品（棚卸高）`）側は集計科目のため合算 1 行にまとめます。

- 期首棚卸高は SubAccount ごとに期首仕訳の `棚卸資産` 残高から導出します（手入力は期末分だけ）
- 期末棚卸高は全 SubAccount について 0 を含めて渡します。未入力はエラーです
- `0` を渡した SubAccount は「期末 0（売り切り）」として扱われ、期首残高があれば期首分だけ振り替えます

決算整理仕訳には `is_adjusting_entry = true` を付けます。通常の仕訳修正（改訂）の対象外になるため、誤って登録した場合は `deactivate()` で無効化して登録し直します。

棚卸の振替は減価償却など他の決算整理仕訳と区別できるよう、`adjusting_entry_type = inventory_closing` を持たせています。上のサービスは、期首分・期末分の必要な仕訳を組み立てて登録する責務を持ちます。

## 4. 締め前チェック

締められる状態かをチェックリストで確認します。

### code例

```php
use App\Services\FiscalYearCloser;

$result = app(FiscalYearCloser::class)->validate($fiscalYear);
```

返り値は次の形です。

```php
[
    'closable' => false,
    'errors' => [
        ['key' => 'planned_transactions_remaining', 'count' => 2],
    ],
    'warnings' => [
        ['key' => 'inventory_transfer_missing'],
    ],
]
```

- `errors` が 1 件でもあると締められません
- `warnings` は確認を促すだけで、締めは可能です

エラーが出た場合は、該当する手順（1〜3）に戻って解消します。

減価償却の検証は、単に「未計上の `DepreciationEntry` があるか」だけではなく、「償却対象固定資産に対してその年度の `DepreciationEntry` が存在し、かつ記帳済みか」を見る前提です。

- `DepreciationEntry` 自体が存在しないものは「未準備」としてエラーになります
- `DepreciationEntry` はあるが `transaction_id` がないものは「未計上」としてエラーになります
- 締め前チェック自体は read-only で、内部で `prepareEntriesFor()` を呼んで自動補完しない想定です

## 5. 締め

チェックがすべて通ったら年度を締めます。

### code例

```php
use App\Services\FiscalYearCloser;

app(FiscalYearCloser::class)->close($fiscalYear, $user);
```

締めると次の状態になります。

- `is_closed = true`、`closed_at` / `closed_by` が記録される
- 新規取引の登録、仕訳の改訂、予定取引の確定・取消ができなくなる

## 6. 翌期繰越

締めた年度の貸借対照表科目の期末残高から、翌年度の期首仕訳を自動生成します。

収益・費用は翌期へ個別には持ち越さず、その年度の `当期所得` として `元入金` に組み替えます。

棚卸振替に使う `期首商品（棚卸高）` / `期末商品（棚卸高）` も損益科目として扱うため、翌期へは繰り越しません。

事業主貸・事業主借・当期所得は翌期の `元入金` に組み替えられます。

```
翌期の元入金 = 当期の元入金 + 事業主借 − 事業主貸 + 当期所得
```

### code例

```php
use App\Services\FiscalYearRollover;

$nextFiscalYear = $businessUnit->fiscalYears()
    ->where('year', $fiscalYear->year + 1)
    ->firstOrFail();

$openingTransaction = app(FiscalYearRollover::class)
    ->rollover($fiscalYear, $nextFiscalYear);
```

生成される期首仕訳は `OpeningEntryRegistrar` の期首仕訳と同じ形式（1 伝票、`is_opening_entry = true`）です。

## 制約

- 締めた年度は再オープンできません（初期実装では締め解除を提供しません）
- 繰越元にできるのは締め済みの年度だけです
- 翌年度にすでに期首仕訳がある場合、繰越はできません（期首仕訳は 1 年度 1 伝票のため）
- 締めた年度の誤りに後から気づいた場合は、当期（開いている年度）で修正仕訳を登録して対応します
- 締めは翌年度を自動作成しません。必要なら別途 `createFiscalYear()` で翌年度を用意します

## 補足

- 家事按分は入力時に仕訳が分割されているため、期末処理には家事按分の作業はありません（`docs/household-allocation-design.md`）
- 月次締めを運用している場合、締め前チェックに全月の月次締め完了が加わる想定です（月次締めは別マニュアルで扱います）
