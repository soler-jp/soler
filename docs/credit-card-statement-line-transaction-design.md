# Credit Card Statement Line Transaction Design

このドキュメントは、`CreditCardStatementLine` から `Transaction` を登録するフローの設計方針を整理したものです。

CSV取込そのものと、明細レビュー後の会計登録を分離しつつ、既存の `TransactionRegistrar` を正規ルートとして再利用することを目的とする。

## 目的

- `CreditCardStatementLine` から安全に `Transaction` を登録できるようにする
- 取引登録の保存ロジックを `TransactionRegistrar` に集約したまま拡張する
- 明細レビュー状態と会計登録状態の整合を崩さないようにする
- 再取込時の無効化フローと矛盾しないようにする

## 適用範囲

このドキュメントは次を対象とする。

- `CreditCardStatementLine` からの単一 `Transaction` 登録
- `Transaction` 登録後の `CreditCardStatementLine` 状態更新
- `CreditCard` 設定からの既定貸方科目解決
- 会計年度解決と登録可否判定

このドキュメントは次を対象外とする。

- 画面UI詳細
- 自動仕訳ルール
- 複数明細を1つの `Transaction` に束ねる機能
- 返金・取消明細の最終仕様

## 基本方針

### 1. `TransactionRegistrar` を唯一の登録入口に保つ

- `CreditCardStatementLine` 起点の登録でも、最終的な永続化は `TransactionRegistrar` を通す
- `Transaction::create()` や `JournalEntry::create()` を直接呼ぶ専用実装は増やさない
- 貸借一致、所属整合、税額正規化、取引先解決は既存ルールを再利用する

### 2. `CreditCardStatementLine` には薄い入口だけを置く

- `CreditCardStatementLine` に `registerTransaction(...)` のような public メソッドを置くのは許容する
- ただし、その中で重い業務ロジックを完結させない
- 実処理は専用サービスへ委譲する

### 3. ユースケース本体は専用サービスに置く

- `App\Services\CreditCardStatementLineRegistrar` のような専用サービスを導入する
- このサービスが、明細行ロック、登録可否判定、会計年度解決、`TransactionRegistrar` 呼び出し、レビュー完了更新を担当する

### 4. 初期実装での訂正手段は「登録取消 + 再登録」とする

- カード明細由来の `Transaction` には `credit_card_import_batch_id` を必ず持たせる
- そのため、初期実装では `TransactionRevisor` の改訂対象外になる
- 借方科目や税区分の誤登録は、まず `CreditCardStatementLine` 側で登録取消し、その後あらためて登録し直す運用とする
- 将来、カード明細由来取引を改訂対象に含める場合は別設計で扱う

この責務分離により、モデルは読みやすさを維持しつつ、業務ロジックはテストしやすいサービスへ閉じ込める。

## 想定 API

### モデル側

`CreditCardStatementLine` には薄い委譲メソッドを置く。

```php
public function registerTransaction(
    array $attributes,
    ?User $user = null,
    ?CreditCardStatementLineRegistrar $registrar = null,
): Transaction
```

役割:

- 呼び出し側から見た自然な入口を提供する
- 既定ではコンテナから `CreditCardStatementLineRegistrar` を解決する
- 実際の登録ロジックは持たない
- 呼び出し後は `$this->refresh()` した最新状態を前提に扱う

登録取消の入口も `CreditCardStatementLine` に置く。

```php
public function cancelTransactionRegistration(
    ?User $user = null,
    ?string $reason = null,
    ?CreditCardStatementLineRegistrar $registrar = null,
): void
```

### サービス側

ユースケース本体は専用サービスに置く。

```php
public function register(
    CreditCardStatementLine $line,
    ?User $user,
    array $attributes,
): Transaction
```

`$attributes` の初期想定:

```php
[
    'debit_sub_account_id' => 123,
    'tax_type' => 'taxable_purchases_10',
    'description' => 'Amazon 文具購入',
    'remarks' => 'カード明細から登録',
]
```

補足:

