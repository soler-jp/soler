# Fixed Asset Design

このドキュメントは、固定資産および減価償却機能の初期設計案を整理するためのメモである。

現時点では `FixedAsset`、`DepreciationEntry`、対応 migration、`DepreciationService` の基礎実装は存在するが、責務分離と残ユースケースの定義はまだ不十分である。

このドキュメントでは、まず初版で扱うユースケースを固定し、そのうえでモデル責務と見直し方針を明確にする。

## 目的

- 固定資産機能の初版スコープを定義する
- モデル責務を整理する
- 取得、償却計算、記帳、除却の境界を明確にする
- 次のチャットでも設計議論を継続できる状態にする

## 現状認識

既存コードには次の要素がある。

- `fixed_assets`
  - 税抜取得価額、消費税額、償却基礎額、耐用年数、償却方法、除却関連カラムを持つ
- `depreciation_entries`
  - 年度ごとの償却金額と `Transaction` への記帳参照を持つ
- `DepreciationService`
  - `registerFixedAsset()` は一部実装済み
  - `registerTransactionFor()` は単票記帳まで実装済み
  - `prepareEntriesFor()` は未実装

一方で、現状には次の問題がある。

- 固定資産登録が `TransactionRegistrar` を経由していない
- `DepreciationEntry` に計算用カラムと記帳状態カラムが混在しており、最小責務がまだ固まりきっていない
- 取得、年度償却、除却がまだユースケースとして分解されていない

## 現時点の前提

現時点では、初版の対象を車両運搬具に限定する。

- 勘定科目は `車両運搬具` のみを扱う
- 対象資産は次の2種類のみ
  - `new_standard_car`（新車-普通車）
  - `new_light_car`（新車-軽自動車）
- 耐用年数は固定値とする
  - `new_standard_car`: 6年
  - `new_light_car`: 4年
- `business_usage_ratio` は固定資産登録時の見込み値として扱う
- 減価償却方法は初版では `straight_line` のみ対応する

この方針では、ユーザー入力を絞る。

- 車種区分
- 税抜取得価額
- 取得時の消費税額
- 購入日
- 事業利用割合（見込み値）

これ以外の値は、原則としてシステム側で自動決定する。

## 初版で扱うユースケース

初版では、以下の6ユースケースを対象候補とする。

### 1. 車両を取得登録する

- 事業年度を選ぶ
- 車種区分、取得日、税抜取得価額、消費税額、事業利用割合を入力する
- 耐用年数と償却方法は車種区分から自動決定する
- 固定資産台帳を登録する
- 同時に取得仕訳を作成する

成果物:

- `FixedAsset` 1件
- 取得仕訳の `Transaction` 1件

### 2. 固定資産台帳を一覧・詳細表示する

- 所有中または除却済みの固定資産を確認する
- 税抜取得価額、消費税額、取得総額、累計償却額、帳簿価額、除却状態を表示する
- 年度別の償却履歴を参照できるようにする

### 3. 年度末の減価償却候補を計算する

- 対象年度を指定する
- 償却対象の固定資産を抽出する
- 当年度の償却月数と償却額を計算する
- まだ記帳しない状態で `DepreciationEntry` を作成または再計算する

### 4. 減価償却を確定記帳する

- 未記帳の `DepreciationEntry` を確認する
- 1件ずつ仕訳化する
- `TransactionRegistrar` を通して減価償却仕訳を作成する

### 5. 固定資産情報を修正する

- 資産名や資産区分など、台帳情報を修正する
- すでに償却済み年度へ影響する項目は制限する
- 再計算可能な項目と、記帳後は固定すべき項目を分ける

### 6. 固定資産を売却・除却する

- 売却日または除却日を登録する
- 売却額、入金先、損益科目を指定する
- 売却・除却仕訳を作成する
- 以後の償却対象から除外する

## モデル責務の提案

### `FixedAsset`

固定資産台帳の親モデルとする。

責務:

- 取得時点の資産情報を保持する
- 資産の現在状態を保持する
- 除却済みかどうかを判定する
- ある年度で償却対象かどうかを判定する
- 累計償却額、未償却残高、帳簿価額を導出する

保持したい主な情報:

- 所属事業体
- 資産科目
- 資産名
- 車種区分
- 取得日
- 税抜取得価額
- 取得時の消費税額
- 償却基礎額
- 耐用年数
- 償却方法
- 事業利用割合（見込み値）
- 除却状態

補足:

- `acquisition_cost` は保存カラムとしては持たず、`taxable_amount + tax_amount` から導出する
- 現行実装では `FixedAsset` のアクセサとして `acquisition_cost` を参照できる

### `DepreciationEntry`

各年度の償却計算結果を保持する明細モデルとする。

責務:

