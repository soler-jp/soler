# Transaction Diff Design

このドキュメントは、旧版 `Transaction` と新版 `Transaction` の差分を、表示・監査・要約生成に再利用できる形で取得する設計を整理したものです。

## 目的

- `Transaction` 改訂時に、旧版と新版の差分を安定して取得できるようにする
- 単純属性と `journal_entries` の差分を同じ入口で扱えるようにする
- 監査ログ UI や改訂確認 UI で再利用できる shape を先に固定する
- 比較ロジックを `Transaction` モデル本体から分離し、責務を増やしすぎない

## 前提

- 仕訳修正は「旧版無効化 + 新版作成」の改訂として扱う
- 旧版と新版は別 `Transaction` レコードであり、`journal_entries.id` は基本的に引き継がれない
- したがって、`journal_entries` の比較は ID ベースではなく、内容ベースの対応付けが必要になる

## 位置付け

この比較機能は、永続化や認可の責務ではなく、**差分表現**の責務を持つ。

- `Transaction` モデルに複雑な比較ロジックは載せない
- `TransactionRevisor` にも表示向け比較ロジックは持たせない
- 比較は専用の値オブジェクトまたは専用サービスに外出しする

## API 方針

`$transactionA->diff($transactionB)` のようなモデルメソッドは採用しない。

理由:

- 左辺・右辺のどちらが主語か曖昧になりやすい
- old/new の向きが毎回確認事項になる
- `Transaction` モデルに表示寄りの責務が増える

初期方針では、専用の比較入口を持つ。

```php
$diff = app(TransactionDiffer::class)->diff($oldTransaction, $newTransaction);
```

初期実装では **`TransactionDiffer` のみを作る**。`TransactionDiff` ファサードは、呼び出し箇所が増えて static 入口の需要が明確になった時点で追加する。

`TransactionDiffer` のシグネチャは次を基本とする。

```php
public function diff(Transaction $old, Transaction $new): TransactionDiffResult;
```

将来 `TransactionDiff::between()` を置く場合も、`TransactionDiffer` への委譲専用ファサードに留める。

## 引数の向き

引数の順序は必ず **`old, new`** に固定する。

- 第1引数: 比較元（旧版）
- 第2引数: 比較先（新版）

返り値の属性差分は常に `[old, new]` の 2 要素配列で表現する。

```php
[
    'date' => ['2026-08-01', '2026-08-03'],
    'description' => ['文房具', '文房具の購入'],
]
```

この向きは `audit-log-design.md` の `changes.subject` shape と揃える。

## 返り値

返り値は配列ではなく、専用の結果オブジェクトとする。

例:

```php
final class TransactionDiffResult
{
    public function hasChanges(): bool;

    /** @return array<string, array{0: mixed, 1: mixed}> */
    public function subjectChanges(): array;

    /** @return array<string, array{0: mixed, 1: mixed}> */
    public function derivedChanges(): array;

    /** @return array<string, array{created: array<int, array>, updated: array<int, array>, deleted: array<int, array>}> */
    public function relatedChanges(): array;
}
```

返り値のトップレベル shape は、監査ログの `changes` と揃えて次の 2 系統に固定する。

- `subject`: `Transaction` 本体の差分
- `related`: `journal_entries` など関連の差分

`derived` は表示補助の派生値であり、`subject` / `related` と同列の監査 shape ではない。監査ログ互換を意識する経路では、`derived` は保存対象に含めない。

`hasChanges()` は次のいずれかが 1 件でもあれば `true` を返す。

- `subjectChanges()` が空でない
- `relatedChanges()` に `created` / `updated` / `deleted` のいずれかがある

`derivedChanges()` だけでは `true` にしない。`derived` は `subject` / `related` から再計算可能な補助情報に限定するためである。

## subject に含める項目

初期実装では、比較対象を明示的に限定する。dirty 属性の総当たり比較はしない。

候補:

- `date`
- `description`
- `remarks`
- `counterparty_id`
- `business_ratio`
- `is_planned`

`is_active` は初期対象から外す。

- 改訂では旧版無効化が常に発生し、差分として毎回現れる
- 改訂の事実は `transaction.revised` というイベントで既に表現される
- 比較結果に毎回含めるとノイズになりやすい

