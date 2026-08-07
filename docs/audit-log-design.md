# Audit Log Design

このドキュメントは、Soler における監査ログの**データモデルと記録契約**を整理したものです。

扱う範囲:

- DB スキーマ（`audit_logs` / `audit_log_targets`）と型
- 書き込み契約（`AuditLogger` / `AuditContext` / `AuditChanges` の役割と実行時保証）
- shape 契約（`event_type` の Enum 定義、`changes` の JSON 形状）
- 追記専用性と例外の境界

扱わない範囲:

- 既存業務 Service への組み込み計画
- 既存モデル・呼び出し元のリファクタリング計画
- enforcement のためのアーキテクチャテスト・静的検査の運用方針
- UI・表示・検索経路

## 目的

- 業務データに対する変更操作を「誰が・いつ・何を・なぜ・どの対象に」の形で追跡可能にする長期保存データを定義する
- 単一 subject の属性差分ではなく、複合操作（旧版 + 新版 + 関連リソースへの影響）を 1 イベントとして表現できる構造にする
- 記録漏れが**モデル契約**として実行時に検出されるようにする（呼び出し側の注意力に依存しない）
- `BusinessUnit` によるスコープを最初からデータモデルに組み込む

## 位置付け

監査ログは「ログ」ではなく、通常の Eloquent モデルによる**業務データ**として扱う。

- ファイルログ・syslog には出力しない
- `Log` ファサードなどのフレームワークのロギング API とは別物
- DB 上のテーブルとして持ち、業務操作と同一 DB トランザクションで書き込む
- `BusinessUnit` に属する第一級リソースとして、既存の認可規約（`ResolvesBusinessUnit` / `AuthorizesBusinessUnitAccess`）に載せる

理由は次の通り。

- 長期保存対象であり、テキストログのローテートで消えてはならない
- `BusinessUnit` スコープでのクエリが必須（自分の履歴だけ閲覧、他事業体のログが混入しない）
- 業務操作と同一トランザクションで巻き戻せる整合性が必要

## 記録方式

意味ベース（semantic）を主軸とする。

- 業務上の意味を持つイベント名で記録する（例: `transaction.deactivated`, `transaction.revised`, `fiscal_year.closed`）
- 変更内容は `changes` 列に構造化 JSON として同梱する
- Eloquent モデル観察者による**自動 diff 記録は採用しない**

理由:

- 属性 diff だけでは「なぜ」を含められず、監査対応で意味を再構成できない
- 「旧版無効化 + 新版作成」のような複合操作は、1 イベント + 複数対象で表現するのが自然（後述の `audit_log_targets`）
- 自動記録はモデルの内部書き換え経路（バッチ・トリガー・migration 補助）まで拾ってしまい、業務イベントとしてノイズが多い

## データモデル

監査ログは 2 テーブル構成にする。

- `audit_logs` — 業務イベント本体（1 レコード = 1 イベント）
- `audit_log_targets` — 対象リソースへのリンク（1 イベントが複数対象を持てる）

単一 `auditable_type / auditable_id` を親テーブルに持たせる案は採用しない。改訂のような複合操作で「旧版と新版の両方から履歴を引きたい」という要件を素直に満たせないため。

### audit_logs

| カラム | 型 | 用途 |
| --- | --- | --- |
| `id` | bigint (PK) | サロゲートキー |
| `business_unit_id` | FK not null | スコープの境界 |
| `event_type` | varchar(64) not null | 意味ベースイベント名。`AuditEvent` Enum のシリアライズ値 |
| `actor_id` | FK to `users` nullable | 操作者。バッチ・システム経路のみ null |
| `actor_label` | varchar(100) utf8mb4_bin nullable | 記録時点の actor 表示名スナップショット |
| `reason` | text nullable | 業務理由（`deactivation_reason` / `revision_reason` などのコピー） |
| `payload_version` | smallint not null | `changes` の shape バージョン。初期は 1 |
| `changes` | JSON nullable | 変更内容。shape は後述 |
| `context` | JSON nullable | IP・UA・リクエスト経路など任意の付帯情報 |
| `recorded_at` | datetime(6) not null | DB に記録された時刻。時刻の正はこの 1 本 |

