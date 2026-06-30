# Ledger Design

このドキュメントは、元帳出力の責務、現在の実装、そして設計上のレビュー観点を整理するためのメモである。

`manual/ledger.md` が利用方法を案内するのに対し、このドキュメントは `GeneralLedgerService` と関連モデルの責務を扱う。

## 目的

- 元帳をどのクラスが返すかを明確にする
- `Account` と `SubAccount` の違いを元帳の観点で整理する
- 既存テストで固定できている挙動を記録する
- 実装レビューで気になった点を残す

## 現状の入口

元帳の入口は `App\Services\GeneralLedgerService` である。

現在の公開 API は次の 3 つ。

- `generate(Account $account, FiscalYear $fiscalYear): array`
- `generateForSubAccount(SubAccount $subAccount, FiscalYear $fiscalYear): array`
- `generateCashbook(FiscalYear $fiscalYear): array`

`manual/ledger.md` でも案内する通り、利用側は `FiscalYear` を起点にした表示用途として使う。

## 責務

### `GeneralLedgerService`

このサービスは、保存済みの仕訳を閲覧用の配列へ変換する。
`Transaction` 1件を1行として返すのではなく、元帳行を `JournalEntry` 1件ごとに返す。

責務は次の通り。

- 勘定科目または補助科目に紐づく仕訳を取得する
- 指定年度に属する取引だけへ絞る
- 日付順に並べる
- 借方・貸方・残高の表示形式へ整形する

このサービスは、仕訳を作成しない。

### `Account`

`Account` は勘定科目単位の元帳入口になる。

`journalEntries()` は `hasManyThrough` で `SubAccount` を経由して `JournalEntry` を返す。

### `SubAccount`

`SubAccount` は補助科目単位の元帳入口になる。

`generateForSubAccount()` はこのモデルを起点にする。

### `Transaction`

`Transaction` は元帳の並び順と表示情報の供給元になる。

- `date`
- `description`

元帳の残高計算自体は `GeneralLedgerService` が担う。

## 現在の実装

`generate()` と `generateForSubAccount()` の処理はほぼ同じで、次の流れになっている。

1. 対象の `journalEntries()` を取得する
2. `transaction` を eager load する
3. `FiscalYear.start_date` から `FiscalYear.end_date` までに絞る
4. `transaction.date` で昇順に並べる
5. 借方なら残高を加算
6. 貸方なら残高を減算
7. 元帳配列へ変換する

`generateCashbook()` は `BusinessUnit` の `現金` 勘定を探し、見つかれば `generate()` を呼ぶ。

## テストで固定されていること

- 借方行は `debit` に値が入り、`credit` は `null` になる
- 貸方行は `credit` に値が入り、`debit` は `null` になる
- 残高は借方で加算、貸方で減算される
- 勘定科目ごとに元帳を取得できる
- 補助科目単位でも元帳を取得できる
- 指定年度外の仕訳は元帳に含まれない
- 仕訳がない場合は空配列を返す
- `generateCashbook()` は `現金` 勘定の仕訳だけを返す

## 実装レビュー

### 1. `generate()` と `generateForSubAccount()` の重複が大きい

両メソッドは取得元が違うだけで、整形ロジックは同じである。

今後の改善候補は次のいずれか。

- 共通の private メソッドへ切り出す
- 対象クエリだけを注入するヘルパーに分解する

現状は読みにくさよりも単純さを優先しているが、拡張時に二重修正が起きやすい。

### 2. 並び順は `transaction.date` のみ

同一日付の複数仕訳については、現在の実装では安定した順序を保証していない。

実務上、伝票番号や `entry_number` を併用したい場面があるなら、次のどちらかを検討したい。

- `transaction.entry_number` を第二キーにする
- `transaction.id` を第二キーにする

### 3. `generateCashbook()` は `現金` という名称に依存する

現時点では `現金` 勘定を探している。

これはシンプルだが、名称変更や別名運用に弱い。

将来の代替案:

- 勘定科目タイプや定数で参照する
- `BusinessUnit` 側でキャッシュ系アカウントを返す専用メソッドを持つ

### 4. `Transaction.is_active` を明示的に除外していない

現状の `GeneralLedgerService` は、`JournalEntry` と `Transaction.date` を条件にしているだけで、`is_active` を見ていない。

そのため、無効化済み取引を元帳に出したくないなら、追加フィルタが必要になる。

この点は、`Transaction::deactivate()` と元帳の可視性をどう結びつけるかという設計判断になる。

### 5. `Transaction::getCreditAccountsLabelAttribute()` は元帳と近い責務を持つ

`Transaction` には貸方補助科目名をまとめるアクセサがある。

これは一覧表示には便利だが、`journalEntries` を毎回読むため、呼び出し側が eager load していないと N+1 を誘発しやすい。

元帳表示側でも同じパターンを使うなら、表示用の query 設計を別途固定した方がよい。

## モデルレビュー

### `Transaction`

良い点:

- `entry_number` と `display_number` があり、一覧表示がしやすい
- `deactivate()` が明示されていて、無効化の入口が分かりやすい
- `is_planned` と `is_active` が状態として分離されている

気になる点:

- `booted()` の採番が `lockForUpdate()` に依存しており、トランザクション外からの利用時は意図を読み取りづらい
- `booted()` で `\Exception` を投げているので、アプリケーション側で例外型を揃えたいなら見直し余地がある
- `getCreditAccountsLabelAttribute()` は表示用として便利だが、責務が増えすぎないか注意が必要

### `JournalEntry`

良い点:

- `type` と税区分の定数が揃っていて、入力の意味が明確
- `grossAmount` アクセサがあり、税額込み表示がしやすい

気になる点:

- `account` はアクセサで間接参照しているため、元帳や一覧で大量に読むときは eager load 方針を決めた方がよい
- `is_effective` は現時点の元帳には使っていないため、将来の用途が未確定なら責務を明示したい

### `Account` / `SubAccount`

良い点:

- `Account` を起点に `journalEntries()` をたどれるので、元帳の入口として自然
- `SubAccount` は軽量で、補助科目単位の集計に向く

気になる点:

- `Account::deleting()` で `subAccounts()->delete()` を呼んでいるため、関連削除の副作用が大きい
- `Account::journalEntries()` は `hasManyThrough` で分かりやすいが、扱う側が「勘定科目配下の全補助科目の仕訳」を意識しないと範囲が広くなりやすい

### `FiscalYear`

`FiscalYear` は元帳そのものを返さないが、対象期間を決める境界として重要である。

元帳の設計では次を前提にしている。

- 開始日と終了日で期間を切る
- `FiscalYear` の期間外は元帳に含めない

## まとめ

現在の元帳実装は、表示用途としては十分に単純で、テストでも基本挙動が固定されている。

一方で、次の 3 点は将来の拡張前に整理したい。

1. 同日付の並び順
2. 無効化済み取引の扱い
3. `generate()` / `generateForSubAccount()` の重複

必要なら次の段階で、現金出納帳だけを先に UI へ載せるか、汎用元帳画面を先に作るかを決める。
