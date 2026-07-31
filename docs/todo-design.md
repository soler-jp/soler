# Todo Design

このドキュメントは、事業体ごとの「やること」を管理する `Todo` モデルと、その最小 API の設計を整理する。

初回 SetupWizard から派生する個別 SetupWizard カードは、将来的にはこの `Todo` の一形態として Dashboard 上で並べたい。ただし本ドキュメントは、その統合より前段として、Todo 単体を Console から操作できる最小構成の設計だけを対象にする。SetupWizard カード側は当面 `FiscalYearSetupAnswer` の射影を返す既存 Provider のまま残し、Dashboard 表示層でのみ両者を並べる方針とする（[setupwizard-design.md](setupwizards/setupwizard-design.md) を参照）。

## 目的

- 事業体ごとの「やること」を、モデルと Service で明示的に扱えるようにする
- 手入力の Todo、および将来の定期取引・システム検知由来の Todo を、同じテーブル・同じ入り口で扱えるようにする
- 発生源モデル（例: `RecurringTransactionPlan`）が削除されても、記録された Todo が独立して残るようにする
- 新規の書き込み入口として、最初から `User $actor` 必須・fail-closed の認可を備える（[actor-authorization-rollout-plan.md](actor-authorization-rollout-plan.md) の方針に従う）
- v1 では Console から呼べる最小の Service API に絞り、生成バッチ・Dashboard 統合・冪等キー・スヌーズは扱わない

## 用語

- Todo
  - `Todo` モデル
  - 事業体に対して 1 件発生した「やること」の単位
- 発生源
  - Todo を生み出した元の Model（例: `RecurringTransactionPlan`、後述の system 検知など）
  - Todo からは polymorphic 参照で辿る
- 手入力 Todo
  - ユーザーが直接登録した Todo。発生源を持たない

## スコープ

### v1 で扱う

- `Todo` モデルとテーブル
- 値集合の定数クラス（`TodoSourceType` / `TodoPriority` / `TodoStatus`）
- `TodoService` による登録・完了・却下・一覧取得
- 発生源モデルへの polymorphic 参照（削除時は Todo を残す方針）

### v1 で扱わない（[今後の拡張](#今後の拡張)）

- 発生源からの自動生成（`RecurringTransactionPlan` からの月次生成、system 検知など）
- 冪等キーによる重複防止
- スヌーズ（`snoozed_until`）
- Dashboard 表示・Livewire コンポーネント
- SetupWizard カードとの統合

## データモデル

`todos` テーブルは、事業体ごとの Todo を 1 行 1 件で保持する。

```php
Schema::create('todos', function (Blueprint $table) {
    $table->id();

    $table->foreignId('business_unit_id')
        ->constrained()
        ->cascadeOnDelete()
        ->comment('対象事業体ID');

    $table->foreignId('fiscal_year_id')
        ->nullable()
        ->constrained()
        ->nullOnDelete()
        ->comment('対象年度ID。年度に紐づかない Todo は null');

    $table->string('source_type')
        ->comment('Todo の発生源種別。manual, recurring, system');

    $table->nullableMorphs('source_model');

    $table->string('title')
        ->comment('Todo の表示文言（1行）');

    $table->text('body')
        ->nullable()
        ->comment('補足説明');

    $table->date('due_on')
        ->nullable()
        ->comment('期日');

    $table->string('priority')
        ->default('normal')
        ->comment('優先度。high, normal, low');

    $table->string('status')
        ->default('pending')
        ->comment('状態。pending, completed, dismissed');

    $table->timestamp('completed_at')->nullable();
    $table->timestamp('dismissed_at')->nullable();

    $table->timestamps();
});
```

### `source_type` と `source_model_*`

- `source_type` は Todo の由来を分類する文字列（`TodoSourceType` の定数）
- `source_model_type` / `source_model_id` は polymorphic な発生源への参照（`nullableMorphs`）
- `source_type = manual` の Todo は `source_model_*` を持たない
- v1 では発生源側からの自動生成は行わないため、`source_type = recurring` / `system` の Todo は Console から明示的に登録される

### 発生源が削除されたときの挙動

Todo は「一度発生したやることの記録」として扱い、発生源のライフサイクルに縛らない。