- 対象年度の償却月数を保持する
- 普通償却額を保持する
- 特別償却額を保持する
- 必要経費算入額を保持する
- 未記帳 / 記帳済みを判定する
- 対応する会計取引を参照する

このモデルは「仕訳明細」ではなく「年度償却明細」である。

役割のイメージ:

- `DepreciationEntry`
  - 償却予定または償却計算結果
- `Transaction`
  - 実際の記帳結果

未記帳の `DepreciationEntry` は見込み値ベースの償却予定であり、記帳直前に当年度の実績値へ更新する。

したがって、流れは次の通りである。

1. `FixedAsset` を登録する
2. 取得初年度の `DepreciationEntry` を見込み値で作成する
3. 継続年度は期初処理で `DepreciationEntry` を見込み値で作成する
4. 期末に当年度 `DepreciationEntry` の事業利用割合を実績値へ更新する
5. 実際に仕訳を起こすと `Transaction` を作成する
6. `DepreciationEntry` に、どの `Transaction` で記帳したかを紐づける

### `DepreciationEntry` の算出と表示対応

`DepreciationEntry` は年度ごとの償却明細であり、表示ラベルごとに参照元を固定する。

#### 算出ルール

- `償却の基礎になる金額`
  - `FixedAsset.acquisition_cost` を使う
  - つまり `taxable_amount + tax_amount`
- `償却率`
  - `12 / FixedAsset.useful_life_months` を小数第3位で丸める
  - 例: `72ヶ月 -> 0.167`
- `本年中の償却期間`
  - 取得年度の取得月から会計年度末までの月数
  - 翌年度以降は原則12ヶ月
- `本年分の普通償却費`
  - `償却の基礎になる金額 × 償却率` を年額として丸めたうえで、月額に直し、本年中の償却期間を掛ける
- `本年分の償却費合計`
  - 初版では `本年分の普通償却費` と同じ
- `事業専用割合`
  - `FixedAsset` 登録時の見込み値を使う
- `本年分の必要経費算入額`
  - `本年分の償却費合計 × 事業専用割合`
- `未償却残高(期末残高)`
  - `償却の基礎になる金額 - 本年分の普通償却費`

#### ラベルと取得元の対応表

| 表示ラベル | 取得元 |
| --- | --- |
| 減価償却資産の名称等 | `FixedAsset.name` |
| 面積または数量 | `1` |
| 取得年月 | `FixedAsset.acquisition_date` の年月 |
| 償却の基礎になる金額 | `FixedAsset.acquisition_cost` |
| 耐用年数 | `FixedAsset.useful_life_months` を年換算した表示値 |
| 償却率 | `12 / FixedAsset.useful_life_months` |
| 本年中の償却期間 | `DepreciationEntry.months` |
| 本年分の普通償却費 | `DepreciationEntry.ordinary_amount` |
| 本年分の償却費合計 | `DepreciationEntry.total_amount` |
| 事業専用割合 | `DepreciationEntry.business_usage_ratio` |
| 本年分の必要経費算入額 | `DepreciationEntry.deductible_amount` |
| 未償却残高(期末残高) | `FixedAsset.acquisition_cost - DepreciationEntry.ordinary_amount` |

### `Transaction`

取得仕訳、償却仕訳、売却・除却仕訳の会計イベント本体とする。

固定資産機能側では直接 `Transaction::create()` せず、既存方針どおり `TransactionRegistrar` を入口にする。

### `JournalEntry`

仕訳明細は既存の責務を維持する。

固定資産・減価償却機能は、必要な借方・貸方データを組み立て、`TransactionRegistrar` に渡す。

## モデル間の関係

想定する関係は次の通り。

- `BusinessUnit` 1 : N `FixedAsset`
- `FixedAsset` 1 : N `DepreciationEntry`
- `FiscalYear` 1 : N `DepreciationEntry`
- `Transaction` 1 : N `JournalEntry`
- `DepreciationEntry` N : 1 `Transaction` または 1 : 1 `Transaction`

ここで重要なのは、減価償却の記帳参照先を `JournalEntry` ではなく `Transaction` に寄せる案である。

## カラム見直し案

### `fixed_assets`

既存の主要カラムは活かしつつ、次を見直し候補とする。

初版の最小構成案は次の通り。

- `id`
- `business_unit_id`
- `account_id`
- `asset_category`
  - `new_standard_car`
  - `new_light_car`
- `name`
- `acquisition_date`
- `taxable_amount`
- `tax_amount`
- `depreciation_base_amount`
- `useful_life_years`
- `useful_life_months`
- `depreciation_method`
- `business_usage_ratio`
- `is_disposed`
- `disposed_at`
- `created_at`
- `updated_at`

補足:

- `account_id` は当面 `車両運搬具` 固定になる
- `asset_type` から `useful_life_years`、`useful_life_months`、`depreciation_method` を自動設定する
- `name` は自由入力でもよいが、初期値として車種区分名を入れてもよい
- `business_usage_ratio` は初年度の見込み値入力および継続年度の初期値候補として使う
- `acquisition_cost` は保存せず、必要時に `taxable_amount + tax_amount` から導出する

現状のコードおよびマイグレーションでは `asset_category` を使用している。

### `depreciation_entries`

現状:

- `transaction_id` を使って `Transaction` に紐づける
- `fixed_asset_id + fiscal_year_id` には一意制約を付ける
- `transaction_id = null` を未記帳の見込み、値ありを記帳済みの確定とみなす
- 必要なら `posted_at` を追加する余地がある

現状の実装では、1資産について1年度に1つの償却計算結果を持ち、その結果が1つの取引に対応する構造になっている。

初版の最小構成案は次の通り。

- `id`
- `fixed_asset_id`
- `fiscal_year_id`
- `months`
- `ordinary_amount`
- `business_usage_ratio`
- `deductible_amount`
- `transaction_id`
- `created_at`
- `updated_at`

補足:

- `special_amount` は初版では不要
- `business_usage_ratio` は `DepreciationEntry` 側に持ち、未記帳の間は見込み値、記帳前の期末更新で実績値に置き換える
- 見込み値は予測用であり、過去の見込み履歴は別保存しない
- `transaction_id = null` なら未記帳の見込み、値があれば記帳済みの確定と判定できる

## 初版の業務ルール案

初版では複雑さを抑えるため、以下に絞る。

- 対象勘定科目は `車両運搬具` のみとする
- 対象資産は `new_standard_car` と `new_light_car` のみとする
- 償却方法は `straight_line` のみ対応する
- 月割り償却を行う
- 特別償却は未対応とする
- 事業利用割合は固定資産登録時の見込み値を使い、期末に当年度 Entry 上で実績値へ更新する
- 売却・除却は第二段階に後ろ倒ししてもよい

### 過去年度資産の登録

固定資産を登録する会計年度と取得年度が異なる場合（過去年度取得資産を現在年度で登録する場合）、取得年度から登録年度までの全年度分の `DepreciationEntry` を一括作成する。

- 取得年度の Entry は月割り計算を行う
- 翌年度以降は12ヶ月分で計算する
- DBに登録されていない中間年度はスキップする（Entry を作成しない）
- この処理は `registerFixedAsset()` 内で自動的に行われる

## 会計処理で先に決めるべき論点

次の論点は実装前に確定が必要である。

### 1. 貸方科目をどうするか

選択肢:

- `減価償却累計額` を使う
- 資産勘定を直接減額する

現状実装では後者を採用し、既存の固定資産勘定を直接貸方に立てている。

### 2. 税込取得時の償却基礎額

将来的には `FiscalYear.is_taxable` / `is_tax_exclusive` に応じて税抜 / 税込を分ける余地がある。

ただし、現時点でサポートしているのは免税事業者・税込経理のみである。

そのため現行実装では、固定資産の入力値は `taxable_amount` と `tax_amount` に分けて保持しつつ、
`depreciation_base_amount` は実質的に税込取得総額を基準として計算している。

### 3. 売却・除却を初版に含めるか

取得登録と年度償却までを先に出し、売却・除却は次段階に分ける案がある。

## サービス責務の分割案

現状の `DepreciationService` には複数責務が入りうるため、次のように分割する案がある。

- `FixedAssetAcquisitionService`
  - 固定資産取得登録
  - 取得仕訳の作成
- `DepreciationCalculationService`
  - 年度ごとの償却候補計算
  - `DepreciationEntry` の作成 / 再計算
- `DepreciationPostingService`
  - 償却仕訳の作成
- `FixedAssetDisposalService`
  - 売却・除却処理

クラスを分けずに1サービスに残す場合でも、少なくとも public API はユースケース単位で明確に分けるべきである。

## 初版の実装優先順

1. `FixedAsset` を車両限定の台帳モデルとして整理する
2. `DepreciationEntry` を年度償却明細として整理する
3. 固定資産取得登録を `TransactionRegistrar` 経由に統一する
4. 年度償却計算ユースケースを整理・実装する
5. 償却単票記帳ユースケースの責務を固定する
6. 売却・除却を次段階で追加する

## 次に詰めるべきこと

次のチャットでは、以下を具体化するのが自然である。

- 初版で採用する会計ルール
- `fixed_assets` と `depreciation_entries` の不要カラム整理
- 償却月数の計算ルール
- `Transaction` の貸方科目方針
- `FixedAsset` / `DepreciationEntry` の責務を前提にしたテストケース
