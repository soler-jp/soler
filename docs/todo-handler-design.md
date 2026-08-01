# Todo Handler Design

このドキュメントは、[`todo-design.md`](todo-design.md) の v1（Todo モデルと `TodoService` の最小構成）を前提に、**Todo に「入力スキーマ」と「実行ロジック」を第一級市民として持たせる拡張**を設計する。

Dashboard 上に並ぶカード、SetupWizard の各画面、tinker やバッチジョブからの Todo 実行、いずれも同じ抽象で扱えるようにする。

## 背景と目的

v1 の Todo は「1 行の title と body、状態（pending/completed/dismissed）」を持つだけの、いわば「メモ」に近い存在である。しかし実運用で想定している Todo は次のような、**入力フォームを伴い、submit すると副作用（Transaction 作成など）を起こしてから完了する**ものが多い:

- 「6 月末の A 銀行の残高を確認する」→ 入力: 金額 → 作成: 期末残高チェック用の仕訳 or 差額仕訳
- 「6 月分の売上を入力する」→ 入力: 各取引先ごとの金額 → 作成: 売上仕訳群
- 「7 月のアルバイト代を入力する」→ 入力: 金額 → 作成: 給与仕訳
- 「初回セットアップ: 銀行口座を登録する」→ 入力: 口座リスト + 期首残高 → 作成: SubAccount + 期首仕訳

これらすべてを個別 Livewire コンポーネント + 個別 Service で実装すると、クラス数が爆発する。そこで:

- Todo に **`todo_type` という新カラム** を追加し、実行種別ごとの Handler（入力スキーマと実行ロジックの束）を対応づける
- **既存の `source_type`（`manual` / `recurring` / `system` の発生源分類）は温存する**。「recurring 由来の月次売上入力 Todo」といった直交する分類を両方保てるようにする
- Handler は UI 非依存にし、Blade/Livewire/CLI/バッチ、どこからでも同じ Handler を呼び出せるようにする
- UI 側（Dashboard カード、SetupWizard 画面）は **1 本の汎用コンポーネント**で複数の Todo タイプを扱えるようにする

これにより、新しい Todo タイプの追加は **`todo_type` 定数 + Handler クラス 1 本 + presenter 1 本**で済む。

## スコープ

### 本ドキュメントで扱う

- `todos` テーブルへの `todo_type` カラム追加と `Todo::TODO_TYPE_*` 定数
- `TodoHandler` interface と、`Todo` モデル上の静的配列による `todo_type → Handler` 対応表
- Handler の入力スキーマ形式・バリデーション・実行契約
- 外部呼び出しは常に `TodoService` 経由に閉じる方針（tinker やバッチからの誤用防止）
- `TodoService` の変更（`register()` への `todoType` 追加、`schemaFor()` / `execute()` の追加）
- 認可・トランザクション境界
- Handler 層のテスト戦略

### 本ドキュメントで扱わない

- 個別 Handler 実装（銀行口座 setup、月次残高チェック等）は別ドキュメント
- UI 層の詳細設計。`TodoCardPresenter` と Livewire コンポーネントは Appendix でスケッチのみ示し、次ステップで別途固める
- 定期 Todo の自動生成（`RecurringTodoPlan` 相当）は [`todo-design.md`](todo-design.md) の今後の拡張として扱う
- 自動完了検知（対応 Journal 登録で auto-close）
- スヌーズ・冪等キー

## 用語

- source_type（発生源分類）
  - Todo がどこから生まれたか（`manual` / `recurring` / `system`）
  - v1 と同じ意味論。`$allowedSourceModels` による発生源モデル検証もそのまま
- todo_type（実行種別）
  - Todo が「何をする作業か」を分類する新カラム
  - `TodoHandler` 選択のキーはこれ。`source_type` とは直交する
  - 単純表示のみの Todo（従来の manual 手入力メモ等）は `todo_type = null`
- Handler
  - ある `todo_type` の Todo が「どんな入力を受け取り、submit されたら何をするか」を定義するクラス
  - `TodoHandler` interface を実装する