- polymorphic 参照は FK 制約を張らないため、発生源モデルが削除されても DB 上の `source_model_id` はそのまま残る
- Todo の描画側は `$todo->source` が null を返す可能性を前提にする
- 発生源の削除に連動して Todo を消したい要件が出た場合は、Observer やドメインイベントで個別に処理する（v1 では実装しない）

## 値の集合（モデル定数）

`source_type` / `priority` / `status` に入る値の集合は、既存の `JournalEntry::TAX_TYPE_*` や `RecurringTransactionPlan::TYPE_*` と同じく **`Todo` モデル上の定数**として定義する。テストコードからも `Todo::SOURCE_TYPE_MANUAL` の形で参照する（[CLAUDE.md](../CLAUDE.md) の Project Conventions に準拠）。

### `Todo` モデル上の定数

```php
class Todo extends Model implements ResolvesBusinessUnit
{
    public const SOURCE_TYPE_MANUAL    = 'manual';
    public const SOURCE_TYPE_RECURRING = 'recurring';
    public const SOURCE_TYPE_SYSTEM    = 'system';

    public const PRIORITY_HIGH   = 'high';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_LOW    = 'low';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_DISMISSED = 'dismissed';

    public const SOURCE_TYPES = [
        self::SOURCE_TYPE_MANUAL,
        self::SOURCE_TYPE_RECURRING,
        self::SOURCE_TYPE_SYSTEM,
    ];

    public const PRIORITIES = [
        self::PRIORITY_HIGH,
        self::PRIORITY_NORMAL,
        self::PRIORITY_LOW,
    ];

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_COMPLETED,
        self::STATUS_DISMISSED,
    ];

    // ...
}
```

### `source_type` と発生源モデルの対応表

`source_type` は発生源の分類を持つが、`source_model_type` と食い違うと不正状態が成立する。これを防ぐため、`source_type` ごとに「許可する発生源モデルのクラス集合」を `Todo` モデルの `$allowedSourceModels` として持たせ、`TodoService` の登録時にこの集合で必ず検証する。

- `SOURCE_TYPE_MANUAL` は発生源を持たない（`source_model_*` は必ず null）
- `SOURCE_TYPE_RECURRING` は `RecurringTransactionPlan` に限定する
- `SOURCE_TYPE_SYSTEM` は v1 では発生源モデルを持たない（`source_model_*` は必ず null）。将来 system 検知で紐づけたいモデルが決まった時点で `$allowedSourceModels` に追加する

```php
class Todo extends Model implements ResolvesBusinessUnit
{
    /**
     * source_type ごとに、発生源として許可するモデルクラスの集合。
     * 空配列は「発生源モデルを持たない（source_model_* は null 必須）」を表す。
     *
     * @var array<string, array<int, class-string>>
     */
    public static array $allowedSourceModels = [
        self::SOURCE_TYPE_MANUAL    => [],
        self::SOURCE_TYPE_RECURRING => [RecurringTransactionPlan::class],
        self::SOURCE_TYPE_SYSTEM    => [],
    ];
}
```

登録側では、この定義を使って次を必ず検証する。

- `$allowedSourceModels[$sourceType]` が空配列: `$sourceModel === null` でなければドメイン例外
- 空配列でない: `$sourceModel` が非 null かつ、そのクラスがこの配列のいずれかに `instanceof` で一致しなければドメイン例外
- 発生源モデルは `ResolvesBusinessUnit` を実装している必要があり、`$sourceModel->resolveBusinessUnit()->is($businessUnit)` が真でなければドメイン例外
- 発生源モデルは既に保存済み（`$sourceModel->exists === true`）でなければドメイン例外（未保存モデルを渡すと morph 参照の `source_model_id` が null になり、DB 上に壊れた参照が残るため）

## サービス設計

v1 では、Todo の生成・状態変更・一覧取得を `TodoService` に集約する。UI からの入り口も、後述のバッチや Dashboard 統合を実装する場合の入り口も、すべてこの Service を経由する前提とする。

### TodoService

