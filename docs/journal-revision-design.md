# Journal Revision Design

このドキュメントは、登録済み仕訳の「修正」をどのような業務操作として扱うかを整理したものです。

ここでいう「仕訳の修正」は、既存 `Transaction` に紐づく `JournalEntry` 群のうち、主に金額または勘定科目を変更したいケースを対象にする。

## 目的

- 仕訳修正を `JournalEntry` 単体編集ではなく、`Transaction` 単位の業務操作として定義する
- 修正前の内容を監査可能な形で保持する
- 新規登録ロジックを再利用し、貸借不一致や所属不整合を防ぐ
- 対象外の取引種別を先に明示し、通常取引の修正仕様を先に固める

## 基本方針

初期実装では、仕訳修正は「既存取引の直接上書き」ではなく「改訂」として扱う。

- 旧 `Transaction` は履歴として残す
- 旧 `Transaction` は `deactivate()` で無効化する
- 修正後の内容は新しい `Transaction` として再登録する
- 新旧の `Transaction` は改訂元 ID で関連付ける

この方針では、見た目上は「1件の修正」でも、保存上は「旧版の無効化 + 新版の作成」になる。

## 修正対象

初期実装で許可する修正は次の 2 つに限定する。

- 金額の変更
- 勘定科目の変更

勘定科目変更は、実装上は `sub_account_id` の変更として扱う。

## 修正単位

修正単位は `JournalEntry` 1 行ではなく `Transaction` 全体とする。

- 借方のみを部分更新する API は持たない
- 貸方のみを部分更新する API も持たない
- 修正時は `Transaction` 本体と `journal_entries[]` をまとめて受け取り、再検証する

この理由は、1 行だけを独立編集すると、貸借一致・税区分・取引先整合が崩れやすいためである。

## 修正履歴

修正履歴は、更新前スナップショットの別保存ではなく、改訂チェーンで表現する。

想定する追加項目は次の通り。

- `transactions.revised_from_transaction_id`
  - この取引がどの取引の改訂版かを示す（新版に付き、旧版を指す）
  - unique インデックスを張り、「1 つの取引に対する改訂版は 1 つだけ」を DB レベルで保証する
- `transactions.revision_reason`
  - なぜ修正したかの短い理由（新版に付く）

履歴の読み方は次の通り。

- 現在有効な取引は `is_active = true`
- 修正前の取引は `is_active = false`
- 現在有効な版から `revised_from_transaction_id` を遡ると、旧い版へ改訂履歴をたどれる
- 旧版から新版を引くときは逆参照を使う（`hasOne` の revision リレーションを想定）

なお、無効化済み取引のうち「自分を `revised_from_transaction_id` で指す取引が存在するもの」は修正による無効化として区別できる。

存在しないものは、修正以外の理由で無効化された取引として区別できる。
たとえば削除・取消・クレジットカードの再読込による無効化が含まれる。

履歴画面でこの判定を使う場合も、「修正由来かどうか」の判定に留める。

## 入口

新規登録の `TransactionRegistrar` とは別に、修正専用の入口を持つ。

候補は次のどちらか。

- `App\Services\TransactionRevisor`
- `App\Models\Transaction::revise(...)`

現時点では、責務分離の観点から `TransactionRevisor` のような専用サービスを優先する。

`TransactionRegistrar` に更新責務まで混ぜない。

## Revisor の責務

修正専用のユースケース層は次を担当する。

- 修正対象 `Transaction` が修正可能か判定する
- 修正入力を検証する
- 旧 `Transaction` を無効化する
- 新規登録ロジックを使って改訂版 `Transaction` を作成する
- 新旧取引を `revised_from_transaction_id` で接続する
- すべてを 1 つの DB トランザクションにまとめる

### 並行修正の防止

同じ取引を同時に修正・削除されるケースに備え、次の 2 段構えで防ぐ。

- Revisor は DB トランザクション内で対象取引を `lockForUpdate()` で再取得し、`is_active = false` なら例外を投げる
  - 現在の `Transaction::deactivate()` は無効化済みなら黙って return するため、事前判定だけでは二重改訂を検出できない
- `revised_from_transaction_id` の unique インデックスを最終防壁とし、万一すり抜けても 2 つ目の改訂版作成を DB 制約で失敗させる

### 監査情報の記録

Revisor は操作者 `User` を受け取り、次のように記録する。

- 旧版: `deactivated_by` に操作者、`deactivation_reason` に固定文言（例: `修正による改訂`）を記録する
- 新版: `created_by` に操作者、`revision_reason` にユーザーが入力した修正理由を記録する

「なぜ修正したか」は新版の `revision_reason` が正であり、旧版の `deactivation_reason` は無効化の種別（修正・削除・予定取消）を示す用途に留める。

## 修正可能な取引

初期実装では、次の条件を満たす通常取引だけを修正可能とする。

