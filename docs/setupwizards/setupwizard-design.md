# SetupWizard 設計

> このドキュメントは「どう作るか」を扱う設計書である。
> 画面・文言・質問・表示ルールなど「何を・なぜ」は [setupwizard-spec.md](setupwizard-spec.md) を参照。

---

# 全体構成

SetupWizard は、大きく次の3層で構成する。

| 層 | 役割 | 永続化 |
| --- | --- | --- |
| Livewire コンポーネント | 画面の描画と入力途中の状態の保持 | しない（コンポーネントのプロパティ） |
| Service | ドメイン処理（BusinessUnit / FiscalYear / 期首仕訳 / 個別登録） | 各ドメインモデルへ |
| 初回導線データ | 初回 SetupWizard の回答と完了記録 | `initial_setup_data` |

「入力途中の状態はモデルにしない・確定した回答は永続化する」という分離が全体の前提になる。

## 初回 SetupWizard と個別 SetupWizard の関係

- 初回 SetupWizard は 1 つの Livewire コンポーネント (`App\Livewire\SetupWizard`) で、submit 時に `BusinessUnit` / `InitialSetupData` / `FiscalYear` / 初回 Todo をまとめて作成する。
- 個別 SetupWizard は独立した Livewire コンポーネント群としては実装せず、**Todo と TodoHandler の組**として実装する。Dashboard の Todo 一覧に並び、`App\Livewire\TodoCard` などの汎用/専用 Card が入力 UI を描画する。
- 進行状態は次の 2 つに分けて保持する。
  - 初回回答（該当するかどうか）: `initial_setup_data.*_answer`
  - 個別 Wizard の進行状態: 対応する `todos.status`

---

# Livewire コンポーネント設計

## 初回 SetupWizard

```text
App\Livewire\SetupWizard
```

### 方針

- Step 遷移は `public int $step` で管理する（`max_unlocked_step` も持ち、戻り遷移だけを許す）
- 各 Step の入力値はコンポーネントのプロパティに持つ
- これらの入力途中の状態はモデルとして保存しない
- `submit()` 時に `GeneralBusinessInitializer::initialize()` を1回だけ呼び、まとめて確定する

### Step 構成（現行実装）

| Step | 内容 | プロパティ |
| --- | --- | --- |
| 1 | 事業名 | `name`, `business_type` |
| 2 | 記録開始年 | `year`（`MIN_SUPPORTED_YEAR` から当年まで） |
| 3 | 開始状態 | `opening_context` |
| 4 | 6 問の Yes / No | `bank_account_answer` ほか 5 つの `*_answer` |
| 5 | 消費税申告の要否 | `is_taxable` |
| 6 | 確認して開始 | ― |

Step 4 の 6 問は spec の `bank_account` / `cash_on_hand` / `fixed_asset` / `recurring_expense` / `recurring_income` / `counterparty` に対応する。在庫は初回 SetupWizard の質問には含めない。

## 個別 SetupWizard = Todo + TodoHandler + TodoCard

個別 SetupWizard 用に Livewire コンポーネントを 1 つずつ作らない。次の 3 要素で実装する。

```text
Todo（DB レコード）
  ├─ todo_type = 'wizard_bank_account' などが実装の Wizard 種別
  ├─ TodoHandler が入力仕様（inputSchema）と実行ロジック（execute）を持つ
  └─ TodoCard が入力 UI を描画する（汎用または todo_type ごとの専用）
```

### Livewire 側の実装

| クラス | 役割 |
| --- | --- |
| `App\Livewire\TodoCard` | Handler の `inputSchema()` を汎用に描画する既定の Card |
| `App\Livewire\TodoCards\OpeningBalanceCard` | 開始残高 Todo の専用 Card |
| `App\Livewire\TodoCards\RecurringExpenseCard` | 定期支出 Todo の専用 Card |

現状、専用 Card があるのは開始残高と定期支出のみ。それ以外の Todo は汎用 `TodoCard` を使う。

各 Card は入力途中の状態のみプロパティで保持し、保存時に `TodoService::execute()` → Handler の `validate()` → `execute()` → 対応する Registration Service を呼ぶ流れになる。

## Dashboard カード

Dashboard は Todo 一覧を priority / status で並べ、todo_type に応じた Card を描画する。SetupDashboard 専用の Livewire クラスは持たない。

---

# key → 実装マッピング

現行実装の「初回 SetupWizard の質問キー ↔ 発行される Todo ↔ Handler ↔ Service ↔ 成果物」の対応表。

## Todo Handler マッピング

`App\Models\Todo::$handlers` に定義される。