- Presenter（詳細は Appendix）
  - Handler の Todo を UI 上でどう見せるかを定義する。本ドキュメントでは概要のみ触れ、次ステップで固める

## `todo_type` の導入（`source_type` とは別カラム）

### なぜ別カラムにするか

v1 の `source_type` は「発生源分類」を表しており、`$allowedSourceModels` もこれを前提に「`recurring` なら `source_model_type = RecurringTransactionPlan` が必須」といった検証をしている。ここに `monthly_sales_entry` のような業務種別を混ぜると、以下の問題が生じる:

- 「recurring 由来の月次売上入力 Todo」と「手動作成の月次売上入力 Todo」を区別できなくなる
- `$allowedSourceModels` の検証意図（発生源モデル必須性）と、業務種別（何を入力するか）が同じ列で表現できない
- 将来「recurring 生成物と system 検知物を横断して同じ handler で扱いたい」ケースに対応しづらい

したがって、**発生源分類は `source_type` に残したまま、実行種別を表す新カラム `todo_type` を追加**する。Handler の選択は `todo_type` を見る。

### スキーマ変更

```php
// database/migrations/*_add_todo_type_to_todos_table.php
Schema::table('todos', function (Blueprint $table) {
    $table->string('todo_type')
        ->nullable()
        ->after('source_model_id')
        ->comment('Todo の実行種別。Handler 選択のキー。表示のみの Todo は null');
});
```

- `todo_type` は nullable。既存の v1 Todo（`todo_type = null`）は「Handler を持たない、状態遷移だけの表示 Todo」として扱う
- **本ドキュメントの時点では `todo_type` を絞り込みキーにするクエリは無い**（`TodoService::listPending()` は `business_unit_id + status (+ fiscal_year_id)` で絞る）ため、追加インデックスは張らない。将来「特定 `todo_type` の Todo だけを Dashboard で強調する」等のクエリが増えた段階で、そのクエリ形状に合わせて追加する

### `Todo` モデル上の定数

命名規則は以下:

- 初回セットアップ系: `wizard_bank_account`, `wizard_cash_on_hand`, `wizard_counterparty`, ...
- 月次繰り返し系: `monthly_bank_balance_check`, `monthly_sales_entry`, `monthly_wage_entry`, ...
- 単発検知系: `system_negative_cash_detected`, ...

```php
class Todo extends Model implements ResolvesBusinessUnit
{
    public const TODO_TYPE_WIZARD_BANK_ACCOUNT        = 'wizard_bank_account';
    public const TODO_TYPE_MONTHLY_BANK_BALANCE_CHECK = 'monthly_bank_balance_check';
    public const TODO_TYPE_MONTHLY_SALES_ENTRY        = 'monthly_sales_entry';
    // ...

    public const TODO_TYPES = [
        self::TODO_TYPE_WIZARD_BANK_ACCOUNT,
        self::TODO_TYPE_MONTHLY_BANK_BALANCE_CHECK,
        self::TODO_TYPE_MONTHLY_SALES_ENTRY,
        // ...
    ];
}
```

`source_type` および `$allowedSourceModels` は v1 のまま。ここには何も追加しない。

### 具体例

| ケース | `source_type` | `source_model_*` | `todo_type` |
| --- | --- | --- | --- |
| 手動で立てた「銀行残高確認」メモ | `manual` | null | null |
| 初回セットアップの銀行口座登録 | `system` | null | `wizard_bank_account` |
| 定期取引プラン由来の月次売上入力 | `recurring` | `RecurringTransactionPlan` | `monthly_sales_entry` |
| 手動で立てた月次売上入力 | `manual` | null | `monthly_sales_entry` |
| system 検知の残高マイナス警告 | `system` | null | `system_negative_cash_detected` |

同じ `todo_type` の Todo でも `source_type` は異なりうる。両者は直交する。

## `TodoHandler` interface

Handler は UI 非依存。プレーンな配列を受け取り、副作用と Todo 完了を実行する。

