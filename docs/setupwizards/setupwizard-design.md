# SetupWizard 設計

> このドキュメントは「どう作るか」を扱う設計書である。
> 画面・文言・質問・表示ルールなど「何を・なぜ」は [setupwizard-spec.md](setupwizard-spec.md) を参照。

---

# 全体構成

SetupWizard は、大きく次の3層で構成する。

| 層 | 役割 | 永続化 |
| --- | --- | --- |
| Livewire コンポーネント | 画面の描画と入力途中の状態の保持 | しない（コンポーネントのプロパティ） |
| Service | ドメイン処理（BusinessUnit / FiscalYear / 期首仕訳 / 個別 setup） | 各ドメインモデルへ |
| 初回導線データ | 初回 SetupWizard の回答と完了記録 | `initial_setup_data` |

「入力途中の状態はモデルにしない・判定結果は永続化する」という分離が全体の前提になる（後述）。

---

# Livewire コンポーネント設計

## 初回 SetupWizard

初回 SetupWizard は 1 つの Livewire コンポーネントで、複数 Step を持つ。

```text
App\Livewire\SetupWizard
```

### 方針

- Step 遷移は `public int $step` で管理する（現行実装を踏襲）
- 各 Step の入力値はコンポーネントのプロパティに持つ
- これらの入力途中の状態はモデルとして保存しない
- `submit()` 時に `GeneralBusinessInitializer` を1回だけ呼び、まとめて確定する

### 現行との差分

現行の `SetupWizard`（`app/Livewire/SetupWizard.php`）は、期首残高・売上補助科目まで1画面群で入力させている。本設計では、初回はほぼ「判定」に絞り、詳細入力は個別ウィザードへ移す。

## 個別 SetupWizard

個別 SetupWizard は、Dashboard のカードから開く。フォームの形が違うため、原則 **key ごとに専用の Livewire コンポーネント**を持つ。ただし定期支出・定期収入はフォームが近いため、1コンポーネントを `type` で出し分ける（下記）。

```text
App\Livewire\Setup\BankAccountSetupWizard
App\Livewire\Setup\CashOnHandSetupWizard
App\Livewire\Setup\FixedAssetSetupWizard
App\Livewire\Setup\InventorySetupWizard
App\Livewire\Setup\CounterpartySetupWizard
App\Livewire\Setup\RecurringTransactionSetupWizard  // recurring_expense / recurring_income を type で共用
```

### 方針

- 各コンポーネントは入力途中の状態のみ保持する
- 完了時に、対応する `*SetupService` を呼ぶ
- Service がドメインモデルへ保存する

### 定期支出・定期収入の共用（recurring_expense / recurring_income）

- `RecurringTransactionSetupWizard` は、開く key（`recurring_expense` / `recurring_income`）に応じて `type`（`expense` / `income`）を受け取り、支出モード・収入モードを出し分ける
- カード・answer・status は key ごとに独立（`recurring_expense` / `recurring_income`）。コンポーネントと `RecurringTransactionSetupService` のみ共用する
- 各行は任意で `Counterparty` を紐づけられる。`RecurringTransactionSetupService` は入力に `counterparty_id`（任意）を受け取り、`RecurringTransactionPlan.counterparty_id` に保存する。紐付け候補は当該 `business_unit_id` の `Counterparty` に限る

## Dashboard カード

```text
App\Livewire\SetupDashboard （またはダッシュボード内のカード一覧コンポーネント）
```

- `InitialSetupData` と各ドメインモデルの状態から「表示すべきカード一覧」を判定して描画する
- カードの見出し・優先度・遷移先は、後述の「key → 設定マッピング」に従う

---

# key → 設定マッピング

各 SetupWizard は、初回 SetupWizard が保存した `InitialSetupData` の回答と、各ドメインモデルの実在状態を組み合わせて判定する。

- **初回回答（DB に保存）**: `bank_account_answer` など。初回導線で 1 回だけ保存する。
- **固定の設定（コードに定義）**: 見出し・優先度・Livewire コンポーネント・Service・成果物の保存先。key ごとに決まっている。

## 設定テーブル

| key | Dashboard カード見出し | 優先度 | Livewire コンポーネント | Service | 成果物の保存先 |
| --- | --- | --- | --- | --- | --- |
| `bank_account` | 銀行口座を登録する | 高 | `BankAccountSetupWizard` | `BankAccountSetupService` | `SubAccount`（その他の預金）＋ 期首仕訳 |
| `cash_on_hand` | 事業専用現金を登録する | 高 | `CashOnHandSetupWizard` | `CashOnHandSetupService` | `SubAccount`（現金）＋ 期首仕訳 |
| `fixed_asset` | 固定資産を登録する | 高 | `FixedAssetSetupWizard` | `FixedAssetSetupService` | `FixedAsset` ＋ 期首仕訳 |
| `inventory` | 在庫の扱いを確認する | 高 | `InventorySetupWizard` | `InventorySetupService` | 棚卸資産の期首仕訳 |
| `counterparty` | 取引相手を登録する | 低 | `CounterpartySetupWizard` | `CounterpartySetupService` | `Counterparty` |
| `recurring_expense` | 毎月・毎年の支払いを登録する | 低 | `RecurringTransactionSetupWizard`（`type = expense`） | `RecurringTransactionSetupService` | `RecurringTransactionPlan`（`type = expense`） |
| `recurring_income` | 毎月・毎年の収入を登録する | 低 | `RecurringTransactionSetupWizard`（`type = income`） | `RecurringTransactionSetupService` | `RecurringTransactionPlan`（`type = income`） |