| todo_type | Handler | Registration Service | 成果物 |
| --- | --- | --- | --- |
| `wizard_bank_account` | `BankAccountTodoHandler` | `BankAccountRegistrationService` | `SubAccount`（その他の預金）＋ 期首仕訳 |
| `wizard_cash_on_hand` | `CashOnHandTodoHandler` | `CashOnHandRegistrationService` | `SubAccount`（現金）＋ 期首仕訳 |
| `wizard_opening_balance` | `OpeningBalanceTodoHandler` | `OpeningBalanceRegistrationService` | 期首仕訳（資産・負債） |
| `wizard_recurring_expenses` | `RecurringExpenseTodoHandler` | ― | `RecurringTransactionPlan`（`type = expense`） |
| `wizard_recurring_incomes` | `RecurringIncomeTodoHandler` | ― | `RecurringTransactionPlan`（`type = income`） |
| `wizard_counterparty` | `CounterpartyTodoHandler` | ― | `Counterparty` |

定期支出・定期収入は共通の `AbstractRecurringTransactionPlanTodoHandler` を継承した Handler で処理する。

## 初回 SetupWizard の回答 → Todo 登録

`GeneralBusinessInitializer::registerRequestedTodos()` が回答を見て Todo を作る。

- `opening_context = carry_forward` → `wizard_opening_balance` Todo を登録
- `bank_account_answer = yes` → `wizard_bank_account` Todo を登録
- `cash_on_hand_answer = yes` → `wizard_cash_on_hand` Todo を登録
- `recurring_expense_answer = yes` → `wizard_recurring_expenses` Todo を登録
- `counterparty_answer = yes` → `wizard_counterparty` Todo を登録

## 未実装の Wizard

現行の initializer は以下の回答に対する Todo を登録しない。回答自体は `initial_setup_data` に保存される。

- `fixed_asset_answer` → 固定資産 Todo / Handler が未実装
- `recurring_income_answer` → Handler と `todo_type` は存在するが、initializer から Todo を登録するコードが未実装
- 在庫 → 初回 SetupWizard に質問がなく、Todo 種別・Handler も未実装

これらの spec は将来像として書かれている。実装のタイミングで initializer と Handler / Service の追加が必要になる。

## 消費税と申告方法

- 消費税は Step 5 で課税 / 免税を確定させ、`fiscal_years.is_taxable` に保存する。詳細（本則 / 簡易 / 2割特例 / 税抜経理など）は消費税計算機能の実装時に扱うため、現時点では専用の SetupWizard も Dashboard カードも設けない。
- 申告方法（青色 / 白色）は初回 SetupWizard でも Dashboard でも扱わない。決算書作成フローで扱うため、`fiscal_years` に列も追加しない。
- 売掛金（請求後入金の売上）と家事按分（共用支払い）は、SetupWizard ではなくそれぞれ売上登録画面・経費登録画面で扱う。

---

# 値の集合（定数クラス）

`answer` に入る文字列や `opening_context` の値は、`InitialSetupData` のモデル定数として定義する。`SetupAnswer` / `SetupStatus` のような専用クラスは持たない。

## InitialSetupData の定数

```php
class InitialSetupData extends Model
{
    public const ANSWER_YES = 'yes';
    public const ANSWER_NO  = 'no';

    public const BINARY_ANSWERS = [
        self::ANSWER_YES,
        self::ANSWER_NO,
    ];

    public const OPENING_CONTEXT_FIRST_YEAR   = 'first_year';
    public const OPENING_CONTEXT_CARRY_FORWARD = 'carry_forward';

    public const OPENING_CONTEXTS = [
        self::OPENING_CONTEXT_FIRST_YEAR,
        self::OPENING_CONTEXT_CARRY_FORWARD,
    ];

    public const KEY_BANK_ACCOUNT      = 'bank_account';
    public const KEY_CASH_ON_HAND      = 'cash_on_hand';
    public const KEY_FIXED_ASSET       = 'fixed_asset';
    public const KEY_RECURRING_EXPENSE = 'recurring_expense';
    public const KEY_RECURRING_INCOME  = 'recurring_income';
    public const KEY_COUNTERPARTY      = 'counterparty';
}
```

現行の初回 SetupWizard は `yes` / `no` のみ扱う。`unknown` はモデルの定数にも含まれておらず、Wizard 内でも使用しない。

## Todo の進行状態

個別 SetupWizard の進行状態は Todo 自身で持つ。

```php
Todo::STATUS_PENDING   = 'pending';
Todo::STATUS_COMPLETED = 'completed';
Todo::STATUS_DISMISSED = 'dismissed';
```

- 初期状態は `pending`
- Handler の `execute()` が成功したときに `Todo::markCompleted()` が呼ばれ `completed` になる
- 明示的に取りやめる場合は `dismissed`
- 「あとで登録する」に相当する `skipped` は現行モデルにはない。利用者が後回しにする場合は `pending` のまま Dashboard に残る

---

# SetupWizard の状態管理