**重要**: この interface のメソッドは **`TodoService` からのみ呼び出す**。Livewire・Blade・CLI いずれの経路も、必ず `TodoService` を挟むこと。理由は下記「[外部呼び出しは TodoService に閉じる](#外部呼び出しは-todoservice-に閉じる)」を参照。

```php
namespace App\Todo\Handlers;

use App\Models\Todo;
use App\Models\User;

interface TodoHandler
{
    /**
     * この Handler が対応する todo_type。
     * Todo::$handlers のキーとして使う。
     */
    public function todoType(): string;

    /**
     * 入力フィールドの定義。Laravel Validator ルール互換の配列に、
     * 表示用メタ情報を付加した構造とする（後述の「入力スキーマの形式」参照）。
     *
     * この Todo に紐づく事業体内のデータ（登録済み SubAccount 等）を options に埋める場合があるため、
     * 呼び出し前に actor による事業体アクセスが認可済みであることを保証すること
     * （TodoService::schemaFor() 経由で呼ぶ）。
     *
     * @return array<string, array{
     *     rules: string|array<int, string>,
     *     label: string,
     *     type?: string,
     *     help?: string,
     * }>
     */
    public function inputSchema(Todo $todo): array;

    /**
     * inputs を検証し、正規化された配列を返す。
     * 失敗時は Illuminate\Validation\ValidationException を投げる。
     *
     * inputSchema() と同じく、呼び出し前に actor による事業体アクセスが認可済みであることを保証すること
     * （TodoService::execute() 内から呼ばれる）。
     *
     * @param  array<string, mixed>  $inputs
     * @return array<string, mixed>
     */
    public function validate(Todo $todo, array $inputs): array;

    /**
     * 実行本体。副作用を起こしたのち、Todo を完了させる。
     *
     * - 冒頭で authorizeBusinessUnitAccess($todo, $actor, ...) を必ず呼ぶ
     * - DB::transaction() で包む。副作用と Todo::complete() を単一トランザクションに収める
     * - 既存 Service（TransactionRegistrar 等）を再利用する。新しい書き込み経路をここに書かない
     *
     * @param  array<string, mixed>  $validatedInputs
     */
    public function execute(Todo $todo, array $validatedInputs, User $actor): void;
}
```

### 入力スキーマの形式

- 各フィールドは `rules`（Laravel Validator 互換）と `label`（表示名）を必ず持つ
- `type` は UI レンダリング用のヒント（`number`, `text`, `date`, `select`, `subaccount`, ...）。省略時は `text`
- `help` は表示用の補足説明（optional）
- `select` 系フィールドは `options: array<string|int, string>` を追加できる
- 配列型の入力（複数行の bank account 等）は `type = 'repeater'` + `fields: array<string, ...>` で表現する
- スキーマは **Handler が Todo インスタンスを見て動的に生成できる**。たとえば「事業体に登録済みの SubAccount 一覧」を options に埋める場合など

将来的にスキーマは値オブジェクト化する余地を残すが、v1 では上記の素朴な配列でよい。

### validate() と execute() の分離

- `validate()` は「入力の受理判定と正規化」のみ。副作用を起こさない
- `execute()` は「副作用を起こす」責務のみ。入力は既に validated 済みと仮定してよい
- 分離しておくと、リアルタイムバリデーション（wire:model の submit 前チェック）や、テストでスキーマ検証だけ叩きたい場合に便利

### 外部呼び出しは TodoService に閉じる

Handler の `inputSchema()` は Todo の紐づく事業体データ（登録済み SubAccount 一覧など）を options に埋める場合があるため、無認可で叩けると事業体内データが漏れる。同様に `validate()` は副作用こそ起こさないが、Handler の実装によっては検証中に事業体データを参照しうる。tinker やバッチスクリプトから Handler を直に呼んでしまうと、この認可を素通りする。

したがって以下を規約とする:

- **Handler の `inputSchema()` / `validate()` / `execute()` は、`TodoService` からのみ呼び出す**
- Livewire・Blade・tinker・Artisan コマンド・バッチジョブなど、いかなる呼び出し元も Handler を直接触らず、必ず `TodoService` の対応メソッド（`schemaFor()` / `execute()`）を経由する
- `TodoService` は各メソッドの冒頭で `authorizeBusinessUnitAccess($todo, $actor, ...)` を通してから Handler に委譲する

PHP には package-private が無いため interface のメソッド自体は `public` になるが、この規約はアーキテクチャテスト（`App\Todo\Services\TodoService` 以外から `App\Todo\Handlers\*` の `inputSchema` / `validate` / `execute` を直接呼ぶコードを禁じる）で機械的に強制する。

### 認可

- Handler の `execute()` 冒頭でも `AuthorizesBusinessUnitAccess::authorizeBusinessUnitAccess($todo, $actor, ...)` を呼ぶ（`TodoService` 側でも通すが、fail-closed の保険として二重に）
- `Todo` は既に `ResolvesBusinessUnit` を実装しているのでそのまま使える
- Handler 内で追加の親リソース（`FiscalYear`, `SubAccount` 等）に触る場合、それらも `resolveBusinessUnit()` 経由で境界を確認する

### なぜ Handler が自ら `Todo::complete()` を呼ぶか

「Handler が副作用を起こす → Service 層が Todo を完了する」と 2 段階に分けることもできるが:

- 副作用と Todo 完了は同一トランザクションで実行したい（副作用失敗時に Todo が半完了で残るのを避ける）
- Handler ごとに「何をもって完了とみなすか」が違う（副作用の一部だけで完了とみなすケースもある）
- 呼び出し側が Handler の実装詳細を知らずに済む

以上から、`execute()` の内部で `Todo::complete()` まで責任を持つ設計とする。

## UI 層は次ステップで固める

Dashboard カードや SetupWizard 画面といった UI の詳細は、Handler / TodoService の形が固まった後に別ドキュメントで設計する。本ドキュメントでは Model 側の設計だけを対象とする。

とはいえ、Handler が UI 非依存であることを維持するには「UI 層をどう繋ぐつもりか」の**方向性**は最低限決めておく必要があるため、想定するかたち（`TodoCardPresenter` interface と `DefaultTodoCardPresenter` フォールバック、汎用 Livewire コンポーネント）を [Appendix A](#appendix-a-ui-層の想定スケッチ) にスケッチとして残す。実装時に見直してよい。

## `todo_type → Handler` の対応表

Registry クラスは設けず、既存の `Todo::$allowedSourceModels` と同じパターンで、`Todo` モデル上の静的配列として保持する。ServiceProvider や DI コンテナへの登録は行わず、コードを読めばそのまま対応関係が見える形にする。

```php
class Todo extends Model implements ResolvesBusinessUnit
{
    /** @var array<string, class-string<TodoHandler>> */
    public static array $handlers = [
        // 例: 実装が進んだ段階で足していく
        // self::TODO_TYPE_WIZARD_BANK_ACCOUNT        => BankAccountSetupHandler::class,
        // self::TODO_TYPE_MONTHLY_BANK_BALANCE_CHECK => BankBalanceCheckHandler::class,
    ];

    public function handler(): ?TodoHandler
    {
        if ($this->todo_type === null) {
            return null;
        }

        $class = static::$handlers[$this->todo_type] ?? null;

        return $class !== null ? app($class) : null;
    }

    public function isExecutable(): bool
    {
        return $this->handler() !== null && $this->status === self::STATUS_PENDING;
    }
}
```

- `todo_type = null` または `$handlers` に未登録なら `handler()` は `null` を返す（= 実行不可な単なる表示 Todo。既存の v1 Todo はすべてこれに該当）
- Handler は `app()` 経由で解決するため、コンストラクタで依存注入を受けられる（ステートは持たせない）
- 追加時に触るファイルは `Todo` モデルのみ。ServiceProvider を編集しない
- UI 側の Presenter 登録配列は [Appendix A](#appendix-a-ui-層の想定スケッチ) で扱う

## `TodoService` の変更

v1 の `TodoService` に対して、次の 2 種類の変更を加える:

1. **`register()` に `?string $todoType = null` 引数を追加**（executable な Todo を Service 経由で正規に作れるようにする）
2. **Handler への単一の入り口として `schemaFor()` / `execute()` を追加**（Livewire・tinker・バッチはこれらを経由し、Handler の interface メソッドを直接叩かない）

`complete()` / `dismiss()` / `listPending()` は v1 のまま。

### `register()` への `todoType` 追加

```php
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
    ?string $todoType = null,     // ← 追加
): Todo {
    // 既存の認可・検証はそのまま
    $this->authorizeBusinessUnitAccess($businessUnit, $actor, '...');
    $this->assertPriorityIsSupported($priority);
    $this->assertFiscalYearBelongsToBusinessUnit($businessUnit, $fiscalYear);
    $this->assertSourceModelIsValid($businessUnit, $sourceType, $sourceModel);
    $this->assertTodoTypeIsSupported($todoType);   // ← 追加

    $todo = new Todo([
        'title'       => $title,
        'body'        => $body,
        'due_on'      => $dueOn,
        'priority'    => $priority,
        'source_type' => $sourceType,
        'todo_type'   => $todoType,   // ← 追加
        'status'      => Todo::STATUS_PENDING,
    ]);

    // ...以下 v1 と同じ
}

protected function assertTodoTypeIsSupported(?string $todoType): void
{
    if ($todoType === null) {
        return;
    }

    if (! in_array($todoType, Todo::TODO_TYPES, true)) {
        throw new DomainException('未対応の Todo todo_type です。');
    }

    if (! array_key_exists($todoType, Todo::$handlers)) {
        throw new DomainException('この todo_type に対応する Handler が登録されていません。');
    }
}
```

方針:

- `todoType` は nullable。省略時は従来の v1 と完全に同等（`todo_type = null` の表示 Todo）
- `Todo::TODO_TYPES` に存在しない値はドメイン例外で拒否
- **`Todo::$handlers` に登録されていない `todo_type` も、`register()` の時点でドメイン例外として拒否する**。「先に `Todo::$handlers` に登録してから Todo を作る」を規約として強制することで、誤登録を早期に検出する。他の設計判断（「外部呼び出しを TodoService に閉じる」「誤登録は早く検出する」）と整合する
- `$sourceType` と `$todoType` は独立に検証する。両者は直交する分類のため、組み合わせ制約は設けない（例: `sourceType=manual, todoType=monthly_sales_entry` も許可される）

### `schemaFor()` / `execute()` の追加

```php
/**
 * この Todo の入力スキーマを取得する。
 * Handler が事業体データを参照する可能性があるため、Todo に対する認可を先に通す。
 *
 * @return array<string, array<string, mixed>>|null Handler が無い Todo は null
 */
public function schemaFor(Todo $todo, User $actor): ?array
{
    $this->authorizeBusinessUnitAccess($todo, $actor, 'この Todo の入力仕様を参照する権限がありません。');

    $handler = $todo->handler();

    return $handler?->inputSchema($todo);
}

public function execute(Todo $todo, array $inputs, User $actor): Todo
{
    $this->authorizeBusinessUnitAccess($todo, $actor, 'この Todo を実行する権限がありません。');

    if ($todo->status !== Todo::STATUS_PENDING) {
        throw new DomainException('この Todo はすでに完了または却下されています。');
    }

    $handler = $todo->handler();
    if ($handler === null) {
        throw new DomainException("この Todo は実行可能な Handler を持ちません: todo_type={$todo->todo_type}");
    }

    $validated = $handler->validate($todo, $inputs);

    $handler->execute($todo, $validated, $actor);

    return $todo->refresh();
}
```

- どちらのメソッドも冒頭で必ず `authorizeBusinessUnitAccess()` を通す。Handler は認可済み前提で呼ばれる
- Handler が `execute()` 内でも認可を通すため、`execute()` に関しては二重チェック（fail-closed の保険）
- Handler が例外を投げた場合はそのまま伝播する（Livewire コンポーネント側でハンドリング）

## 呼び出し例（tinker / バッチ）

Handler は UI 非依存なので、tinker や Artisan コマンド、バッチジョブから `TodoService` を通して同じ Handler を叩ける。呼び出し規約は「必ず `TodoService` を経由する」の 1 点。

```php
// tinker
$todo = Todo::find(42);
$user = User::find(1);

// スキーマ確認（認可を通してから Handler::inputSchema() が呼ばれる）
$schema = app(TodoService::class)->schemaFor($todo, $user);

// 実行
app(TodoService::class)->execute($todo, ['amount' => 100000], $user);
```

Dashboard 側の Livewire コンポーネントも同じ `TodoService` を叩く。詳細は [Appendix A](#appendix-a-ui-層の想定スケッチ) を参照。

## 認可・トランザクション境界のまとめ

- `TodoService::execute()` → **1 回目の認可**（`$todo` に対して）
- `Handler::execute()` 冒頭 → **2 回目の認可**（同じ `$todo` に対して、fail-closed の保険）
- Handler 内で `FiscalYear`, `SubAccount` 等の親リソースを触るときは、それらの `resolveBusinessUnit()` が `$todo->businessUnit` と一致することを確認する（他事業体のリソースを紐づけない）
- トランザクション境界は **`Handler::execute()` の内側で `DB::transaction()`** で囲う
  - 副作用（`TransactionRegistrar` 呼び出し等）と `Todo::complete()` を同一トランザクションに収める
  - 呼び出し側（Livewire・CLI）でグローバルトランザクションを張らない。Handler の粒度が権威

## テスト戦略

### Handler 単位のユニットテスト

- `inputSchema()` が期待するキーを含むこと
- `validate()` が正常入力を通し、異常入力で `ValidationException` を投げること
- `execute()` が期待する副作用を起こし、`Todo` が `completed` になること
- 他事業体の actor で `AuthorizationException` になること
- 副作用が失敗した場合、Todo が pending のまま残ること（トランザクション性）

これらはすべて UI 非依存で書ける。

### `TodoService` のテスト

- `register()` に `todoType` を渡すと Todo に反映されること
- `register()` に `Todo::TODO_TYPES` に無い `todoType` を渡すとドメイン例外
- `register()` に Handler 未登録の `todoType` を渡してもドメイン例外
- `register()` の既存挙動（`todoType` 省略時は `todo_type = null`）が回帰していないこと
- `schemaFor()` / `execute()` が冒頭で `authorizeBusinessUnitAccess()` を通していること（他事業体の actor で `AuthorizationException`）
- `execute()` は完了済み Todo でドメイン例外
- `execute()` は Handler が登録されていない `todo_type` でドメイン例外（`register()` で弾かれるはずだが、既存レコードや DB 直挿しへの防衛として残す）
- Handler が例外を投げた場合、そのまま伝播すること

Handler 側は Mock で差し替えてよい（Service 単体の責務に集中）。

### アーキテクチャテスト

- `App\Todo\Handlers` 配下の全クラスが `TodoHandler` を実装すること
- 各 Handler の `todoType()` の戻り値が `Todo::TODO_TYPES` に含まれること
- 各 Handler の `execute()` 内で `authorizeBusinessUnitAccess()` が呼ばれていること（既存の `ActorAuthorizationTest` と同じ機構を流用）
- **`App\Todo\Services\TodoService` 以外のクラスが、`App\Todo\Handlers\*` の `inputSchema` / `validate` / `execute` を直接呼んでいないこと**（PHP に package-private が無いため、これで規約を機械的に強制）

## 追加ステップ（新規 Todo タイプの追加手順）

新しい Todo タイプを追加する開発フローは以下 3 ステップに集約される（Model 層のみ）:

1. `Todo::TODO_TYPES` に定数を追加（例: `TODO_TYPE_MONTHLY_SALES_ENTRY`）
2. `App\Todo\Handlers\MonthlySalesEntryHandler` を作成
3. `Todo::$handlers` に `todo_type => Handler::class` を 1 行追加

UI 側の追加ステップ（Presenter 登録・独自 Blade）は [Appendix A](#appendix-a-ui-層の想定スケッチ) を参照。

## 対象外（本ドキュメントで扱わないが将来の候補）

- **入力スキーマの値オブジェクト化**: 素朴な配列から `TodoInputSchema` VO へ移行
- **フィールド型の共通ライブラリ**: `MoneyField`, `SubAccountSelectField`, `DateField` などのプリセット化
- **Handler の冪等性契約**: 同一 Todo を 2 回 execute しても副作用が 2 回起きない保証（v1 は「完了済み Todo は再実行不可」で回避）
- **入力の中間保存（下書き）**: Livewire の wire:model だけでは足りない、複数ステップの入力を Todo に紐づけて保存する仕組み
- **Handler が Todo を分割する / 派生 Todo を生む**: 「1 つの Todo を実行した結果、次にやるべき別 Todo を作る」パターン
- **通知連携**: Todo 生成時に Slack / Email 通知を送る（Handler ではなく別 Observer で処理する想定）

---

## Appendix A: UI 層の想定スケッチ

Model 側の設計が固まった後に別途詰めるが、方向性を残しておく。実装時に見直してよい。

### `TodoCardPresenter` interface

```php
namespace App\Todo\Presenters;

interface TodoCardPresenter
{
    public function cardView(Todo $todo): string;   // Blade ビューのパス
    public function icon(Todo $todo): string;
    public function title(Todo $todo): string;
}
```

- 汎用 Presenter として `DefaultTodoCardPresenter` を提供し、`cardView()` は `livewire.todo-cards.generic-form`（入力スキーマから自動生成されるフォーム）を返す
- 独自 Blade やアイコンが必要な Handler だけ、専用 Presenter を作って `Todo::$presenters` に登録する

### `Todo` モデルでの解決

```php
/** @var array<string, class-string<TodoCardPresenter>> */
public static array $presenters = [
    // self::TODO_TYPE_WIZARD_BANK_ACCOUNT => BankAccountSetupPresenter::class,
];

public function presenter(): ?TodoCardPresenter
{
    // Handler が無ければ Presenter も無し
    if ($this->handler() === null) {
        return null;
    }

    $class = static::$presenters[$this->todo_type] ?? DefaultTodoCardPresenter::class;

    return app($class);
}
```

**Handler が存在するのに Presenter が null になることは無い**。UI 側は `presenter() === null` を「純粋な表示 Todo」の判定にだけ使い、それ以外では常に Presenter を経由してビュー・アイコン・タイトルを取得する。

### 汎用 Livewire コンポーネント

```php
namespace App\Livewire;

class TodoCard extends Component
{
    public Todo $todo;
    public array $inputs = [];

    public function submit(TodoService $service): void
    {
        $service->execute($this->todo, $this->inputs, auth()->user());
        $this->dispatch('todo-completed', todoId: $this->todo->id);
    }

    public function render(TodoService $service)
    {
        $presenter = $this->todo->presenter();

        if ($presenter === null) {
            return view('livewire.todo-cards.display-only', ['todo' => $this->todo]);
        }

        return view($presenter->cardView($this->todo), [
            'todo'      => $this->todo,
            'presenter' => $presenter,
            'schema'    => $service->schemaFor($this->todo, auth()->user()) ?? [],
        ]);
    }
}
```

- スキーマ取得は必ず `TodoService::schemaFor()` を経由する（Handler の `inputSchema()` を直接叩かない）
- Dashboard は `TodoService::listPending()` の結果をループして `<livewire:todo-card :todo="$todo" />` を並べるだけ

### 追加ステップ（UI 側）

Model 側 3 ステップに加えて、UI が独自になる場合のみ:

4. 専用 Presenter クラスを作成し、`Todo::$presenters` に 1 行追加
5. 専用 Blade ビュー（`resources/views/livewire/todo-cards/...blade.php`）を作成

大半の Handler はここをスキップし、`DefaultTodoCardPresenter` + `generic-form` に任せられる想定。
