# Todo を登録する

この manual では、事業体ごとの `Todo` を `TodoService` 経由で登録・完了・却下・一覧取得する方法を説明します。

`Todo` は手入力のやることだけでなく、将来的には定期取引や system 検知由来のやることも同じモデルで扱う前提です。

詳しい設計は [`docs/todo-design.md`](../docs/todo-design.md) を参照してください。

## 前提

- 対象の `User` があること
- 対象の `BusinessUnit` がその `User` に属していること
- 必要に応じて対象の `FiscalYear` が作成済みであること
- `TodoService` は必ず actor を受け取るため、`User` を渡せること

## 手入力 Todo を登録する

手入力の Todo は `TodoService::register()` で作成します。

```php
use App\Models\Todo;
use App\Services\TodoService;
use Carbon\Carbon;

$actor = auth()->user();
$businessUnit = $actor->selectedBusinessUnitOrFail();
$fiscalYear = $businessUnit->currentFiscalYear;

$todo = app(TodoService::class)->register(
    $businessUnit,
    '7月分の領収書を整理する',
    $actor,
    $fiscalYear,
    'レシートの欠落がないかも確認する',
    Carbon::parse('2026-08-10'),
    Todo::PRIORITY_HIGH,
);
```

この場合は次の値で保存されます。

- `source_type = Todo::SOURCE_TYPE_MANUAL`
- `status = Todo::STATUS_PENDING`
- `source_model_type = null`
- `source_model_id = null`

## 定期取引由来 Todo を登録する

定期取引計画に紐づく Todo は、`sourceType` と `sourceModel` を明示して登録します。

```php
use App\Models\Todo;
use App\Services\TodoService;

$actor = auth()->user();
$businessUnit = $actor->selectedBusinessUnitOrFail();
$fiscalYear = $businessUnit->currentFiscalYear;

$plan = $businessUnit->recurringTransactionPlans()
    ->where('name', '家賃')
    ->firstOrFail();

$todo = app(TodoService::class)->register(
    $businessUnit,
    '家賃の予定取引を確認する',
    $actor,
    $fiscalYear,
    sourceType: Todo::SOURCE_TYPE_RECURRING,
    sourceModel: $plan,
);
```

`sourceType = Todo::SOURCE_TYPE_RECURRING` の場合は、`sourceModel` が必須です。  
また、その発生源モデルは保存済みで、かつ同じ `BusinessUnit` に属していなければなりません。

## system Todo を登録する

system 由来の Todo は、v1 では発生源モデルを持たない想定です。

```php
use App\Models\Todo;
use App\Services\TodoService;

$todo = app(TodoService::class)->register(
    $businessUnit,
    '今月末の銀行残高を確認する',
    $actor,
    $fiscalYear,
    sourceType: Todo::SOURCE_TYPE_SYSTEM,
);
```

`Todo::SOURCE_TYPE_SYSTEM` に対して `sourceModel` を渡すことはできません。

## Handler 付き Todo を登録して実行する

`todo_type` を持つ Todo は、`TodoService` 経由で入力スキーマ取得と実行ができます。  
銀行口座登録では `BankAccountTodoHandler` が紐づいています。

### 銀行口座登録 Todo を作る

```php
use App\Models\Todo;
use App\Services\TodoService;

$todo = app(TodoService::class)->register(
    $businessUnit,
    '銀行口座を登録する',
    $actor,
    $fiscalYear,
    todoType: Todo::TODO_TYPE_WIZARD_BANK_ACCOUNT,
);
```

### 入力スキーマを取得する

```php
use App\Services\TodoService;

$schema = app(TodoService::class)->schemaFor($todo, $actor);
```

現在の銀行口座登録 Todo では、次の 2 項目を入力します。

- `bank_name`
- `opening_balance`

### 実行する

```php
use App\Services\TodoService;

$todo = app(TodoService::class)->execute($todo, [
    'bank_name' => 'ひかり青空銀行',
    'opening_balance' => 120000,
], $actor);
```

実行に成功すると、次が行われます。

- `その他の預金` 配下に銀行名の `SubAccount` が追加される
- `opening_balance > 0` の場合は期首仕訳が作成または改訂される
- Todo 自体は `completed` になる

### 注意点

- Handler は直接呼ばず、必ず `TodoService::schemaFor()` / `TodoService::execute()` を使う
- 銀行口座登録 Todo は `FiscalYear` に紐づいている必要がある
- `opening_balance` は 0 円以上でなければならない
- `opening_balance = 0` の場合は、補助科目だけ追加して期首仕訳は作られない

## 主な定数

- `Todo::SOURCE_TYPE_MANUAL`
- `Todo::SOURCE_TYPE_RECURRING`
- `Todo::SOURCE_TYPE_SYSTEM`
- `Todo::PRIORITY_HIGH`
- `Todo::PRIORITY_NORMAL`
- `Todo::PRIORITY_LOW`
- `Todo::STATUS_PENDING`
- `Todo::STATUS_COMPLETED`
- `Todo::STATUS_DISMISSED`

## Pending Todo を一覧取得する

未完了の Todo 一覧は `TodoService::listPending()` で取得します。

```php
use App\Services\TodoService;

$todos = app(TodoService::class)->listPending(
    $businessUnit,
    $actor,
    $fiscalYear,
);
```

`$fiscalYear` を渡した場合は、次の両方が返ります。

- その年度に紐づく Todo
- 年度に紐づかない Todo（`fiscal_year_id = null`）

並び順は次のとおりです。

1. `priority` が高いもの
2. `due_on` が近いもの
3. `created_at` が古いもの

## Todo を完了する

完了処理は `TodoService::complete()` を使います。

```php
use App\Services\TodoService;

$todo = $businessUnit->todos()->findOrFail($todoId);

app(TodoService::class)->complete($todo, $actor);
```

完了すると、次が設定されます。

- `status = Todo::STATUS_COMPLETED`
- `completed_at = now()`

`pending` 以外の Todo は完了できません。

## Todo を却下する

不要になった Todo は `TodoService::dismiss()` で却下します。

```php
use App\Services\TodoService;

$todo = $businessUnit->todos()->findOrFail($todoId);

app(TodoService::class)->dismiss($todo, $actor);
```

却下すると、次が設定されます。

- `status = Todo::STATUS_DISMISSED`
- `dismissed_at = now()`

こちらも `pending` 以外の Todo は却下できません。

## 注意点

- `TodoService` の public メソッドはすべて actor 必須です
- actor が対象 `BusinessUnit` にアクセスできない場合は `AuthorizationException` になります
- `fiscalYear` を渡す場合は、対象 `BusinessUnit` に属する年度でなければなりません
- `sourceModel` を渡す場合は、`Todo::$allowedSourceModels` に含まれる型である必要があります
- 発生源モデルが削除されると、後から `$todo->source` は `null` になることがあります

## 参考

- `app/Models/Todo.php`
- `app/Services/TodoService.php`
- `app/TodoHandlers/BankAccountTodoHandler.php`
- `app/Services/BankAccountRegistrationService.php`
- `tests/Feature/TodoServiceTest.php`
- `tests/Feature/TodoHandlers/BankAccountTodoHandlerTest.php`
- `docs/todo-design.md`
