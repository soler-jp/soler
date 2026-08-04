# OpeningBalanceRegistrationService TODO

## 目的

`App\Services\OpeningBalanceRegistrationService` は、開始残高の入力を受け取り、
期首仕訳の登録または改訂へ変換する上位サービスとして追加した。

現時点では、次の責務を持つ。

- 開始残高入力の正規化（asset_accounts / custom_asset_accounts / liability_accounts / custom_liability_accounts）
- 必要な custom account の作成（`BusinessUnit::addCustomAccount()`）
- 資産/負債の journal entries 組み立てと、元入金の差額計算
- 期首仕訳が未登録なら `OpeningEntryRegistrar::registerForRollover()` で新規登録
- 期首仕訳が既にあれば `TransactionRevisor::revise()` を通じて、対象科目の行と元入金を差し替え

`OpeningBalanceTodoHandler`（`todo_type = wizard_opening_balance`）が本サービスの現在唯一の呼び出し元。

## 将来的な統一方針

将来的には、開始残高に関わる入口をこのサービスへ集約する。

対象候補:

- 銀行口座の開始残高
- 事業用現金の開始残高
- 資産/負債 ToDo の開始残高
- 固定資産の carry forward 反映
- 棚卸資産の carry forward 反映
- CLI / Artisan command からの開始残高補正

## 目指す責務分離

- `OpeningBalanceRegistrationService`
  - 開始残高ユースケースの入口
  - 入力の解釈
  - ドメインルールの適用
  - 期首仕訳の差分組み立て

- `OpeningEntryRegistrar`
  - 低レベルな期首仕訳の登録部品
  - 現状は `register()` / `registerForRollover()` の単発登録のみ
  - 将来的には journal entries を受けた upsert（登録/改訂）の内部 API を持たせる

## なぜ今すぐ統一しないか

既存の `BankAccountRegistrationService` と `CashOnHandRegistrationService` は
すでに呼び出し元やテストが揃っており、現時点で一気に統合すると差分が大きくなる。

そのため、まずは `OpeningBalanceRegistrationService` を新しい入口として導入し、
将来的に既存サービスを段階的に移す。

## リファクタリング時の注意

- actor guard の責務を崩さない
- 既存の `TransactionRevisor` 利用パターンを維持する
- `OpeningEntryRegistrar` に UI 起点の入力解釈を持ち込まない
- 既存テストを壊さず、サービス統合ごとに小さく移行する
