# OpeningBalanceRegistrationService TODO

## 目的

`App\Services\OpeningBalanceRegistrationService` は、開始残高の入力を受け取り、
期首仕訳の登録または改訂へ変換する上位サービスとして追加した。

現時点では、次の責務を持つ。

- 開始残高入力の正規化
- 必要な custom account の作成
- 資産/負債/元入金の journal entries 組み立て
- 新規期首仕訳の登録
- 既存期首仕訳の改訂

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
  - journal entries を受けた登録/改訂の内部 API

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