`revision_reason` も初期対象から外す。

- 旧版との差分というより、新版にだけ付与される改訂メタデータである
- 「何をどう修正したか」の差分本体とは性質が異なる

`revision_reason` のような改訂メタデータは、差分結果の外側で別途扱う。

## derived に含める項目

表示や要約の補助として比較したい派生値は `subject` に混ぜず、`derived` に分ける。

候補:

- `total_amount`
- `debit_entry_count`
- `credit_entry_count`

`derived` の値も `[old, new]` の形に揃える。

```php
[
    'total_amount' => [1000, 1100],
]
```

`derived` は新旧 `Transaction` の `journal_entries` から再計算可能な補助情報に限る。ここへ永続化前提の業務情報を入れない。

## related に含める項目

初期実装では `journal_entries` のみを対象にする。

```php
[
    'journal_entries' => [
        'created' => [...],
        'updated' => [...],
        'deleted' => [...],
    ],
]
```

各項目の shape は次の通り。

### created

新版にのみ存在する行。

```php
[
    [
        'attributes' => [
            'type' => 'debit',
            'sub_account_id' => 123,
            'tax_type' => 'taxable_purchases_10',
            'gross_amount' => 1100,
            'net_amount' => 1000,
            'tax_amount' => 100,
            'business_ratio' => 100,
        ],
    ],
]
```

### updated

旧版と新版で対応付く行のうち、内容が変わったもの。

```php
[
    [
        'before' => [
            'type' => 'debit',
            'sub_account_id' => 123,
            'tax_type' => 'taxable_purchases_10',
            'gross_amount' => 1000,
        ],
        'after' => [
            'type' => 'debit',
            'sub_account_id' => 123,
            'tax_type' => 'taxable_purchases_10',
            'gross_amount' => 1100,
        ],
        'changes' => [
            'gross_amount' => [1000, 1100],
        ],
    ],
]
```

`updated.changes` の各値も `subject` / `derived` と同じく `[old, new]` で統一する。`old` / `new` の連想配列は採用しない。

### deleted

旧版にのみ存在する行。

```php
[
    [
        'attributes' => [
            'type' => 'credit',
            'sub_account_id' => 456,
            'tax_type' => 'non_taxable',
            'net_amount' => 1000,
        ],
    ],
]
```

## JournalEntry の対応付け

`journal_entries.id` は比較キーに使わない。

初期実装では、次の正規化キーで対応付ける。

- `type`
- `sub_account_id`
- `tax_type`

この 3 つが一致する行同士を、同一グループ候補として扱う。

`type` をキーに含めるため、借方から貸方への変更、または貸方から借方への変更は `updated` ではなく `created + deleted` として表現される。

この判断を採る理由:

- 借方と貸方は会計上の役割が異なり、同じ行の単純更新として扱うより、片側削除と反対側追加の方が意味に忠実
- 誤って 1:1 対応するより、粗めでも意味を裏切らない差分を優先する

同一キーが複数行ある場合は、その中で次の順に比較する。

- `gross_amount`
- `net_amount`
- `tax_amount`
- `business_ratio`

完全一致するものから先に消し込む。その後の規則は次の通り。

1. 同一キーグループ内で、旧行数と新行数が同数なら、残った行を入力順で対応付ける
2. 対応付いた行同士に差分があれば `updated` とする
3. 旧行数と新行数が異なる場合は、残りを無理に対応付けず、旧側を `deleted`、新側を `created` に振り分ける

この規則により、曖昧ケースでも決定論的に結果が定まる。

`updated.changes` に含めるのは、初期実装では次の項目のみとする。

- `gross_amount`
- `net_amount`
- `tax_amount`
- `business_ratio`

`sub_account_id` と `tax_type` は対応付けキーに含まれるため、`updated.changes` には現れない。これらが異なる場合は別グループとして扱われ、`updated` ではなく `created` / `deleted` に振り分ける。

この規則で十分な理由:

- 初期対象の改訂は通常取引の単純修正が中心
- 借方・貸方 1 行ずつ、または少数行の比較が大半
- 監査 UI で必要なのは会計上の意味がわかる差分であり、内部 ID の追跡ではない

## 初期実装で許容する曖昧さ

次のケースでは、完全な 1:1 対応を保証しない。