## コンポーネントの入力途中の状態は保存しない

初回 SetupWizard および TodoCard の「入力途中の状態」（現在の step、まだ確定していないフォームの値）は、モデルとして永続化しない。

これらは Livewire コンポーネントのプロパティとして、その場限りのメモリ上でのみ保持する。

## 初回回答は永続化する

Dashboard に出す個別 SetupWizard（Todo）を判定するための初回回答は、セッションやリロードをまたいで必要になるため、`initial_setup_data` として永続化する。

| 種類 | 例 | 保存方法 |
| --- | --- | --- |
| 入力途中の状態 | 現在の step、未確定の入力値 | 保存しない（コンポーネントのプロパティ） |
| 初回回答 | 銀行口座の有無、固定資産の有無、開始年 | `initial_setup_data` に保存 |
| 個別 Wizard の進行状態 | pending / completed / dismissed | `todos.status` |
| 確定した年度設定 | 消費税、opening_context | `fiscal_years` のカラム |

---

# データ設計

## initial_setup_data

初回 SetupWizard の回答を保存する（1 business_unit につき 1 レコード）。実装のマイグレーションと同じ形。

```php
Schema::create('initial_setup_data', function (Blueprint $table) {
    $table->id();
    $table->foreignId('business_unit_id')->constrained()->cascadeOnDelete();
    $table->integer('year');
    $table->string('opening_context');
    $table->boolean('is_taxable')->default(false);
    $table->string('bank_account_answer');
    $table->string('cash_on_hand_answer');
    $table->string('fixed_asset_answer');
    $table->string('recurring_expense_answer');
    $table->string('recurring_income_answer');
    $table->string('counterparty_answer');
    $table->timestamp('completed_at')->nullable();
    $table->timestamps();

    $table->unique('business_unit_id');
});
```

- 個別 Wizard の完了状態を示す `*_setup_status` カラムは持たない。完了状態は対応する Todo の status で判定する。
- 在庫の回答カラム（`inventory_answer`）も現状は持たない。

## fiscal_years の追加項目

年度そのものに強く紐づく基本設定は `fiscal_years` に持たせる。

| カラム | 型 | 説明 |
| --- | --- | --- |
| `opening_context` | string | `first_year` / `carry_forward` |
| `is_taxable` | boolean | 課税事業者なら true、免税事業者なら false |
| `is_tax_exclusive` | boolean | 税抜経理は現時点で常に false（true は initializer 側で拒否） |

## Todo

`todos` テーブル。SetupWizard から作られる Todo は `source_type = system`、`todo_type = wizard_*`、対応する `business_unit_id` / `fiscal_year_id` を持つ。

---

# 期首仕訳の作り方

期首仕訳は、年度につき1本のみとする。

複数の入口（銀行口座 Todo・現金 Todo・開始残高 Todo・将来的な固定資産や在庫）から少しずつ入力されるため、下書きを別テーブルに貯めるのではなく、その1本の期首仕訳を追加・修正できる仕組みで組み立てる。

```text
Todo で残高を入力
  ↓
その年度の期首仕訳（1本）に、該当科目の行を追加・修正する
  ↓
元入金の行を差額として再計算する
```

## 責務分担（現行実装）

- `App\Services\OpeningEntryRegistrar`
  - 期首仕訳の低レベルな登録部品
  - `register(FiscalYear, entries, actor)`: 単発登録。同年度に有効な期首仕訳が既にあれば `DomainException`
  - `registerForRollover(FiscalYear, entries, capitalEntry, actor)`: 資産負債＋元入金を受け取って新規登録（翌期繰越用）
  - upsert 自体はここでは実装しない
- `App\Services\OpeningBalanceRegistrationService`
  - 資産・負債の開始残高を受け取る上位入口
  - 既存の期首仕訳がなければ `OpeningEntryRegistrar::registerForRollover()` で新規登録
  - 既存がある場合は `TransactionRevisor::revise()` を通じて対象の借方・貸方行だけを差し替え、元入金を差額として再計算する
- `App\Services\BankAccountRegistrationService` / `App\Services\CashOnHandRegistrationService`
  - SubAccount の作成と、期首残高がある場合の期首仕訳の追記
  - 既存の期首仕訳があれば `TransactionRevisor::revise()` で追記し、貸方（元入金相当）を合計に合わせて再構成する

各サービスは `AuthorizesBusinessUnitAccess` トレイトを通して actor による認可を行う。

## 元入金の扱い

- 資産・負債の入力を受けるたびに、元入金の行を差額として再計算する
- 利用者に元入金を入力させない。Wizard 上にも表示しない

## 将来的な統一