- 呼び出し側に `'debit'` のような曖昧な文字列だけを渡させない
- 必要な入力は「借方をどう登録するか」という業務上の意味で渡す
- 貸方科目は通常 `CreditCard` 設定から解決する
- `business_ratio` は借方が費用科目のときだけ受け付ける
- 借方が費用科目でない場合は `business_ratio` を渡さない
- 初期実装では、呼び出し側は原則 `tax_type` を明示指定する
- 将来、年度や事業者区分に応じて省略可能にする場合は別途UI仕様とあわせて整理する

登録取消の本体も同じサービスに置く。

```php
public function cancelRegistration(
    CreditCardStatementLine $line,
    ?User $user,
    ?string $reason = null,
): void
```

## 処理フロー

### 1. 対象明細を排他取得する

- DBトランザクション内で `CreditCardStatementLine` を `lockForUpdate()` 付きで再取得する
- 同時レビューや二重登録を防ぐ

### 2. 登録可能か検証する

少なくとも次を満たす必要がある。

- `is_active = true`
- `status = unreviewed`
- `transaction_id = null`
- `credit_card_import_batch_id != null`
- 親 `CreditCardImportBatch` が active
- 親 `CreditCardStatement` 配下の有効行である
- `amount > 0`

違反時は、レビュー操作の文脈に沿った例外を返す。

認可:

- `$user` が存在する場合は、そのユーザーが対象 `CreditCard` の `businessUnit` に属することをサービス側でも検証する
- UI や Livewire 側の認可だけに依存しない

### 3. カード設定を解決する

- `CreditCardStatementLine -> statement -> creditCard` を辿る
- `defaultCreditSubAccountId()` を使って既定貸方科目を決める

初期方針:

- `business` カードは `liability_sub_account_id`
- `personal` カードは `owner_draw_sub_account_id`

既定貸方科目が設定されていない場合は登録不可とする。

### 4. 取引日と会計年度を解決する

取引日決定ルール:

- 第一候補は `used_on`
- 第二候補は `posted_on`
- どちらも無い場合は登録不可

会計年度解決ルール:

- カードの `businessUnit` に属する会計年度から、取引日を含む年度を特定する
- 見つからない場合は登録不可
- 該当年度が決算済みなら登録不可

補足:

- `TransactionRegistrar` 自身も `null` 年度と決算済み年度を拒否する
- ここでの事前判定は、レビュー操作の文脈で分かりやすい例外を早めに返すための重複チェックとして扱う

### 5. `TransactionRegistrar` に渡す入力へ変換する

`transactionData` の初期方針:

- `date`
- `description`
- `remarks`
- `created_by`
- `credit_card_import_batch_id`
- `counterparty_name` または `counterparty_id`

`journalEntriesData` の初期方針:

- 借方
  - `sub_account_id = debit_sub_account_id`
  - `type = debit`
  - `gross_amount = line.amount`
  - `tax_type`
  - 借方が費用科目のときだけ `business_ratio`
- 貸方
  - `sub_account_id = creditCard.defaultCreditSubAccountId()`
  - `type = credit`
  - `net_amount = line.amount`

摘要の既定値:

- `attributes['description']` があれば優先
- なければ `merchant_name`
- それもなければ `description`

`description` / `remarks` の使い分け:

- `description` には、帳簿表示や一覧表示でそのまま使う会計上の摘要を保存する
- `description` は、利用者が最終的に確定した短い要約として扱う
- `remarks` には、クレジットカード明細の原文や補足情報を保存する
- `remarks` は監査・見直し用の出典情報として扱う

初期方針:

- `description` は `attributes['description']` を優先し、未指定なら `merchant_name` または `CreditCardStatementLine.description` から組み立てる
- `remarks` には、必要に応じて `merchant_name` や `CreditCardStatementLine.description` など元明細情報を格納する
- 元明細情報は `remarks` に複写してよいが、正本はあくまで `CreditCardStatementLine` とその relation に残る

relation との役割分担:

- 元データの参照元は `creditCardStatementLines()` relation とする
- ただし、`Transaction` 単体でも帳簿上の意味が完結するよう、`description` は relation 依存にしない
- `remarks` も relation の完全代替ではなく、主要な原文情報を補助的に保持するために使う
- そのため、初期実装では「relation だけを見て摘要を復元する」設計は採らない

取引先の既定値:

- `attributes['counterparty_id']` があれば優先
- なければ `attributes['counterparty_name']`
- それ以外は取引先未設定のまま登録する

補足:

- `merchant_name` はCSV由来の生文字列であり、表記揺れした `Counterparty` を量産しやすい
- 初期実装では `merchant_name` を自動で `counterparty_name` に昇格させない
- 将来、自動仕訳ルールや正規化辞書を導入する場合は別設計として扱う

家事按分の補足:

- 借方が費用科目で `business_ratio < 100` の場合、`TransactionRegistrar` 側で家事按分行が追加生成される
- そのため、呼び出し側の入力が「借方1行・貸方1行」でも、保存結果の `JournalEntry` は増えることがある

### 6. 登録成功後に明細行を更新する

登録された `Transaction` を受けて、対象 `CreditCardStatementLine` を次の内容で更新する。

- `transaction_id`
- `status = registered`
- `reviewed_by`
- `reviewed_at`

必要であれば `memo` も同時更新する。

補足:

- `CreditCardStatement` の状態は `CreditCardStatementLine` から導出されるため、ここで明示更新しない
- 呼び出し側が更新済みの明細状態を使う場合は、`CreditCardStatementLine` を `refresh()` して扱う

`?User $user` の扱い:

- Web文脈では、呼び出し側が明示しない場合でも `auth()->user()` を渡す方針を優先する
- CLI や内部バッチなど、操作者が存在しない文脈では `null` を許容する
- `null` の場合、`Transaction.created_by` と `CreditCardStatementLine.reviewed_by` はどちらも `null` になる
- この挙動は許容仕様として扱う

### 7. 登録取消

誤登録のやり直し用に、`registered` 行を `unreviewed` へ戻す専用フローを持つ。

対象条件:

- `is_active = true`
- `status = registered`
- `transaction_id != null`
- `credit_card_import_batch_id != null`

処理:

- 対象行を `lockForUpdate()` 付きで再取得する
- 紐づく `Transaction` を `deactivate()` する
- `CreditCardStatementLine` を次の状態へ戻す
  - `status = unreviewed`
  - `transaction_id = null`
  - `reviewed_by = $user?->id`
  - `reviewed_at = now()`
  - 必要であれば `memo` に取消理由を反映する

補足:

- 初期実装では「登録取消」がカード明細由来取引の訂正手段になる
- `Transaction` は物理削除せず、無効化で扱う
- `registered -> unreviewed` の逆遷移はこのフローでのみ許可する

### 8. 全体を1トランザクションにまとめる

- 明細ロック
- 登録可否検証
- `TransactionRegistrar` 実行
- `CreditCardStatementLine` 更新

これらを同一の `DB::transaction()` 内で扱う。

## `CreditCardStatementLine` に置かない責務

次はモデル本体に直接書かない。

- `lockForUpdate()` を含む排他制御
- 会計年度の探索
- `TransactionRegistrar` への入力組み立て
- レビューエラーメッセージの組み立て
- 将来の返金・取消ルール分岐

理由:

- Eloquentモデルの責務を肥大化させないため
- 同じ登録処理をCLI・Livewire・Controller・将来のAPIで再利用しやすくするため
- Feature test と service test を書きやすくするため

## 状態遷移

初期実装では、登録フローに関係する `CreditCardStatementLine.status` の遷移は次の通り。

- `unreviewed -> registered`
- `registered -> unreviewed`
  - 登録取消時のみ

初期実装では次の状態からの登録は許可しない。

- `private`
- `duplicate`
- `ignored`
- `registered`

将来、「`private` へした行を後から登録する」要件が出た場合は、専用の再レビュー仕様として別途整理する。

## 再取込との整合

既存設計では、`CreditCardImportBatch` 無効化時に関連 `Transaction` も無効化する。