```php
final class TodoService
{
    use AuthorizesBusinessUnitAccess;

    public function register(
        BusinessUnit $businessUnit,
        string $title,
        User $actor,
        ?FiscalYear $fiscalYear = null,
        ?string $body = null,
        ?CarbonInterface $dueOn = null,
        string $priority = Todo::PRIORITY_NORMAL,
        string $sourceType = Todo::SOURCE_TYPE_MANUAL,
        ?Model $sourceModel = null,
    ): Todo;

    public function complete(Todo $todo, User $actor): void;

    public function dismiss(Todo $todo, User $actor): void;

    /** @return Collection<int, Todo> */
    public function listPending(
        BusinessUnit $businessUnit,
        User $actor,
        ?FiscalYear $fiscalYear = null,
    ): Collection;
}
```

### 責務

- `register()`
  - 冒頭で `$this->authorizeBusinessUnitAccess($businessUnit, $actor, ...)` を呼び、失敗時は `AuthorizationException` を投げる
  - `sourceType` と `sourceModel` の整合性を `Todo::$allowedSourceModels` に照らして、次の順で検証する（メッセージが一次原因を指し示すよう、より広い分類の食い違いから先に判定する）
    1. `sourceType` が `Todo::$allowedSourceModels` のキーに含まれるかを検証（未対応の source_type ならドメイン例外）
    2. 空配列に対応する `sourceType`（`manual` / `system`）: `sourceModel` が null でなければドメイン例外
    3. 空配列でない `sourceType`（`recurring`）: `sourceModel` が null ならドメイン例外
    4. `sourceModel` のクラスが `$allowedSourceModels[$sourceType]` のいずれかに `instanceof` で一致しなければドメイン例外
    5. `sourceModel` が `ResolvesBusinessUnit` を実装していなければドメイン例外
    6. `sourceModel->exists` が false（未保存）ならドメイン例外。未保存モデルを渡すと morph 参照の `source_model_id` が null になり、DB 上に壊れた参照が残るため
    7. `$sourceModel->resolveBusinessUnit()->is($businessUnit)` が真でなければドメイン例外（他事業体のモデルを紐づける経路を塞ぐ）
  - `fiscalYear` が渡された場合、その `business_unit_id` が `businessUnit` と一致するかを検証し、不一致ならドメイン例外を投げる
  - 上記の検証を通過した後に `Todo` を 1 件作成し、`status = pending` で保存する。`sourceModel` が非 null の場合は `source_model_type` / `source_model_id` を埋める
- `complete()`
  - 冒頭で `$this->authorizeBusinessUnitAccess($todo, $actor, ...)` を呼び、失敗時は `AuthorizationException` を投げる
  - `status = completed`、`completed_at = now()` を設定する
  - すでに `completed` / `dismissed` の Todo に対して呼ばれた場合はドメイン例外を投げる
- `dismiss()`
  - 冒頭で `$this->authorizeBusinessUnitAccess($todo, $actor, ...)` を呼び、失敗時は `AuthorizationException` を投げる
  - `status = dismissed`、`dismissed_at = now()` を設定する
  - すでに `completed` / `dismissed` の Todo に対して呼ばれた場合はドメイン例外を投げる
- `listPending()`
  - 冒頭で `$this->authorizeBusinessUnitAccess($businessUnit, $actor, ...)` を呼び、失敗時は `AuthorizationException` を投げる
  - `fiscalYear` が渡された場合、その `business_unit_id` が `businessUnit` と一致するかを検証し、不一致ならドメイン例外を投げる（呼び出しミスを静かに通さない）
  - 指定事業体の `status = pending` の Todo を返す
  - `fiscalYear` が渡された場合は、その年度に紐づくもの、および年度に紐づかない（`fiscal_year_id = null`）ものを両方返す
  - 並び順は `priority`（high → normal → low）、`due_on`（近い順、null は末尾）、`created_at`（古い順）とする
  - `priority` は文字列列のため、単純な `orderBy('priority')` ではこの順序を表現できない。実装時は `CASE WHEN` による重み付け、または別の並び替え用列の導入のどちらかで明示的に順序を定義する
  - もし `orderByRaw` / `CASE WHEN` を使う場合、このリポジトリの規約上は MySQL 固有差異の確認対象に入るため、通常の SQLite テストに加えて必要な `mysql` グループのテストも用意する

### 認可方針

