# FiscalYear Design

このドキュメントは、`FiscalYear` の責務と、現在の年度集計仕様、および次に修正したい点を整理するためのメモである。

`docs/transaction-registration.md` が取引登録時の正規化を扱うのに対し、このドキュメントは保存後の集計と、その入口としての `FiscalYear` を扱う。

## 目的

- `FiscalYear` が何を返すモデルなのかを明確にする
- 現在の年度集計 API の到達点を記録する
- 次にどこを拡張・整理したいかを明確にする

## 前提

取引登録時の責務は `TransactionRegistrar` にある。

- 入力で受けた `gross_amount` を `net_amount` と `tax_amount` に正規化する
- `tax_type` を含めて `JournalEntry` に保存する
- 保存後の総額は `net_amount + tax_amount` から導出できる

そのため `FiscalYear` は、保存済みの `JournalEntry` を前提に年度集計を行う。

## FiscalYear の責務

現時点では、`FiscalYear` は次を責務とする。

- 年度に属する取引と仕訳の集計入口を提供する
- 実績と予定を分けて集計できる
- 売上と経費について年度集計できる
- 損益計算書に必要な総額ベースのサマリーを返せる
- 売上と経費について `net_amount`、`tax_amount`、`gross_amount` を返せる

## FiscalYear が持つ判断軸

`FiscalYear` は少なくとも次の属性を持つ。

- `is_taxable`
- `is_tax_exclusive`

### `is_taxable`

`is_taxable` は、課税事業者かどうかを表す。

現時点では、年度集計ロジックの主要分岐にはまだ使っていない。

今後の用途候補は次の通り。

- 税務集計の分岐
- 見なし税額と通常税額の扱いの分岐
- 集計結果の整合性チェック

### `is_tax_exclusive`

`is_tax_exclusive` は既存カラム名として保持する。

ただし現時点では、将来拡張のために用意されているフラグとして扱い、実際の年度集計ロジックでは使用しない前提とする。

現在サポート対象とするのは次のみ。

- `is_tax_exclusive = false`
  - 税込経理

`is_tax_exclusive = true` の扱いは未実装であり、このドキュメントでは将来課題として扱う。

## 現在の公開 API

### `calculateSummary(): array`

総額ベースの損益サマリーを返す。

返り値:

```php
[
    'actual' => [
        'total_income' => 0,
        'total_expense' => 0,
        'profit' => 0,
    ],
    'planned' => [
        'total_income' => 0,
        'total_expense' => 0,
        'profit' => 0,
    ],
]
```

ここでの `total_income` と `total_expense` は、税込経理前提で `net_amount + tax_amount` を使って集計した値である。

### `calculateAmountSummary(): array`

売上と経費の年度集計元データを返す。

返り値:

```php
[
    'actual' => [
        'sales' => [
            'net_amount' => 0,
            'tax_amount' => 0,
            'gross_amount' => 0,
        ],
        'expenses' => [
            'net_amount' => 0,
            'tax_amount' => 0,
            'gross_amount' => 0,
        ],
    ],
    'planned' => [
        'sales' => [
            'net_amount' => 0,
            'tax_amount' => 0,
            'gross_amount' => 0,
        ],
        'expenses' => [
            'net_amount' => 0,
            'tax_amount' => 0,
            'gross_amount' => 0,
        ],
    ],
]
```

このメソッドは、次の軸でデータを分ける。

- 実績 / 予定
- 売上 / 経費
- `net_amount` / `tax_amount` / `gross_amount`

## 現在の集計単位

年度集計は少なくとも次の軸で切り分けている。

- 実績
  - `Transaction.is_planned = false`
- 予定
  - `Transaction.is_planned = true`

また、集計対象は勘定科目属性と仕訳区分で決めている。

- 売上
  - `account.type = revenue`
  - `journal_entries.type = credit`
- 経費
  - `account.type = expense`
  - `journal_entries.type = debit`

加えて、`Transaction.is_active = true` の取引だけを集計対象にする。

- `is_active = false` の取引は履歴として保持する
- 履歴保持された `JournalEntry` が残っていても、年度集計には含めない
- 集計ロジックは `JournalEntry` ではなく親 `Transaction` の active 状態で判定する

## 現在の実装方針

### 1. 集計の入口は `FiscalYear`

`FiscalYear` は公開 API を持ち、呼び出し側は年度モデルから集計を始める。

### 2. 集計処理の詳細は別クラスへ切り出す

実際の集計クエリは `App\Services\FiscalYearSummaryCalculator` に置く。

`FiscalYear` は入口を持ち、calculator が実際の集計処理を担当する。

### 3. `calculateSummary()` は既存互換を維持する

ダッシュボードなど既存利用箇所の互換を壊さないため、総額ベースの損益サマリーは `calculateSummary()` として残している。

### 4. `calculateAmountSummary()` は集計の元データを返す

`calculateAmountSummary()` は、損益計算の元になる売上・経費の `net_amount` / `tax_amount` / `gross_amount` を返す。

現時点では、`profit` はこのメソッドには含めていない。

## 現在の限界

現時点の設計には次の限界がある。

- `tax_amount` は単純合計であり、見なし税額と通常税額を区別していない
- 仕入れはまだ集計対象に含めていない
- `is_taxable` に応じた税区分整合性チェックを持っていない
- `is_tax_exclusive = true` の集計仕様を持っていない

## 次に修正したいこと

次の修正候補は、優先順としては以下を想定する。

### 1. `tax_amount` の意味を整理する

今の `calculateAmountSummary()` の `tax_amount` は、保存されている税額の単純合計である。

そのため、次の区別ができていない。

- 課税事業者の通常税額
- 免税事業者の見なし税額

今後は、少なくとも次のどちらかに進めたい。

- `tax_amount` を「保存税額合計」として明確に位置づける
- `stored / deemed / reportable` のように分けて返す

### 2. `is_taxable` と税区分の整合性チェックを追加する

想定しているビジネスルールは次の通り。

- `is_taxable = true` の年度では、`deemed_*` が混ざってはいけない
- `is_taxable = false` の年度では、通常の `taxable_*` が混ざってはいけない

このチェックは、まず保存時に担保するのが本筋だが、`FiscalYear` 側でも年度データの監査手段を持たせたい。

### 3. 仕入れの集計ルールを決める

`sales` と `expenses` は現在集計できるが、`purchases` はまだ未定義である。

次に決める必要があるのは次の点である。

- どの勘定科目を仕入れとして扱うか
- 税区分との関係をどう扱うか
- 売上・経費と同じ shape で返すか

### 4. `is_tax_exclusive = true` を本当にサポートするか決める

今は税込経理前提のみサポートしている。

将来、本体額ベース集計まで扱うなら、次を別途固定する必要がある。

- `calculateSummary()` の基準金額
- `calculateAmountSummary()` の返り値との整合
- UI や帳票での見せ方

## テストで現在固定していること

- `calculateSummary()` の既存返り値 shape が壊れない
- 実績と予定が引き続き分かれて返る
- 総額ベース集計で既存と同じ結果になる
- `calculateAmountSummary()` が売上・経費の `net_amount` / `tax_amount` / `gross_amount` を返せる
- 複数の実績売上・実績経費・予定売上・予定経費を合算できる
- `inactive` にした実績取引が年度集計に含まれない
- `inactive` にした予定取引が年度金額集計に含まれない

## 非目標

このドキュメントでは次を扱わない。

- 取引登録画面の入力UI
- `gross_amount` の永続化
- 消費税申告額そのものの確定ロジック