`recurring_expense` / `recurring_income` は、キー・カード・answer / status は分けるが、フォームの形が近いため Livewire コンポーネントと Service は共用し、`type`（`expense` / `income`）で出し分ける。優先度低グループでは、紐付けの前提となる `counterparty` を定期支出・定期収入より前に並べる。

消費税は Step 5 で課税 / 免税を確定させ、`fiscal_years.is_taxable` に保存する。詳細な消費税設定（本則 / 簡易 / 2割特例 / 税抜経理など）は消費税計算機能の実装時に扱うため、現時点では専用の SetupWizard も Dashboard カードも設けない。

申告方法（青色 / 白色）も初回 SetupWizard でも Dashboard でも扱わない。決算書作成フローで扱うため、`fiscal_years` に列も追加しない。

売掛金（請求後入金の売上）と家事按分（共用支払い）は、SetupWizard ではなくそれぞれ売上登録画面・経費登録画面で扱う。詳細は [setupwizard-spec.md](setupwizard-spec.md) を参照。

在庫は初回 SetupWizard の質問項目には含めない。継続事業（`opening_context = carry_forward`）で開始時点の棚卸資産が必要になる可能性があるため、Dashboard 側の個別 SetupWizard として扱う。

## 設定の持ち方（コード側）

```php
FiscalYearSetupKey::BANK_ACCOUNT => [
    'label'     => '銀行口座を登録する',
    'priority'  => 'high',
    'component' => Setup\BankAccountSetupWizard::class,
    'service'   => BankAccountSetupService::class,
],
FiscalYearSetupKey::FIXED_ASSET => [
    'label'     => '固定資産を登録する',
    'priority'  => 'high',
    'component' => Setup\FixedAssetSetupWizard::class,
    'service'   => FixedAssetSetupService::class,
],
// ...
```

この定義は、`FiscalYearSetupKey`（key の集合）とあわせて1箇所に置き、Dashboard カード生成と個別ウィザードの起動の両方から参照する。

## 新モデルは作らない

各 key の成果物は、すべて既存のドメインモデル（`SubAccount` / `FixedAsset` / `RecurringTransactionPlan` / `Counterparty` / 期首仕訳）に保存する。

家事按分の初期割合を保持する新モデル（当初検討していた `HouseholdAllocationDefault` など）は、家事按分を経費登録画面側で扱う方針にしたため不要になった。

---

# 値の集合（定数クラス）

`answer` / `status` に入る文字列の集合は、既存の `JournalEntry::TAX_TYPE_*` などと同じモデル定数方式のクラスで定義する。これは永続化の要否とは別で、値のバリデーションと参照のためである。

## SetupAnswer

```php
class SetupAnswer
{
    public const YES = 'yes';

    public const NO = 'no';

    public const UNKNOWN = 'unknown';

    public static array $values = [
        self::YES,
        self::NO,
        self::UNKNOWN,
    ];
}
```

初回 SetupWizard では `YES` / `NO` だけを使う。`UNKNOWN` は個別 SetupWizard や後続判定で必要になった場合にのみ扱う。

## SetupStatus

```php
class SetupStatus
{
    public const NOT_NEEDED = 'not_needed';

    public const PENDING = 'pending';

    public const COMPLETED = 'completed';

    public const SKIPPED = 'skipped';

    public static array $values = [
        self::NOT_NEEDED,
        self::PENDING,
        self::COMPLETED,
        self::SKIPPED,
    ];
}
```

---

# SetupWizard の状態管理

## コンポーネントの入力途中の状態は保存しない

初回 SetupWizard および個別 SetupWizard の「入力途中の状態」（現在の step、まだ確定していないフォームの値）は、モデルとして永続化しない。

これらは Livewire コンポーネントのプロパティとして、その場限りのメモリ上でのみ保持する。

## 初回回答は永続化する

一方で、Dashboard に表示する個別 SetupWizard を判定するための初回回答は、セッションやリロードをまたいで必要になる。

そのため、これらは使い捨てのメモリ空間では不足で、`initial_setup_data` として永続化する。

| 種類 | 例 | 保存方法 |
| --- | --- | --- |
| 入力途中の状態 | 現在の step、未確定の入力値 | 保存しない（コンポーネントのプロパティ） |
| 初回回答 | 銀行口座の有無、固定資産の有無、開始年 | `initial_setup_data` に保存 |
| 確定した年度設定 | 消費税、opening_context | `fiscal_years` のカラム |

---

# データ設計

## initial_setup_data

初回 SetupWizard の回答を管理する。