- `TodoService` の全公開メソッドは `User $actor` を必須引数として受け取り、`AuthorizesBusinessUnitAccess` トレイトで fail-closed に認可する
- 認可対象は次のとおり
  - `register()` / `listPending()`: 引数の `BusinessUnit`（`ResolvesBusinessUnit` を実装済み）
  - `complete()` / `dismiss()`: 引数の `Todo`（下記のとおり `ResolvesBusinessUnit` を実装させる）
- `?User` は受け付けず、actor が不明な呼び出しは常に拒否する
- `register()` に渡される `sourceModel` / `fiscalYear` は、いずれも「対象 `BusinessUnit` に属する」ことを Service 側で明示的に検証する。認可（actor に対する `canAccess`）とは別軸の**データ境界のチェック**として扱い、他事業体のモデルを Todo に紐づける経路を塞ぐ
- 認可テストは各公開メソッドに対して 1 本ずつ、他事業体の User で拒否されることを確認する。加えて `register()` は、他事業体の `sourceModel` / `fiscalYear` を渡した場合にドメイン例外になることを確認するテストを 1 本ずつ持つ

### モデル側の責務

`Todo` モデルには、以下の最小のリレーションと定数参照のみを持たせる。ドメイン処理は `TodoService` に寄せる。

- `businessUnit()`（belongsTo）
- `fiscalYear()`（belongsTo, nullable）
- `source()`（morphTo。`source_model_type` / `source_model_id` を使う）
- `SOURCE_TYPE_*` / `PRIORITY_*` / `STATUS_*` および `SOURCE_TYPES` / `PRIORITIES` / `STATUSES` / `$allowedSourceModels` の保持
- `ResolvesBusinessUnit` を実装し、`resolveBusinessUnit()` で `$this->businessUnit` を返す（`TodoService::complete()` / `dismiss()` の認可対象として使う）

## 対象外

- 発生源からの自動生成
  - `RecurringTransactionPlan` の次回発生日から Todo を作る、といった生成ロジックは v1 では扱わない
  - Console から `TodoService::register()` を明示的に呼ぶ運用のみを対象とする
- Dashboard / Livewire コンポーネント
  - v1 では画面を持たない。表示層は後続のタスクで設計する
- SetupWizard カードの Todo 化
  - 既存の SetupWizard カード（`FiscalYearSetupAnswer` の射影）は Todo に載せ替えず、Dashboard 表示層でのみ Todo と並べる
- スヌーズ
  - 「あとで」相当の状態を持たせない。必要になった段階で `snoozed_until` を追加する

## 今後の拡張

以下は本ドキュメントのスコープ外だが、v1 の設計はこれらを後から追加できることを前提にしている。

### 冪等キーと重複防止

`recurring` / `system` 由来の Todo が自動生成されるようになると、同一事業体・同一発生源・同一対象期間で二重登録されないための冪等キーが必要になる。

- 想定形: `todos.source_key`（nullable string）を追加し、`(business_unit_id, source_type, source_key)` に unique 制約を張る
- キーの組み立て例:
  - `recurring`: `"recurring_plan:{plan_id}:2026-06"`
  - `system`: `"month_end_balance:{sub_account_id}:2026-06"`
- 手入力 Todo は `source_key = null` として unique 制約から除外する

### 自動生成のバッチ

- `RecurringTransactionPlan` の次回発生日が近づいたら 1 件生成する日次バッチ
- 事業体プロファイル + 月次スケジュールから system 由来の Todo を生成する処理

### 自動完了検知

- 対応する `JournalEntry` が登録されたら、関連する Todo を完了扱いにする
- 発生源ごとに検知ロジックを持つ形になるため、v1 の手動完了だけで運用したうえで段階的に足す

### Dashboard 統合

- SetupWizard カード（`FiscalYearSetupAnswer` の射影 Provider）と Todo 一覧を、同じ「カード」インターフェースで並べる表示層
- 表示層で並び順・グルーピングを扱い、`TodoService` / `FiscalYearSetupAnswerService` 側は素直な問い合わせだけを返す

### スヌーズ

- `snoozed_until` を追加し、Dashboard 表示では `pending` かつ `snoozed_until <= now()` のもののみ表示する形にする
