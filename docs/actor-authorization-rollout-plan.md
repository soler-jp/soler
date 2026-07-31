# actor 認可追加の進め方

## 目的

BusinessUnit 配下のデータ登録・更新・削除は、呼び出し時点で操作主体である `User $actor` を明示し、`AuthorizesBusinessUnitAccess` で fail-closed に認可する。

`auth()` fallback や `?User` による暗黙の未認可動作を減らし、Service 最下層の `TransactionRegistrar::register()` へ actor を追加する前に、上位入口で actor を確定させる。

## 基本方針

1. 上位の公開書き込み入口に `User $actor` を追加する。
2. その入口で `AuthorizesBusinessUnitAccess` による認可を先に行う。
3. その段階では、下位 Service への actor 伝播は必要最小限に留める。
4. 上位入口が actor を持つ状態になってから、利用している Service に actor を追加する。
5. 最後に `TransactionRegistrar::register()` を actor 必須にする。

## 公開入口で先に認可する基準

- 公開書き込みメソッドが下位 Service に単純委譲するだけなら、認可は Service 側に集約してよい。
- 下位 Service を呼ぶ前に関連モデルを検索する、関連データを読む、検証する、または更新 payload を組み立てる場合は、その公開入口の冒頭でも `AuthorizesBusinessUnitAccess` による認可を行う。
- この場合、下位 Service 側の認可は残す。公開入口と Service のどちらも単独で呼ばれる可能性があるため、二重認可は許容する。

## コミット分割の基準

- 1コミットは原則「公開書き込みメソッド1つ」または「Service公開メソッド1つ」。
- ただし呼び出し元が多い場合は、「上位入口の actor 必須化」と「下位 Service への actor 伝播」を別コミットに分ける。
- 認可テストは、そのコミットで確定する公開入口に対して1本追加する。
- テストの既存呼び出し更新が多い場合は、対象入口に直接関係するテストだけを同じコミットに含める。
- `TransactionRegistrar::register()` のような広範囲の下位 Service は、上位入口の準備が終わるまで触らない。

## 機械的強制

`app/Services/**` のクラス／メソッドが未ガードのまま追加されないよう、`tests/Unit/Architecture/ActorAuthorizationTest.php` で以下を機械的に検査する。

- Service クラスは `AuthorizesBusinessUnitAccess` trait を use するか、`#[App\Concerns\SkipActorGuard('理由')]` をクラスに付与する（親クラスに付与すれば子クラスへ継承）。
- trait を use するクラスの public メソッドは、本文で `authorizeBusinessUnitAccess()` を呼ぶ（同クラス内ヘルパー経由も可）か、`#[SkipActorGuard('理由')]` をメソッドに付与する。
- 「read-only 集計・PDF生成・純粋関数ユーティリティ」など actor 不要と判断したクラスは class-level `#[SkipActorGuard]` で理由付き除外する。既存の Calculator 系・PDF 系・CSV パーサ系は初期投入時にすべて除外済み。
- ロールアウトで actor を追加する対象は、`#[SkipActorGuard('TODO: ...')]` の TODO 文言を根拠に段階的に潰す。

## 対応済み

- `BusinessUnit::createFiscalYear(int $year, User $actor)`
- `BusinessUnit::createRecurringTransactionPlan(array $attributes, User $actor)`
- `BusinessUnit::generatePlannedTransactionsForPlan(RecurringTransactionPlan $plan, FiscalYear $fiscalYear, User $actor)`
- `FiscalYearRollover::rollover(FiscalYear $closedYear, FiscalYear $nextYear, User $actor)`
- `OpeningEntryRegistrar::registerForRollover(FiscalYear $fiscalYear, array $entries, array $capitalEntry, User $actor)`
- `FiscalYear::registerOpeningEntry(array $entries, User $actor)`
- `OpeningEntryRegistrar::register(FiscalYear $fiscalYear, array $entries, User $actor)`
- `PlannedTransactionConfirmer::confirm(Transaction $transaction, User $actor, ...)`（下記 推奨順序 2）
- `InventoryClosingService::registerFor(FiscalYear $fiscalYear, array $closingAmounts, User $actor)`（下記 推奨順序 5）
- `DepreciationService::registerTransactionFor(DepreciationEntry $entry, User $actor)`（下記 推奨順序 6）