```php
Schema::create('initial_setup_data', function (Blueprint $table) {
    $table->id();

    $table->foreignId('business_unit_id')
        ->constrained()
        ->cascadeOnDelete()
        ->comment('対象事業体ID');

    $table->integer('year')
        ->comment('記録を始める年');

    $table->string('opening_context')
        ->comment('開始状態。first_year, carry_forward');

    $table->boolean('is_taxable')
        ->default(false)
        ->comment('消費税申告が必要かどうか');

    $table->string('bank_account_answer');
    $table->string('cash_on_hand_answer');
    $table->string('fixed_asset_answer');
    $table->string('recurring_expense_answer');
    $table->string('recurring_income_answer');
    $table->string('counterparty_answer');

    $table->timestamp('completed_at')
        ->nullable()
        ->comment('初回セットアップ完了日時');

    $table->timestamps();

    $table->unique('business_unit_id');
});
```

## fiscal_years の追加項目

年度そのものに強く紐づく基本設定は `fiscal_years` に持たせる。

```php
Schema::table('fiscal_years', function (Blueprint $table) {
    $table->string('opening_context')
        ->default('first_year')
        ->comment('期首設定の文脈。first_year, carry_forward');

    $table->boolean('is_taxable')
        ->default(false)
        ->comment('課税事業者なら true、免税事業者なら false');
});
```

---

# 期首仕訳の作り方

期首仕訳は、年度につき1本のみとする。

複数のカード（銀行口座・現金・固定資産・在庫など）から少しずつ入力されるため、下書きを別テーブルに貯めるのではなく、その1本の期首仕訳を追加・修正できる API を用意し、各カードはそれを呼ぶ。

```text
カードで残高を入力
  ↓
期首仕訳（1本）に、その科目の行を追加・修正する
  ↓
元入金の行を差額として再計算する
```

これにより、下書き用の一時テーブルを持たずに、常に整合した期首仕訳を1本だけ維持できる。

## OpeningEntryRegistrar の拡張

現状の `OpeningEntryRegistrar`（`app/Services/OpeningEntryRegistrar.php`）は、次の点を拡張する。

- 2本目の登録で例外を投げるのではなく、既存の期首仕訳に対する追加・修正を許可する
- 借方の許可科目を、資産科目だけでなく負債科目（買掛金・未払金・借入金）や売掛金にも広げる
- 追加・修正のたびに、元入金の行を資産・負債の差額として再計算する
- 元入金は利用者に入力させず、常に差額として自動計算する

現状は `register()` が単発登録で、`is_opening_entry` が既にあると `DomainException` を投げる。この単発前提を、追加・修正（upsert）できる形に変える。

---

# サービス設計

## BusinessUnitSetupService

### 責務

- `BusinessUnit` を作成する
- 標準 `Account` を作成する
- 標準 `SubAccount` を作成する
- 作成者を単一オーナー（`business_units.user_id`）として設定する
- 選択中の事業体に設定する

---

## InitialSetupData

### 責務

- 初回 SetupWizard の回答を 1 レコードにまとめて保存する
- `GeneralBusinessInitializer` 向けの input 配列へ変換する
- `FiscalYear` に確定保存する前の導線データを保持する

---

## OpeningSetupService

### 責務

- 各カードから渡された開始残高を、1本の期首仕訳へ追加・修正する
- 銀行口座・現金・売掛金・在庫・買掛金・未払金・借入金などの開始状態を扱う
- 固定資産 setup と連携する
- 必要な検証を行う
- 元入金の差額計算を含め、`OpeningEntryRegistrar` の追加・修正 API を呼ぶ

---

## Individual Setup Services

個別 SetupWizard はそれぞれ専用サービスを持つ。

```text
BankAccountSetupService
CashOnHandSetupService
FixedAssetSetupService
InventorySetupService
RecurringTransactionSetupService
CounterpartySetupService
```

---

# 既存実装の修正

## GeneralBusinessInitializer の修正

消費税の状態は `FiscalYear` を正とし、`BusinessUnit` には持たせない。

`GeneralBusinessInitializer`（`app/Setup/Initializers/GeneralBusinessInitializer.php`）は、初回 SetupWizard の submit 時に単一 transaction の中で `BusinessUnit` 作成、`InitialSetupData` 保存、`FiscalYear` 作成を行う。

そのため、初期化処理を次のように修正する。

- `BusinessUnit` を作成する
- `InitialSetupData` を `business_unit_id` 配下に保存する
- `InitialSetupData` を `toGeneralBusinessInitializerInputs()` で整形する
- 整形済み input から `FiscalYear` を作成する
- 消費税・税抜経理の状態は `FiscalYear` にのみ保存する

## 銀行口座 setup の責務分離

銀行口座の setup では、ユーザーの使いやすさを優先し、銀行口座の登録と開始残高の入力を同じ画面で扱ってよい。

ただし、内部では責務を分ける。

```text
銀行口座登録
  -> SubAccount

開始残高
  -> Opening Setup（期首仕訳）
```

## UI と会計処理の変換

利用者には日常語で聞き、内部で会計処理に変換する。

```text
この年のはじめに残っていた金額
  ↓
Opening Setup
  ↓
OpeningEntryRegistrar（追加・修正 API）
  ↓
期首仕訳
```