- `is_active = true`
- `is_opening_entry = false`
- `is_planned = false`
- `is_adjusting_entry = false`
- `recurring_transaction_plan_id = null`
- `credit_card_import_batch_id = null`
- `DepreciationEntry` から参照されていない
- 所属する会計年度が決算済み（`is_closed = true`）でない

この条件により、通常入力で登録された本登録済みの取引だけを対象にする。

決算済み年度は `TransactionRegistrar` 側でも新版作成時に弾かれるが、そのエラーメッセージは新規登録の文脈のものになる。Revisor は事前判定で修正操作の文脈に合ったエラーを返す。

定期取引計画由来の取引は、本登録済み（`is_planned = false`）であっても初期実装では対象外とする。改訂版に `recurring_transaction_plan_id` を引き継ぐかどうか（計画との紐付け・再生成への影響）の整理が必要なためで、対象に含める場合は将来拡張で扱う。

## 初期実装で対象外にする取引

次の取引は、通常仕訳修正フローの対象外とする。

- 期首仕訳
- 予定取引
- 定期取引計画由来の取引（予定・本登録済みの両方）
- 減価償却計上仕訳
- 固定資産関連の特殊取引
- クレジットカード取込由来取引

これらは発生源ごとの業務ルールが強いため、必要なら別 API として扱う。

## 修正入力

初期実装の入力は、少なくとも次の shape を想定する。

```php
[
    'transaction' => [
        'revision_reason' => '金額入力ミスの修正',
    ],
    'journal_entries' => [
        [
            'sub_account_id' => 123,
            'type' => 'debit',
            'net_amount' => 1000,
            'tax_amount' => 100,
            'tax_type' => 'taxable_purchases_10',
        ],
        [
            'sub_account_id' => 456,
            'type' => 'credit',
            'net_amount' => 1100,
            'tax_amount' => 0,
            'tax_type' => 'non_taxable',
        ],
    ],
]
```

初期段階では、`date` `description` `remarks` `counterparty_id` などの修正は必須対象にしない。

修正対象外の属性は、Revisor が旧取引から改訂版へコピーする。

- `fiscal_year_id` `date` `description` `remarks` は旧取引の値をそのまま引き継ぐ
  - `TransactionRegistrar` の検証はこれらを必要とするため、入力になくても Revisor が補完する
- `counterparty_id` も旧取引から引き継ぐ
  - 引き継がないと取引先別集計から改訂版が漏れるため

### 伝票番号の扱い

改訂版には `entry_number` の採番フックにより新しい伝票番号が振られる。初期実装ではこれを許容する。

- 旧版の伝票番号は履歴としてそのまま残る
- 「1 件の修正」でも表示上の伝票番号は変わる
- 修正時期によっては日付順と番号順が一致しなくなるが、番号引き継ぎは採番ロジックの特例が必要になるため初期実装では行わない

## バリデーション方針

修正時も、新規登録時と同じ整合ルールを適用する。

- 補助科目が選択中事業体に属していること
- 借方・貸方が両方存在すること
- 総額ベースで貸借一致すること
- 税区分が会計年度の課税区分と矛盾しないこと

可能な限り `TransactionRegistrar` 側の正規化・検証ロジックを再利用する。

## 表示方針

通常一覧・集計・元帳では、改訂前の無効化済み取引は表示しない。

- 現在有効な改訂版だけを通常表示する
- 履歴画面または詳細画面でのみ旧版を表示する
- 元帳・集計は従来どおり `is_active = true` の取引だけを対象にする

## 監査上の意味

この設計では、修正は「過去データの破壊」ではなく「新しい版の採用」として残る。

したがって、次を追跡しやすい。

- 修正前にどの内容で登録されていたか（旧版の `journal_entries`）
- いつ無効化されたか（旧版の `deactivated_at`）
- だれが修正したか（旧版の `deactivated_by` / 新版の `created_by`）
- どの理由で修正したか（新版の `revision_reason`）

## 将来拡張

今後の拡張候補は次の通り。

- 日付・摘要・取引先も修正対象に含める
- 修正差分の比較表示を追加する
- 期首仕訳や減価償却仕訳にも専用の修正 API を設ける
- 定期取引計画由来の本登録済み取引を修正対象に含める（`recurring_transaction_plan_id` の引き継ぎ方針を整理した上で）
- `source_type` を追加して、取引種別判定を明確化する
- 修正回数や最終改訂版を高速に引くための補助カラムを追加する

## まとめ

初期実装では、仕訳修正を「通常取引に限定した改訂機能」として扱う。

- 修正内容は金額と勘定科目に絞る
- 修正単位は `Transaction` 全体とする
- 修正履歴は旧版無効化と新版作成で残す
- 修正対象外の取引種別は別ユースケースへ分離する

この方針により、削除機能と修正機能を同じ「状態遷移 + 履歴保持」の流儀で統一できる。