- 同じ `type + sub_account_id + tax_type` を持つ行が複数ある
- 1 行が 2 行に分割される
- 2 行が 1 行に統合される
- 同一キーグループ内で新旧の行数が異なる

この場合は、無理に自然言語で「この 1 行があの 1 行に変わった」と断定せず、`created` / `deleted` を優先する。

つまり、**誤った対応付けをするより、粗めの差分に倒す**。

## 表示用サマリ

差分要約の文字列生成は `TransactionDiffResult` に持たせない。

- `TransactionDiffResult` は構造化差分だけを保持する
- 表示文言は `TransactionDiffPresenter` のような別レイヤで組み立てる
- 利用者向け文言は `lang/ja` カタログを通す

例:

- `TransactionDiffPresenter::summaryLines(TransactionDiffResult $diff): array`
- `取引日: 2026-08-01 -> 2026-08-03`
- `摘要: 文房具 -> 文房具の購入`
- `借方 消耗品費: 1000円 -> 1100円`
- `貸方 現金: 1000円 -> 1100円`

サマリ文字列は表示用途に限る。業務判定や条件分岐に使わない。

## 監査ログとの関係

この比較機能は、監査ログの保存 shape を直接決める責務は持たない。

- 監査ログは意味ベースイベントを主とする
- `transaction.revised` の `changes` に、比較結果をそのまま永続化するかは別判断
- ただし、`subject` / `related` の shape は `audit-log-design.md` と寄せておく
- 将来 `toAuditChanges()` を追加する場合も、変換対象は `subject` / `related` のみとし、`derived` は含めない

これにより、後から次のどちらにも進める。

- 表示時に旧版・新版を比較して差分を組み立てる
- 比較結果の一部を監査ログへ保存する

## 責務分離

責務は次のように分ける。

- `TransactionRevisor`: 旧版無効化、新版作成、改訂チェーン接続
- `TransactionDiffer`: 旧版・新版の比較
- `TransactionDiffResult`: 構造化差分の保持
- `TransactionDiffPresenter`: 表示文言の生成
- 監査ログ書き込み側: 何を保存するかの選択
- UI: 差分の見せ方の決定

初期実装では `TransactionDiff` ファサードは作らず、`TransactionDiffer` / `TransactionDiffResult` が監査ログ保存や翻訳文言まで持つ設計にはしない。

## テスト観点

最低限、次を固定テストで担保する。

- 単純属性だけが変わる場合、`subject` に `[old, new]` が入ること
- 変更のない属性は含めないこと
- `derived` は `subject` と分離され、`hasChanges()` 判定には単独で使われないこと
- `journal_entries` で金額だけ変わる場合、`updated` に入ること
- 旧版のみにある行が `deleted`、新版のみにある行が `created` に入ること
- `business_ratio` だけ変わる場合、`updated.changes` に入ること
- 借方 / 貸方の入れ替えは `updated` ではなく `created + deleted` に入ること
- 同一キー複数行の曖昧ケースで、誤った 1:1 対応を作らないこと
- 同一キーグループで 2:2 の場合は入力順対応で `updated` になること
- 同一キーグループで 2:1 または 1:2 の場合は `updated` を作らず、`created` / `deleted` に倒すこと
- 差分がない場合、`hasChanges()` が `false` になること

## 将来拡張

- `counterparty` 名や `sub_account` 名など、表示用ラベルを同梱する
- `journal_entries` の対応付け規則を差し替え可能にする
- 監査ログ永続化用に `toAuditChanges()` を追加する
- `TransactionDiffPresenter` の実装を画面単位で差し替え可能にする

## まとめ

`Transaction` の差分比較は、モデルメソッドではなく専用の比較入口に外出しする。

- 比較の本体は `TransactionDiffer::diff($old, $new)` に置く
- 引数順は `old, new` に固定する
- 返り値 shape は `subject` / `derived` / `related` に分ける
- `journal_entries` は内容ベースで対応付ける
- `type` 変更は `updated` ではなく `created + deleted` として扱う
- 曖昧なケースでは無理に 1:1 対応せず、粗めの差分に倒す

この方針なら、改訂確認 UI・監査ログ UI・将来の差分保存のどれにも流用しやすい。