## 推奨順序

### 1. `RecurringTransactionPlan::confirmTransaction`

対象:

- `app/Models/RecurringTransactionPlan.php`
- `app/Livewire/Recurring/TabList.php`
- `tests/Feature/RecurringTransactionPlanTest.php`
- `tests/Feature/BusinessFlowScenarioTest.php`

現状:

- `confirmTransaction(int $transactionId, array $attributes)` が `auth()->user()` を使っている。
- 下位の `PlannedTransactionConfirmer::confirm()` は actor を受け取れるが、`?User` で nullable。

修正方針:

- `confirmTransaction(int $transactionId, array $attributes, User $actor): ?Transaction` にする。
- `confirmTransaction()` は Service 呼び出し前に transaction / journal entries / sub account を読むため、冒頭で `AuthorizesBusinessUnitAccess` により `$this` を認可する。
- 下位の `PlannedTransactionConfirmer::confirm()` 側の認可も残し、Service単体呼び出しでも fail-closed にする。
- `Livewire\Recurring\TabList::confirm()` では `Auth::user()` を `$actor` として明示的に渡す。
- テストは既存の `$user` をそのまま渡す。

テスト方針:

- `RecurringTransactionPlanTest` に他ユーザーが確定できないケースを維持または追加する。
- `PlannedTransactionConfirmerTest` の既存認可テストと合わせて実行する。

### 2. `PlannedTransactionConfirmer::confirm`

**対応済み。以下は当時の設計メモ。**

対象:

- `app/Services/PlannedTransactionConfirmer.php`
- `app/Services/TransactionRegistrar.php`
- `tests/Feature/PlannedTransactionConfirmerTest.php`
- `tests/Feature/TransactionRegistrarTest.php`

現状:

- `confirm(Transaction $transaction, ?User $user = null, ...)` が nullable actor。
- `TransactionRegistrar::confirmPlanned()` が `auth()->user()` を渡している。

修正方針:

- `confirm(Transaction $transaction, User $actor, array $overrides = [], array $journalEntriesData = []): Transaction` にする。
- `TransactionRegistrar::confirmPlanned(Transaction $transaction, User $actor): Transaction` にする。
- `confirmPlanned()` 内の `auth()->user()` fallback を削除する。
- ここではまだ `TransactionRegistrar::register()` には actor を追加しない。

テスト方針:

- nullable actor で通っていたテストを `$user` 明示渡しへ変更する。
- `confirmPlanned()` の他ユーザー拒否を確認する。

### 3. `FiscalYear::registerTransaction`

対象:

- `app/Models/FiscalYear.php`
- `tests/Feature/FiscalYearTransactionTest.php`
- `tests/Feature/BusinessUnitResolutionTest.php`
- `tests/Unit/BusinessUnitTest.php`
- `tests/Feature/UserTest.php`

現状:

- `registerTransaction(array $transactionData, array $journalEntriesData, ?TransactionRegistrar $registrar = null)` が actor を受け取らない。
- 下位の `TransactionRegistrar::register()` も actor を受け取らない。

修正方針:

- `registerTransaction(array $transactionData, array $journalEntriesData, User $actor, ?TransactionRegistrar $registrar = null): Transaction` にする。
- この段階では model 側で認可するか、直後の Service 伝播コミットまで一時的に actor を保持するかを選ぶ。
- 推奨は、model 側では `AuthorizesBusinessUnitAccess` を使わず、次コミットで `TransactionRegistrar::register()` に渡す前提にすること。ただしコミット単体で認可を成立させたい場合は、この段階だけ model 側で認可してもよい。