インデックス:

- `(business_unit_id, recorded_at desc, id desc)` — 一覧・時系列取得の主クエリ
- `(business_unit_id, event_type, recorded_at desc, id desc)` — イベント種別絞り込み

`created_at` / `updated_at` は持たない。監査ログは追記専用で、Laravel 慣習の 2 本時刻を持たせても情報が増えない上に `recorded_at` と乖離するリスクだけが残るため。

- モデル側で `$timestamps = false` を設定する
- `AuditLogger` が `recorded_at = now()` を明示的に設定する

`occurred_at`（業務イベントの発生時刻）列は初期実装では持たない。外部取込や過去データ移行など、DB 記録時刻と乖離する経路が実際に必要になった時点で追加する（[将来拡張](#将来拡張) 参照）。

### audit_log_targets

| カラム | 型 | 用途 |
| --- | --- | --- |
| `id` | bigint (PK) | サロゲートキー |
| `audit_log_id` | FK to `audit_logs` not null | 親監査ログ |
| `business_unit_id` | FK not null | 冗長列（クエリ効率と整合検証用） |
| `role` | varchar(32) not null | 対象の役割。`AuditTargetRole` Enum のシリアライズ値 |
| `auditable_type` | varchar(191) not null | 対象モデルクラス（morph map 経由） |
| `auditable_id` | varchar(36) ascii_bin not null | 対象 ID（後述） |
| `recorded_at` | datetime(6) not null | 親の `recorded_at` を冗長で保持（後述） |

インデックス:

- `(business_unit_id, auditable_type, auditable_id, recorded_at desc, audit_log_id desc)` — 特定リソースの履歴取得（子テーブル単独で完結）
- `(audit_log_id)` — 親からの子取得

`recorded_at` を子テーブルに冗長で持つ理由:

- リソース履歴取得（`forAuditable()`）の主クエリは「あるリソースに紐づく監査ログを新しい順に並べる」で、時系列の正は親 `audit_logs.recorded_at` にある
- 子テーブルに時刻を持たないと、フィルタは子で解決できても並び替えのために親を join する必要が生じ、リソース履歴の主クエリで join + filesort が発生する
- 監査ログは追記専用で親の `recorded_at` が後から変わらないため、冗長化のリスク（乖離）が構造的に発生しない
- `business_unit_id` を子に冗長で持つ判断（クエリ効率のため）と同じ根拠

`role` の値集合:

- `subject` — 対象そのもの。1 イベントに 1 つが基本
- `source` — 派生元（改訂の旧版など）
- `result` — 派生先（改訂の新版など）
- `affected` — 間接的に影響を受けた関連リソース

例:

- `transaction.deactivated` は `role=subject` に 1 行
- `transaction.revised` は `role=subject` に新版、`role=source` に旧版の 2 行
- `fiscal_year.rolled_over` は `role=subject` に対象年度、`role=result` に繰越先年度、`role=affected` に生成された期首取引群

`business_unit_id` と `recorded_at` を子テーブルに冗長で持つのは、いずれもリソース履歴取得の主クエリを子テーブル単独で完結させるため。親 join を避けるとインデックスヒット率が上がり、リソース履歴ページのレイテンシ特性が安定する。親子で `business_unit_id` / `recorded_at` が一致することは `AuditLogger` が書き込み時に保証する。監査ログは追記専用で親側もこれらを後から更新しないため、乖離リスクは構造的に発生しない。

### auditable_id の型

`auditable_id` は `varchar(36)` / `ascii_bin` で保持する（`bigint` 固定にはしない）。

- 現状 Soler の全モデルは bigint auto-increment だが、監査ログの保存期間中に UUID / ULID モデルが 1 つでも追加されると型変更コストが非常に高い
- 監査ログの `auditable_type` + `auditable_id` はポリモーフィック参照で FK 制約を張らない（張れない）ため、bigint を選ぶ意味は主にインデックスサイズだけ
- `varchar(36)` は ULID (26) / UUID (36) / bigint 文字列化（最長 19）を全てカバーする
- `ascii_bin` にすることで utf8mb4 の 4 バイト/文字を避けつつ、UUID の case sensitivity 事故も防ぐ

アプリ層は常に文字列としてキャストする。bigint モデルでも `(string) $model->getKey()` で書き込む。

### changes 列の物理上限

- 列型は MySQL の `JSON`（PostgreSQL なら `jsonb`）
- **モデル契約として 64 KB を hard cap** とする
- シリアライズ後 64 KB を超える書き込みは `AuditChangesTooLargeException` で拒否する
- 上限に収める責任は後述の `AuditChanges` ファクトリ側にあり、大量子レコードは集約サマリで表現する

64 KB の根拠:

- MySQL `TEXT` 上限と一致し、将来 `JSON` から `TEXT` に切り替える余地を残す
- 一覧クエリでの 1 レコード転送コストが顕著になる境界
- 集約サマリで表現するには十分な余白

### payload_version

`changes` の shape 定義は将来変わり得るため、レコードごとに `payload_version` を持つ。

- 初期は全レコード `payload_version = 1`
- shape を後方非互換に変える際に増分し、読み出し側が版に応じてデコードする
- **非互換の例**: 関連名の変更、キー階層の変更、既存キーの削除、意味の変更
- **互換の例**: 新しい関連やキーの追加（読み出し側が未知キーを無視する前提）

7〜10 年以上の保存期間中に `changes` の解釈が変わる可能性を、早めに逃げ道として持たせる。

### actor_label の書式

- 列型は `varchar(100)` / `utf8mb4_bin`
- 100 文字を超える場合は末尾を切り、`…` を付加する
- 空文字は禁止。ゼロ長は `null` として保存する
- 一度書き込んだら**書き換えない**を本設計の原則とする

### 追記専用性

`audit_logs` および `audit_log_targets` はドメイン上、追記専用テーブルとして扱う。

- 更新 API は提供しない
- 物理削除は初期実装では提供しない
- モデル上も `update()` / `delete()` を許容しない実装とする

**現時点では例外なし**。全カラム、全経路について書き換えを拒否する。

### 匿名化・退会対応は本設計では確定させない

利用者の退会や個人情報保護法上の請求に伴い、`actor_label` に残った氏名の扱いをどうするかは、本設計では**確定させない**。

- 個人情報保護法上の「消去」は物理削除だけを指すのではなく、匿名化や仮名化（個人を識別できないようにする措置）も含み得る（個人情報保護委員会 Q&A）
- 利用停止等の請求は一定要件付きで、必ず氏名を消さねばならないとは限らない
- Soler の退会ポリシーそのものが未定

したがって、次の設計判断は本ドキュメントでは行わない。

- `actor_label` を物理的に上書きする経路の追加
- 匿名化用の別テーブルによる表示置換
- 監査ログの追記専用性に例外を設けるかどうか

これらは法務レビューと退会ポリシー確定後に別設計として起こす。それまでは「追記専用に例外なし」を維持する。

追記専用性に将来例外を設ける場合、少なくとも次の条件を満たす設計にする（当ドキュメントでは要件のみ列挙し、実装は決めない）:

- 対象カラムを限定する（`actor_label` のみなど、範囲を最小化する）
- 変更経路を専用 Service に限定する
- 変更操作自体を新規監査ログレコードとして残し、変更と記録が同一 DB トランザクションで対になることを保証する

## AuditLog モデル

`App\Models\AuditLog` を追加する。

- `ResolvesBusinessUnit` を実装し、`AuthorizesBusinessUnitAccess` の対象にする
- `resolveBusinessUnit()` は `businessUnit` リレーションを返す
- `fillable` を持たず、`AuditLogger` からの `forceFill()` + `save()` のみを受け付ける
- `updating` / `deleting` イベントで例外を投げ、追記専用性を担保する
- `targets(): HasMany` で `AuditLogTarget` を持つ
- スコープ: `scopeForBusinessUnit(BusinessUnit)`, `scopeForAuditable(Model&ResolvesBusinessUnit)`
- `event_type` は `AuditEvent` に cast する

`business_unit_id` は「ログ対象が属する `BusinessUnit`」であり、actor の所属ではない。

## AuditLogTarget モデル

`App\Models\AuditLogTarget` を追加する。

- `ResolvesBusinessUnit` を実装（親と同じ `business_unit_id`）
- `fillable` を持たず、`AuditLogger` 経由でのみ書き込み
- `updating` / `deleting` は例外で拒否
- `auditable(): MorphTo` で対象モデルを引く
- `role` は `AuditTargetRole` に cast する

## AuditLogger サービス

`App\Services\AuditLogger` を追加する。監査ログの書き込みは全てこのサービスを経由する。呼び出し側の設計自由度を制限してでも、書き込み契約をこの 1 経路に閉じ込める。

### インターフェース

```php
public function record(
    AuditEvent $event,
    array $targets,          // AuditTarget[]
    ?User $actor,
    AuditChanges $changes = new AuditChanges(),
    ?string $reason = null,
    array $context = [],
): AuditLog;
```

`$targets` は `AuditTarget` 値オブジェクトの配列で、少なくとも 1 つの `subject` を含まなければならない。

```php
final class AuditTarget
{
    public function __construct(
        public readonly AuditTargetRole $role,
        public readonly Model&ResolvesBusinessUnit $model,
    ) {}
}
```

`AuditTarget` は intersection type で `Model & ResolvesBusinessUnit` を要求し、`business_unit_id` 解決不能なモデルの受け渡しを型で防ぐ。

### 責務

- **呼び出し時点で DB トランザクションが開いていることを検証する**（`DB::transactionLevel() > 0`）。開いていなければ `AuditLoggerOutsideTransactionException` を投げる
- 全 target が同一 `business_unit_id` を返すことを検証（矛盾すれば例外）
- `$targets` に `subject` が少なくとも 1 件含まれることを検証（欠なら例外）
- 現在の `AuditContext` スコープに `event_type` が一致するイベントとして登録する（[後述](#auditcontext)）
- `AuditLog` レコードと `AuditLogTarget` レコード群を書き込む（親子は自身では `beginTransaction` せず、呼び出し元の既存トランザクションに参加する）
- `recorded_at = now()` を設定し、同じ値を全 `AuditLogTarget` にも書き込む
- `actor_label` に `$actor?->name` をスナップショットとして書き込む（100 文字切り詰め、空文字 null 化）
- `AuditChanges` をシリアライズして `changes` に書き込む
- シリアライズ後バイト長が 64 KB 超なら `AuditChangesTooLargeException`
- `payload_version` は `AuditChanges` が保持する版数を書き込む
- `auditable_id` には `$target->model->getKey()` を文字列化して書き込む

### トランザクション境界

`AuditLogger::record()` 自身は `DB::transaction` を開かない。呼び出し元の DB トランザクションに参加する前提で、参加できないケース（トランザクション外呼び出し）は上記のとおり実行時に拒否する。

これにより次を保証する。

- 業務操作が失敗して巻き戻ったとき、監査ログも巻き戻る
- 監査ログの書き込みが失敗したとき、業務操作も巻き戻る
- 「業務変更は commit、監査ログだけ失敗」または「監査ログだけ commit、業務変更は失敗」という不整合経路が発生しない

この保証は呼び出し側の注意力に依存せず、**モデル契約として `AuditLogger` が実行時に強制する**。

## AuditContext

`AuditContext` は「監査対象操作のスコープ」を表す。監査ログの**記録漏れをモデル契約として実行時に検出する**ための仕組みで、`AuditLogger` の書き込みは全てこのスコープ内で行われることを前提とする。

### 契約

- `AuditContext::within(AuditEvent $event, Closure $work): mixed` でスコープを開く
- スコープ内で `AuditLogger::record()` が呼ばれると、そのスコープに紐づけて記録数がカウントアップされる
- スコープ終了時に記録数が 0 なら `AuditContextMissingRecordException` を投げ、周囲の DB トランザクションもロールバックされる
- スコープはネスト可能で、内側スコープの完了は外側スコープの記録要件には影響しない
- 実装は静的な bool フラグではなく **stack + try/finally** ベースで、コールスタックローカルに保持する（`Fiber` や coroutine を跨がない前提）

### なぜ必要か

モデルの `updating` イベントによる防御だけでは、モデルの状態変更を伴わないイベント（`transaction.created`, `fiscal_year.closed` など）で記録漏れを検出できない。`AuditContext` は**全てのイベント種別に対して**「対応する record が呼ばれたか」を実行時に保証する。

副次効果として、`AuditLogger::record()` 自身も「現在 `AuditContext` スコープ内か」を検証でき、スコープ外での record 呼び出しも拒否できる（`AuditContextMissingException`）。

## AuditChanges

`AuditChanges` は `changes` 列に書き込む JSON の shape を型付きで表現する値オブジェクト。

### shape

トップレベル構造は固定する。

```json
{
  "subject": { "attr": [old, new] },
  "related": { "<関連名>": { "created": [], "updated": [], "deleted": [] } }
}
```

- `subject`: 対象リソース本体の属性変化。値は `[old, new]` の 2 要素配列で統一。片方欠側は `null`
- `related`: 子コレクション・関連集約の変化。`created` / `updated` / `deleted` の 3 バケット
- 関連キー名は Eloquent のリレーション名に依存させない。**監査用の安定名**を使う（リレーション名変更で監査履歴の解釈が壊れないよう）

`related` の値の形:

```json
{
  "created": [{"id": 12, "attributes": {}}],
  "updated": [{"id": 8, "changes": {"gross_amount": [1000, 1100]}}],
  "deleted": [{"id": 5, "attributes": {}}]
}
```

複数対象間の関係（改訂の旧版・新版など）は `audit_log_targets.role` で表現し、`changes` 内には埋め込まない。

### イベント別 shape 例

**`transaction.created`**

targets: `subject` に対象取引 1 件。

```json
{
  "subject": {
    "date":         [null, "2026-08-07"],
    "description":  [null, "文房具の購入"],
    "entry_number": [null, 42]
  },
  "related": {
    "journal_entries": {
      "created": [
        {"id": 100, "attributes": {"sub_account_id": 5, "type": "debit",  "gross_amount": 1100, "tax_type": "taxable_purchases_10"}},
        {"id": 101, "attributes": {"sub_account_id": 8, "type": "credit", "net_amount":   1100, "tax_type": "non_taxable"}}
      ]
    }
  }
}
```

**`transaction.deactivated`**

targets: `subject` に対象取引 1 件。

```json
{
  "subject": {
    "is_active":      [true, false],
    "deactivated_at": [null, "2026-08-07T12:34:56Z"]
  },
  "related": {}
}
```

**`transaction.revised`**

targets: `subject` に新版、`source` に旧版の 2 件。旧版 ID を `changes` に埋め込まないため、`changes` は新版側の情報のみを持つ。

```json
{
  "subject": {
    "revision_reason": [null, "金額入力ミスの修正"]
  },
  "related": {
    "journal_entries": {
      "created": [
        {"id": 200, "attributes": {"sub_account_id": 5, "type": "debit", "gross_amount": 1100, "tax_type": "taxable_purchases_10"}}
      ]
    }
  }
}
```

**`fiscal_year.rolled_over`**

targets: `subject` に元年度、`result` に繰越先年度、`affected` に生成された期首取引群（各 1 行ずつ）。大量子レコードの詳細は個別に列挙せず、集約サマリを `changes` に置く。

```json
{
  "subject": {
    "rollover_at": [null, "2026-08-07T00:00:00Z"]
  },
  "related": {
    "opening_transactions": {
      "created": [{"count": 15, "entry_number_range": [1, 15]}]
    }
  }
}
```

### ファクトリ

`AuditChanges` は**イベント専用ファクトリメソッドのみ**を公開する。汎用の「任意の属性配列を受け取る」API は提供しない。

```php
AuditChanges::forTransactionCreated(Transaction $transaction);
AuditChanges::forTransactionDeactivated(Transaction $transaction);
AuditChanges::forTransactionRevised(Transaction $newVersion);
AuditChanges::forFiscalYearClosed(FiscalYear $fiscalYear);
AuditChanges::forFiscalYearRolledOver(FiscalYear $fiscalYear, int $openingTransactionCount);
```

汎用 API を提供しない理由:

- 監査データでは「何を保存するか」を明示的に決めた方が事故が少ない
- 呼び出し側が「たまたま dirty な属性を全部保存」してしまうと、機密情報や意味のない中間状態が混入する
- ファクトリ単位で `payload_version` を管理できる（あるファクトリの shape だけ増分するなど）

新しい `AuditEvent` を追加する際は、対応するファクトリメソッドと shape の固定テストを同時に追加する。

## event_type

`event_type` は PHP の `BackedEnum`（string backed）で集約する。

```php
namespace App\Auditing;

enum AuditEvent: string
{
    case TransactionCreated      = 'transaction.created';
    case TransactionDeactivated  = 'transaction.deactivated';
    case TransactionRevised      = 'transaction.revised';
    case FiscalYearClosed        = 'fiscal_year.closed';
    case FiscalYearRolledOver    = 'fiscal_year.rolled_over';
}
```

- 命名は `<resource>.<action>` の 2 段。`resource` はモデルのスネークケース単数形、`action` は業務動詞の過去形
- DB カラムは `varchar(64)`。MySQL の `ENUM` 型は使わない（イベント追加毎のスキーマ変更を避ける）
- モデル cast で読み出し時に自動で Enum 化される
- 過去に書き込まれた値の case は**削除・rename しない**運用を守る（Enum 化を採用する前提条件）
- 将来 3 段命名が必要になった場合も同じ Enum に追加する（例: `credit_card.import_batch.imported`）。長さ 64 は 3 段でも十分収まる

`AuditTargetRole` も同様に BackedEnum で表現する。

```php
enum AuditTargetRole: string
{
    case Subject  = 'subject';
    case Source   = 'source';
    case Result   = 'result';
    case Affected = 'affected';
}
```

## 認可

- 書き込み: `AuditLogger` の呼び出し元が actor を認可済みである前提。`AuditLogger` 自身は認可判定しない
- 読み取り: 監査ログ閲覧は必ず `authorizeBusinessUnitAccess()` を通す。他 `BusinessUnit` のログが混入しない設計をモデル契約として保証する

## 保持期間

監査ログは、関連する会計データの保存期限より前に削除しない。

- 具体的な保持期限は、関連する会計年度およびデータ種別に従う
- 監査ログ単体で法定帳簿の保存要件を満たすことは想定しない
- 電子帳簿保存法の要件は意識するが、この設計だけで適法化を主張しない
- ローテート・アーカイブの具体仕様は実データ量を見てから検討する

## テスト方針

型システムで防げる誤用（`AuditEvent` 未定義値・`AuditTarget` の型違反・`AuditChanges` の汎用配列渡し）はランタイムテストの対象にしない。静的解析（PHPStan / Psalm）で担保する。

モデル契約として実行時テストの対象にするもの:

- `AuditLog` / `AuditLogTarget` の `update()` / `delete()` が例外で拒否されること
- `AuditContext::within()` スコープ内で `record()` が 0 件なら `AuditContextMissingRecordException` になり、DB トランザクションがロールバックされること
- ネストしたスコープで、内側完了が外側の記録要件に影響しないこと
- ネストしたスコープで、内側スコープ内で発生した例外が伝播しても外側スコープの stack が正しく巻き戻ること（try/finally による解放が確実に効くこと）
- `AuditContext` スコープ外の `AuditLogger::record()` が `AuditContextMissingException` で拒否されること
- `AuditLogger::record()` が DB トランザクション外で呼ばれた場合に `AuditLoggerOutsideTransactionException` で拒否されること
- `AuditLogger` が全 target の `business_unit_id` 不一致を例外で拒否すること
- `$targets` に `subject` が含まれない場合に例外で拒否されること
- `actor` が null のケースで `actor_label` が null になること
- `actor_label` が 100 文字超で末尾切り詰め + `…` になること
- `changes` シリアライズ後のバイト長が 64 KB を超えたら `AuditChangesTooLargeException` になること
- `auditable_id` が bigint / UUID / ULID いずれのモデルでも文字列として書き込まれること
- `recorded_at` が `now()` で設定され、`created_at` / `updated_at` カラムが存在しないこと
- `payload_version` が対応する `AuditChanges` ファクトリと一致すること
- 各 `AuditChanges` ファクトリの shape が固定テストで担保されていること
- `audit_log_targets.business_unit_id` が親 `audit_logs.business_unit_id` と一致すること
- `audit_log_targets.recorded_at` が親 `audit_logs.recorded_at` と一致すること
- 上記の親子一致は `AuditLogger` 経由の通常書き込みだけでなく、テスト用 factory / seeder / 直接 insert など**あらゆる書き込み経路**で保証されること（親子整合性は書き込み経路に依存しないモデル契約であるため）

MySQL 固有のクエリを含む場合は `mysql` グループを付ける。

## 初期実装スコープ外

- モデル観察者による属性 diff の**自動記録**
- ログ改ざん検出（ハッシュチェーンや外部システムへの複製）
- `audit_logs` の物理削除・アーカイブ
- 監査ログの CSV / PDF エクスポート
- 退会・匿名化フロー全般（法務・退会ポリシー確定後に別設計）
- 他 `BusinessUnit` 間の横断集計（管理者向け）
- `occurred_at` の分離
- `context` の値オブジェクト化

## 将来拡張

- `payload_version` の増分による shape 進化
- `occurred_at` 列追加による記録時刻と発生時刻の分離（外部取込・過去データ移行が現実化した時点）
- `context` の値オブジェクト化と allowlist 強制
- `context` に traceId / correlation_id を含めて複数関連イベントをグルーピング
- 監査ログの検索 UI（期間・actor・event_type・auditable の複合絞り込み）
- 監査ログのアーカイブ（古いレコードを別テーブル / 別ストレージに退避）
- ハッシュチェーンによる改ざん検出

## まとめ

監査ログを次のデータモデルとして定義する。

- 業務イベント本体 `audit_logs` と、対象リソースへのリンク `audit_log_targets` の 2 テーブル構成
- 1 イベントが複数対象を持てるため、`transaction.revised` のような複合操作を旧版・新版・関連の対等な関係として自然に表現できる
- `event_type` は `AuditEvent` BackedEnum、`role` は `AuditTargetRole` BackedEnum で集約
- 変更内容は `AuditChanges` 値オブジェクトを介して型付きで書き込み、shape は `subject` / `related` に固定
- `AuditChanges` はイベント専用ファクトリのみを公開し、汎用配列渡しは提供しない
- `payload_version` により shape の将来変更に耐える
- 書き込みは `AuditLogger` を経由し、`AuditContext::within()` スコープ内で `record()` が最低 1 回呼ばれること・DB トランザクション内で呼ばれることを実行時契約として保証する
- 追記専用（例外なし）。退会・匿名化フローで例外が必要になった場合は法務・退会ポリシー確定後に別設計として起こす
- `recorded_at` 1 本で時刻管理し、`created_at` / `updated_at` / `occurred_at` は持たない
- `recorded_at` と `business_unit_id` は `audit_log_targets` にも冗長で保持し、リソース履歴取得を子テーブル単独で完結させる
- `auditable_id` は `varchar(36)` で bigint / UUID / ULID を透過的に受ける
- `changes` は 64 KB を hard cap とし、超過は例外で拒否
- 保持期間は関連会計データに従う（監査ログ単体で法令適合を主張しない）