そのため、`CreditCardStatementLine` から登録された `Transaction` には、必ず元の `credit_card_import_batch_id` を引き継ぐ。

これにより再取込時は:

- 旧 batch が inactive になる
- 旧 line が inactive になる
- 旧 line 由来の `Transaction` も inactive になる

という一貫した無効化が成立する。

運用上の意味:

- 再取込すると、その batch 配下で行ったレビュー結果や登録結果は失われる
- たとえば登録済み行も、新しいCSV取込後は新しい `CreditCardStatementLine` 側で再レビューが必要になる
- 初期実装では `fingerprint` によるレビュー結果引き継ぎは行わない
- 将来、再取込前後でレビュー状態を引き継ぐ場合は別設計とする

決算済み年度との関係:

- 既存の `Transaction::deactivate()` は決算済み年度の取引を拒否する
- そのため、決算済み年度に属する登録済み取引を含む batch は再取込を拒否する方針とする
- 一部だけをスキップして再取込を続行する仕様は採らない
- 失敗時は取込全体をロールバックする

## 返金・負数明細・0円明細

`amount < 0` の扱いは初期実装の論点として明示的に保留する。

候補:

- 借貸を反転して登録する
- 別の専用登録フローにする
- 初期実装では登録不可にする

`amount = 0` も同様に初期実装では登録不可とする。

現時点では、通常の正の支出明細を先に通し、負数明細と0円明細は後続設計で扱う方が安全である。

## 例外方針

利用者入力やレビュー状態に起因する登録不可は、初期実装では `ValidationException` に寄せる。

対象例:

- inactive 行
- 二重登録
- batch_id なし
- 会計年度未解決
- 決算済み年度
- 貸方科目未設定
- 0円明細
- 非費用科目への `business_ratio` 指定

`InvalidArgumentException` は、内部契約違反やプログラミングミスに近いケースへ限定する。

## テスト方針

最低限、次のFeature testを追加する。

- 未レビューの明細から `Transaction` を登録できる
- 登録後に `status=registered` と `transaction_id` が保存される
- `credit_card_import_batch_id` が `Transaction` に引き継がれる
- 貸方科目がカード設定から解決される
- `used_on` が属する会計年度へ登録される
- `used_on` が `null` のとき `posted_on` にフォールバックして会計年度解決できる
- inactive 行は登録できない
- すでに `registered` の行は二重登録できない
- 貸方科目未設定カードでは登録できない
- 会計年度が見つからない場合は登録できない
- 該当会計年度が決算済みなら登録できない
- `personal` カードでは貸方が `owner_draw_sub_account_id` に解決される
- 借方が費用科目で `business_ratio < 100` のとき家事按分行が生成される
- 借方が非費用科目のとき `business_ratio` を渡すと登録できない
- `credit_card_import_batch_id = null` の行は登録できない
- `amount = 0` の行は登録できない
- `registered` 行の登録取消で `unreviewed` と `transaction_id = null` に戻る
- 決算済み年度の登録済み取引を含む batch は再取込できない

`lockForUpdate()` を前提とするため、少なくとも次のケースは `mysql` グループのテストでも検証する。

- 二重登録防止
- 登録取消と同時登録の競合
- 並行レビュー時の排他

必要に応じて、将来の負数明細や `private` 再登録は別テスト群に分ける。

## 実装順

1. `CreditCardStatementLineRegistrar` を追加する
2. `CreditCardStatementLine` に薄い `registerTransaction()` を追加する
3. Feature test を追加する
4. 呼び出し側の Livewire / Controller を接続する

## 結論

`CreditCardStatementLine` に「取引化の入口」を置く方針自体は妥当である。

ただし、`makeTransaction('debit')` のようにモデルが直接すべてを抱える形ではなく、次の分割を採る。

- モデル:
  - 薄い public API
- サービス:
  - 実処理本体
- 登録処理:
  - `TransactionRegistrar` を再利用

この方針が、既存アーキテクチャとの整合、将来拡張、テスト容易性のバランスが最もよい。