テスト方針:

- `FiscalYearTransactionTest` の全呼び出しを `$user` 明示渡しへ更新する。
- 他ユーザーが `registerTransaction()` できないテストを追加する場合は、Service伝播のコミットと重複しないようにする。

### 4. `TransactionRegistrar::register`

対象:

- `app/Services/TransactionRegistrar.php`
- `app/Models/FiscalYear.php`
- `app/Models/BusinessUnit.php`
- `app/Services/OpeningEntryRegistrar.php`
- `app/Services/InventoryClosingService.php`
- `app/Services/DepreciationService.php`
- `app/Services/TransactionRevisor.php`
- `app/Services/CreditCardStatementLineRegistrar.php`
- `app/Livewire/DashboardExpenseInput.php`
- `app/Livewire/DashboardRevenueInput.php`
- 関連する `TransactionRegistrarTest` と集計系テスト

現状:

- `register(?FiscalYear $fiscalYear, array $transactionData, array $journalEntriesData)` が actor を受け取らない。
- `TransactionRegistrar` には `AuthorizesBusinessUnitAccess` trait が既にあるが、`register()` では使っていない。

修正方針:

- `register(?FiscalYear $fiscalYear, array $transactionData, array $journalEntriesData, User $actor): Transaction` にする。
- `fiscalYear === null` の validation より後、閉鎖年度チェックより前に `authorizeBusinessUnitAccess($fiscalYear, $actor, ...)` を入れる。
- actor は `created_by` の自動設定には使わない。既存仕様が明示 `created_by` を扱うなら別コミットで整理する。
- 呼び出し元は、既に上位入口で得た actor を渡す。

テスト方針:

- `TransactionRegistrarTest` に他ユーザー拒否を追加する。
- 既存の大量の直接呼び出しテストは、共通ヘルパー化できる箇所だけを小さく整理し、機械的な actor 追加に留める。
- MySQL group の集計系テストも実行する。

### 5. `InventoryClosingService::registerFor`

**対応済み。以下は当時の設計メモ。**

対象:

- `app/Services/InventoryClosingService.php`
- `tests/Feature/InventoryClosingServiceTest.php`

現状:

- `registerFor(FiscalYear $fiscalYear, array $closingAmounts)` が actor を受け取らない。
- 内部で `TransactionRegistrar::register()` を呼ぶ。

修正方針:

- `registerFor(FiscalYear $fiscalYear, array $closingAmounts, User $actor): ?Transaction` にする。
- Service入口で `authorizeBusinessUnitAccess($fiscalYear, $actor, ...)` を行う。
- `TransactionRegistrar::register()` actor対応後は、その actor を下位へ渡す。

テスト方針:

- 既存の `InventoryClosingServiceTest` に `$user` を渡す。
- 他ユーザー拒否のテストを1本追加する。

### 6. `DepreciationService::registerTransactionFor`

**対応済み。以下は当時の設計メモ。**

対象:

- `app/Services/DepreciationService.php`
- 減価償却仕訳登録を呼ぶテスト

現状:

- `registerTransactionFor(DepreciationEntry $entry)` が actor を受け取らない。
- 内部で `TransactionRegistrar::register()` を呼ぶ。

修正方針:

- `registerTransactionFor(DepreciationEntry $entry, User $actor): void` にする。
- `DepreciationEntry` の `fiscalYear` を対象に `authorizeBusinessUnitAccess()` する。
- `TransactionRegistrar::register()` actor対応後は、その actor を下位へ渡す。

テスト方針:

- 減価償却仕訳登録の成功テストに `$user` を渡す。
- 他ユーザー拒否を追加する。

### 7. `TransactionRevisor::revise`

対象:

- `app/Services/TransactionRevisor.php`
- `tests/Feature/TransactionRevisorTest.php`

現状:

- 入口は `User $user` を受け取り、認可済み。
- 内部で `TransactionRegistrar::register()` を呼ぶが、actor はまだ渡せない。

修正方針:

- `TransactionRegistrar::register()` actor対応後に、既存の `$user` を下位へ渡すだけにする。
- 署名変更は不要。

テスト方針:

- 既存の認可テストを維持する。
- 追加テストは原則不要。`TransactionRegistrar::register()` への伝播確認が必要なら、既存修正テストで十分か確認する。

### 8. `CreditCardStatementLine` と `CreditCardStatementLineRegistrar`

対象:

- `app/Models/CreditCardStatementLine.php`
- `app/Services/CreditCardStatementLineRegistrar.php`
- `tests/Feature/CreditCardStatementLineRegistrarTest.php`

現状:

- model の `registerTransaction()` / `cancelTransactionRegistration()` が `?User $user = null` と `auth()->user()` fallback を持つ。
- Service側も `?User` を受けるが、`AuthorizesBusinessUnitAccess` により null は拒否される。
- `register()` 内部で `TransactionRegistrar::register()` を呼ぶ。

修正方針:

- model公開メソッドを `User $actor` 必須にする。
- Service側も `User $actor` 必須にする。
- `auth()->user()` fallback を削除する。
- `TransactionRegistrar::register()` actor対応後は、登録時に actor を下位へ渡す。

テスト方針:

- 既存テストの呼び出しを `$user` 明示渡しに更新する。
- 他ユーザー拒否テストは既存があれば維持し、nullable actor の挙動に依存するテストをなくす。

### 9. `CreditCardImportBatch::deactivate`

対象:

- `app/Models/CreditCardImportBatch.php`
- `app/Services/CreditCardImport/CreditCardImportService.php`
- `tests/Feature/CreditCardModelsTest.php`
- `tests/Feature/CreditCardImportServiceTest.php`

現状:

- `deactivate(?User $user = null, ?string $reason = null)` が actor nullable。
- Import Service は `$uploadedBy` を渡しているが nullable。
- 関連 `Transaction::deactivate()` にも actor nullable のまま伝播している。

修正方針:

- `CreditCardImportBatch::deactivate(User $actor, ?string $reason = null): void` にする。
- batch の `statement.creditCard.businessUnit` を対象に認可する。
- `CreditCardImportService::import()` は `User $uploadedBy` 必須にする。
- 関連 transaction の無効化へ同じ actor を渡す。

テスト方針:

- batch無効化の成功テストに `$user` を渡す。
- 他ユーザー拒否を追加する。
- CSV再取り込みのテストで actor が伝播することを確認する。

### 10. `Transaction::deactivate`

対象:

- `app/Models/Transaction.php`
- `app/Services/TransactionRegistrar.php`
- `app/Services/TransactionRevisor.php`
- `app/Services/CreditCardStatementLineRegistrar.php`
- `app/Models/CreditCardImportBatch.php`
- `tests/Feature/TransactionTest.php`
- 集計系テスト

現状:

- `deactivate(?User $user = null, ?string $reason = null)` が actor nullable。
- 取引無効化はデータ更新なので、actor必須化が必要。

修正方針:

- `deactivate(User $actor, ?string $reason = null): void` にする。
- `AuthorizesBusinessUnitAccess` を使って transaction 所属 BusinessUnit を認可する。
- 既に上位Serviceで認可済みでも、model公開書き込みメソッドとして fail-closed にする。
- `deactivated_by` は `$actor->id` を保存する。

テスト方針:

- 他ユーザー拒否を `TransactionTest` に追加する。
- 既存の null actor 呼び出しをなくす。

### 11. `BlueReturnInputRegistrar` と `FiscalYear::saveBlueReturnInput(s)`

対象:

- `app/Models/FiscalYear.php`
- `app/Services/BlueReturnInputRegistrar.php`
- `tests/Feature/BlueReturnInputStorageTest.php`
- `tests/Feature/BusinessUnitResolutionTest.php`