現状は BankAccount / CashOnHand / OpeningBalance が個別に「期首仕訳の追記・改訂」ロジックを持っている。将来的には `OpeningBalanceRegistrationService` を開始残高全体の統一入口に寄せ、`OpeningEntryRegistrar` に upsert API を持たせる方針。詳細は [opening-balance-registration-service-todo.md](../opening-balance-registration-service-todo.md) を参照。

---

# サービス設計

## GeneralBusinessInitializer

```text
App\Setup\Initializers\GeneralBusinessInitializer
```

初回 SetupWizard の submit を受け、単一トランザクションで次を行う。

- `is_tax_exclusive = true` は `InvalidArgumentException` で拒否（現時点で税抜経理は未対応）
- `User::createBusinessUnitWithDefaults()` で `BusinessUnit` と標準 `Account` / `SubAccount` を作り、単一オーナー（`business_units.user_id`）で紐づける
- `InitialSetupData` を `business_unit_id` 配下に作成し、`completed_at = now()` を記録
- `InitialSetupData::toGeneralBusinessInitializerInputs()` で年度作成向けの input へ整形
- `FiscalYear` を作成し、`is_active` / `is_taxable` / `is_tax_exclusive` / `opening_context` を確定
- 事前に渡された `opening_entries` / `revenue_sub_accounts` を反映
- `registerRequestedTodos()` で初回回答に応じた Todo を作る

## InitialSetupData

```text
App\Models\InitialSetupData
```

- 初回 SetupWizard の回答を 1 レコードにまとめる
- `toGeneralBusinessInitializerInputs()`: 年度作成向けの input を返す。`is_tax_exclusive` は現状 false 固定

## OpeningBalanceRegistrationService

```text
App\Services\OpeningBalanceRegistrationService
```

開始残高の入口。詳細は「期首仕訳の作り方」および [opening-balance-registration-service-todo.md](../opening-balance-registration-service-todo.md) を参照。

## その他の Registration Service / 部品

| クラス | 役割 |
| --- | --- |
| `App\Services\BankAccountRegistrationService` | 銀行口座 SubAccount 登録＋開始残高の期首仕訳への追記 |
| `App\Services\CashOnHandRegistrationService` | 事業用現金 SubAccount 登録＋開始残高の期首仕訳への追記 |
| `App\Services\OpeningEntryRegistrar` | 期首仕訳の低レベル登録部品 |
| `App\Services\TransactionRevisor` | 期首仕訳を含む Transaction の改訂 |
| `App\Services\DepreciationService` | 固定資産の登録と償却明細生成（固定資産 Wizard 実装時に接続予定） |
| `App\Services\TodoService` | Todo の登録・入力仕様取得・実行 |

## Todo Handler の契約

```text
App\Contracts\TodoHandler
```

```php
interface TodoHandler
{
    public function todoType(): string;

    public function inputSchema(Todo $todo): array;

    public function validate(Todo $todo, array $inputs): array;

    public function execute(Todo $todo, array $validatedInputs, User $actor): void;
}
```

`inputSchema()` が返す配列を汎用 `TodoCard` が読んで UI を組み立てる。`execute()` の中で対応する Service を呼び、成功時に `Todo::markCompleted()` を呼ぶ実装が原則。

## SubAccount の system_purpose / visibility

Soler 標準の SubAccount は次の 2 カラムを持つ（[SubAccount モデル](../../app/Models/SubAccount.php) 参照）。

- `system_purpose`: `unclassified` / `household_allocation` など内部用途（null 可）
- `visibility`: `standard`（既定）または `expanded`

SetupWizard の Registration Service が追加する SubAccount は既定で `visibility = standard`。「未分類」など内部用途を持つものは `system_purpose = unclassified` を設定する。

---

# 既存実装のポイント

## 消費税の状態は FiscalYear を正とする

`GeneralBusinessInitializer::initialize()` は消費税状態を `FiscalYear.is_taxable` / `FiscalYear.is_tax_exclusive` に保存する。`BusinessUnit` には持たせない。税抜経理は現時点で未対応（`is_tax_exclusive = true` を受けたら例外）。

## 銀行口座 Todo の責務分離

`BankAccountTodoHandler::execute()` は次を 1 トランザクションで行う。

- 銀行口座 SubAccount（「その他の預金」配下）の作成
- 期首残高がある場合の期首仕訳への反映

内部では `BankAccountRegistrationService` が SubAccount 作成と期首仕訳追記の両方を扱い、期首仕訳追記は `OpeningEntryRegistrar` / `TransactionRevisor` に委譲する。

## UI と会計処理の変換

利用者には日常語で聞き、内部で会計処理に変換する。

```text
この年のはじめに残っていた金額
  ↓
Todo の入力（TodoCard）
  ↓
TodoHandler::execute()
  ↓
Registration Service（BankAccount / CashOnHand / OpeningBalance）
  ↓
期首仕訳（1本を追加・修正）
```