現状:

- `saveBlueReturnInputs()` / `saveBlueReturnInput()` が actor を受け取らない。
- Service側も actor を受け取らず、決算書入力を更新している。

修正方針:

- `FiscalYear::saveBlueReturnInputs(array $inputs, User $actor): Collection` にする。
- `FiscalYear::saveBlueReturnInput(string $key, array $value, User $actor): BlueReturnInput` にする。
- `BlueReturnInputRegistrar::saveMany()` / `save()` も `User $actor` 必須にする。
- 認可は Service 側に集約し、model は actor を渡すだけにする。

テスト方針:

- 既存保存テストに `$user` を渡す。
- 他ユーザー拒否を追加する。

### 12. `Counterparty::setQualificationStatus`

対象:

- `app/Models/Counterparty.php`
- `tests/Feature/CounterpartyTest.php`
- `tests/Feature/BusinessUnitResolutionTest.php`

現状:

- `setQualificationStatus(string $qualificationStatus, ?Carbon $effectiveFrom = null)` が actor を受け取らない。
- 取引先の状態更新なので actor 認可が必要。

修正方針:

- `setQualificationStatus(string $qualificationStatus, User $actor, ?Carbon $effectiveFrom = null): void` にする。
- model公開書き込みメソッドとして `AuthorizesBusinessUnitAccess` を直接使う。
- 呼び出し順は nullable Carbon と混同しないよう、`User $actor` を第2引数に固定する。

テスト方針:

- 既存テストに `$user` を渡す。
- 他ユーザー拒否を追加する。

### 13. `BusinessUnit::setCurrentFiscalYear`

対象:

- `app/Models/BusinessUnit.php`
- `tests/Unit/BusinessUnitTest.php`
- `tests/Feature/Livewire/DashboardRevenueInputTest.php`

現状:

- `setCurrentFiscalYear(FiscalYear $fiscalYear)` が actor を受け取らない。
- `setCurrentFiscalYearIfNotSet()` からも呼ばれる。

修正方針:

- ユーザー操作としての `setCurrentFiscalYear(FiscalYear $fiscalYear, User $actor): void` を追加する。
- `setCurrentFiscalYearIfNotSet()` は作成直後の内部補助なので、actorを受け取るか、内部専用メソッドへ分離する。
- 推奨は `setCurrentFiscalYearIfNotSet(FiscalYear $fiscalYear, User $actor): void` にして、`createFiscalYear()` から actor を渡す。

テスト方針:

- 既存テストに `$user` を渡す。
- 他ユーザー拒否を追加する。

## 対象外または別軸で扱うもの

- `User::setSelectedBusinessUnit(BusinessUnit $unit)` は「自分自身が選択する」操作なので、`User $actor` 追加よりも `$this` と `$unit->user_id` の一致で十分。ただし将来 BusinessUnit の共同利用を入れる場合は `canAccess()` に寄せる。
- `BusinessUnit::createWithDefaultAccounts()` はBusinessUnit作成直後の内部初期化として扱う。actor必須化する場合は、`User::createBusinessUnitWithDefaults()` 側から一貫して見直す。
- `Livewire\Admin\Users::createUser()` / `deleteUser()` は BusinessUnit 境界ではなく管理者権限の問題なので、この計画とは別に admin 認可を追加する。
- PDF生成、集計、台帳生成などの読み取り系メソッドは、この計画では対象外。

## 最終到達点

- BusinessUnit配下の公開書き込みメソッドはすべて `User $actor` を必須にする。
- `auth()` は UI / HTTP / Livewire の入口で actor を取り出す用途に限定する。
- model や Service のドメインメソッド内では `auth()` を呼ばない。
- `?User` actor は原則なくし、未認証・actor不明は `AuthorizationException` で拒否する。
- `TransactionRegistrar::register()` は actor 必須かつ `AuthorizesBusinessUnitAccess` による認可済みの最下層登録Serviceにする。
