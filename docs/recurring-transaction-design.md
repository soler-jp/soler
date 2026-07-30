# Recurring Transaction Design

このドキュメントは、`RecurringTransactionPlan` をモデル/API として扱うときの技術仕様を整理したものです。

UI の固定費登録画面は別レイヤーの実装であり、本ドキュメントでは `BusinessUnit::createRecurringTransactionPlan()`、`BusinessUnit::generatePlannedTransactionsForPlan()`、`RecurringTransactionPlan::confirmTransaction()` の振る舞いを対象にする。

## 目的

- 毎月・隔月・毎年発生する取引を、予定取引として一括生成できるようにする
- 支出だけでなく、毎月売上や年次報酬などの定期収入もモデル/API で表現できるようにする
- 源泉徴収された定期報酬（入金額と源泉徴収税を借方 2 行に分ける形）もテンプレート化できるようにする
- 予定取引生成時の仕訳方向、税区分、家事按分の扱いを明示し、`TransactionRegistrar` の正規化ルールから外れないようにする
- 現行実装で支出向けに寄っている箇所と、収入を正式サポートするための変更点を分離して管理する

## 用語

- 定期取引計画
  - `RecurringTransactionPlan`
  - 繰り返し発生する取引のテンプレート
- 予定取引
  - `transactions.is_planned = true`
  - 計画から生成された、まだ確定していない取引
- 確定
  - 予定取引を実績取引へ変える操作
  - 現行実装では同一 `Transaction` の `is_planned` を `false` にし、仕訳を再構成する

## データモデル

`RecurringTransactionPlan` は、基本は単一借方・単一貸方・単一金額のテンプレートとして扱う。
テンプレートの種類は `type`（`expense` / `income`）と `is_withholding`（源泉徴収フラグ）の 2 軸で表す。
`type` はかたちの方向、`is_withholding` は収入時の修飾で、組み合わせで支出 / 収入・源泉なし / 収入・源泉あり
の 3 つの仕訳シェイプになる。

唯一、単一借方の前提を外すのが源泉徴収付き収入（`type = income` かつ `is_withholding = true`）で、
この場合のみ単一貸方（売上）に対して借方を 2 行（入金額・源泉徴収税）に分ける。これは源泉に限定した
固定形であり、任意の複数明細化とは区別する（[対象外](#対象外) を参照）。

- `business_unit_id`
  - 計画が属する事業体
- `name`
  - 生成する取引の `description` に使う
- `interval`
  - `monthly`
  - `bimonthly`
  - `yearly`
- `day_of_month`
  - 生成日の day
  - 対象月にその日が存在しない場合は月末日に丸める
- `month_of_year`
  - `yearly` の対象月
- `start_month`
  - `bimonthly` の開始月
  - `1` なら奇数月、`2` なら偶数月として使う
- `type`
  - テンプレートの方向を表す enum
  - `expense`: 支出
  - `income`: 収入
  - 将来 `transfer`（振替）などの方向を足せるよう、真偽値ではなく enum とする
  - モデル定数（`RecurringTransactionPlan::TYPE_EXPENSE` / `TYPE_INCOME`）で参照する
- `is_withholding`
  - 源泉徴収付き収入かどうかのフラグ
  - `type = income` のときだけ意味を持つ
  - テンプレートの「かたち」を表し、`withholding_tax_amount` の値とは独立に持つ（源泉テンプレートであることが金額に依存しないようにするため）
- `debit_sub_account_id`
  - 生成仕訳の借方補助科目
- `credit_sub_account_id`
  - 生成仕訳の貸方補助科目
- `amount`
  - テンプレート上の本体額（税抜）
  - 支出・収入とも同じ意味で使う
- `tax_amount`
  - テンプレート上の税額
  - 支出・収入とも同じ意味で使う
  - `gross_amount = amount + tax_amount` は、支出では税込支払額、収入では税込入金額を表す
- `tax_type`
  - 税区分
- `business_ratio`
  - 支出の家事按分で使う事業割合
- `withholding_tax_amount`
  - 源泉徴収税額（`is_withholding = true` のときのみ）
  - `amount` / `tax_amount` と同じく固定のテンプレート値として持つ
  - 料率からの自動計算は行わない（[対象外](#対象外)）
- `withholding_sub_account_id`
  - 源泉徴収税を受ける借方補助科目（`is_withholding = true` のときのみ）
  - 個人事業では源泉された所得税は事業の費用ではなく事業主の税金前払いのため、通常は事業主貸を指定する
- `is_active`
  - `false` の計画からは予定取引を生成しない

`type` と `is_withholding`・源泉カラムの整合条件:

- `type = expense` のとき `is_withholding` は必ず `false`
- `is_withholding = true` のとき `withholding_tax_amount > 0` かつ `withholding_sub_account_id` を必須とする
- `is_withholding = false` のとき `withholding_tax_amount` は 0 または null、`withholding_sub_account_id` は null とする

## 繰り返し日付

日付生成は `RecurringTransactionPlan::getPlannedDatesIn(FiscalYear $fiscalYear)` が担当する。

### `monthly`

対象会計年度の開始月から終了月まで、毎月 1 件ずつ生成する。

- `day_of_month = 10` なら各月 10 日
- `day_of_month = 31` で 2 月なら、2 月末日に丸める

### `bimonthly`

`start_month` を起点に 2 か月ごとに生成する。

- `start_month = 1` は 1, 3, 5, 7, 9, 11 月
- `start_month = 2` は 2, 4, 6, 8, 10, 12 月
- `start_month = null` は 1 月開始として扱う

### `yearly`

`month_of_year` と `day_of_month` で年 1 件だけ生成する。

- `month_of_year = null` は 1 月として扱う
- `day_of_month` が対象月に存在しない場合は月末日に丸める

## 予定取引生成

予定取引生成の入口は `BusinessUnit::generatePlannedTransactionsForPlan()` とする。

生成時の不変条件:

- 計画は呼び出し元の `BusinessUnit` に属している必要がある
- 操作者は対象事業体へアクセスできる必要がある
- `is_active = false` の計画は空の `Collection` を返す
- 同じ計画で同じ日付の予定取引が既に存在する場合は再生成しない
- 別計画の同日予定取引は重複扱いしない
- 実際の `Transaction` / `JournalEntry` 作成は `TransactionRegistrar` を経由する

`TransactionRegistrar` を経由するため、通常取引と同じ検証が適用される。

- 会計年度の存在
- 取引日が会計年度内に収まること
- 補助科目が同じ事業体に属すること
- 貸借の総額一致
- 税区分が借方/貸方の向きに合っていること

## 仕訳テンプレート

### 支出

`type = expense` の計画は、支出テンプレートとして扱う。

代表例:

```text
借方: 経費科目
貸方: 現金・預金・事業主借など
```

支出時の入力意味:

- `debit_sub_account_id`
  - 経費・仕入などの発生科目
- `credit_sub_account_id`
  - 支払元
- `tax_type`
  - 借方に付く税区分
  - `taxable_purchases_10` などの仕入・経費系税区分を指定できる
- `business_ratio`
  - 借方の経費行に付く
  - `TransactionRegistrar` の家事按分展開に渡す

`tax_type = null` の場合の既定値:

- `tax_amount > 0`
  - `taxable_purchases_10`
- `tax_amount = 0` または `null`
  - `out_of_scope`

### 収入

`type = income` かつ `is_withholding = false` の計画は、源泉なしの収入テンプレートとして扱う。

代表例:

```text
借方: 現金・預金・売掛金など
貸方: 売上高・雑収入など
```

収入時の入力意味:

- `debit_sub_account_id`
  - 入金先または債権科目
- `credit_sub_account_id`
  - 売上・雑収入などの収益科目
- `tax_type`
  - 貸方に付く税区分
  - `taxable_sales_10` などの売上系税区分を指定できる
- `business_ratio`
  - 収入では使わない
  - 収入に按分の概念は存在しないため、指定されたらバリデーションで拒否する

`tax_type = null` の場合の既定値:

- `tax_amount > 0`
  - `taxable_sales_10`
- `tax_amount = 0` または `null`
  - `out_of_scope`

### 源泉徴収付き収入

`type = income` かつ `is_withholding = true` の計画は、源泉徴収付き収入テンプレートとして扱う。
単一貸方（売上）に対し、借方を入金額と源泉徴収税の 2 行に分ける。

代表例（月額報酬 100,000 円・源泉徴収税 10,210 円、消費税なし）:

```text
借方: 普通預金        89,790   (入金額 = gross_amount - withholding_tax_amount)
借方: 事業主貸        10,210   (源泉徴収税 = withholding_tax_amount)
貸方: 売上高         100,000   (gross_amount、税区分は貸方)
```

追加の入力意味:

- `withholding_tax_amount`
  - 借方 2 行目（源泉徴収税）の `net_amount`
  - 入金額の借方 1 行目は `gross_amount - withholding_tax_amount` として導出する
- `withholding_sub_account_id`
  - 借方 2 行目の補助科目（通常は事業主貸）
- `debit_sub_account_id`
  - 借方 1 行目（入金額）の補助科目

制約:

- `is_withholding = true` は `type = income` でのみ有効。`type = expense` で `is_withholding = true` はバリデーションで拒否する
- `is_withholding = true` のとき `withholding_tax_amount > 0` かつ `withholding_sub_account_id` を必須とする
- `withholding_tax_amount < gross_amount` を満たすこと（入金額が負にならない）
- `is_withholding = false` のとき源泉カラム（`withholding_tax_amount` / `withholding_sub_account_id`）は空にする

貸借は借方合計 `(gross_amount - withholding_tax_amount) + withholding_tax_amount = gross_amount` と
貸方 `gross_amount` で一致する。消費税がある場合も、源泉徴収税額を固定値として持つため、
借方 2 行はいずれも税区分なしの `net_amount` 行で表現でき、貸方の売上税区分だけが税計算に効く。

## `toTransactionData()` の仕様

`RecurringTransactionPlan::toTransactionData()` は、保存済みの計画から `TransactionRegistrar` に渡す raw input を組み立てる。

支出の場合:

```php
[
    'transaction' => [
        'date' => $date->toDateString(),
        'description' => $plan->name,
        'remarks' => null,
        'is_planned' => true,
        'recurring_transaction_plan_id' => $plan->id,
    ],
    'entries' => [
        [
            'sub_account_id' => $plan->debit_sub_account_id,
            'type' => 'debit',
            'gross_amount' => $plan->gross_amount,
            'tax_type' => $purchaseTaxType,
            'business_ratio' => $plan->business_ratio,
        ],
        [
            'sub_account_id' => $plan->credit_sub_account_id,
            'type' => 'credit',
            'net_amount' => $plan->gross_amount,
        ],
    ],
]
```

収入の場合:

```php
[
    'transaction' => [
        'date' => $date->toDateString(),
        'description' => $plan->name,
        'remarks' => null,
        'is_planned' => true,
        'recurring_transaction_plan_id' => $plan->id,
    ],
    'entries' => [
        [
            'sub_account_id' => $plan->debit_sub_account_id,
            'type' => 'debit',
            'net_amount' => $plan->gross_amount,
        ],
        [
            'sub_account_id' => $plan->credit_sub_account_id,
            'type' => 'credit',
            'gross_amount' => $plan->gross_amount,
            'tax_type' => $salesTaxType,
        ],
    ],
]
```

源泉徴収付き収入の場合（借方 2 行）:

```php
[
    'transaction' => [
        'date' => $date->toDateString(),
        'description' => $plan->name,
        'remarks' => null,
        'is_planned' => true,
        'recurring_transaction_plan_id' => $plan->id,
    ],
    'entries' => [
        [
            'sub_account_id' => $plan->debit_sub_account_id,
            'type' => 'debit',
            'net_amount' => $plan->gross_amount - $plan->withholding_tax_amount,
        ],
        [
            'sub_account_id' => $plan->withholding_sub_account_id,
            'type' => 'debit',
            'net_amount' => $plan->withholding_tax_amount,
        ],
        [
            'sub_account_id' => $plan->credit_sub_account_id,
            'type' => 'credit',
            'gross_amount' => $plan->gross_amount,
            'tax_type' => $salesTaxType,
        ],
    ],
]
```

この分岐により、`JournalEntryValidator` の「仕入・経費税区分は借方のみ」「売上税区分は貸方のみ」というルールと整合する。

## 予定取引の確定

予定取引の確定入口は現行では `RecurringTransactionPlan::confirmTransaction()` である。

### 上書き項目

支出計画では、次の上書きを許容する。

- `date`
- `amount`
- `business_ratio`
- `credit_sub_account_id`

支出では `credit_sub_account_id` が支払元を表すため、確定時に支払元を差し替える用途に合う。

収入計画では、確定時に差し替えたい科目は通常 `debit_sub_account_id` である。

- 未収予定を普通預金入金として確定する
- 現金予定を売掛金として確定する

したがって、収入を正式サポートする場合は、確定 API の上書き項目を `type` で分ける。

- 支出
  - 支払元として `credit_sub_account_id` を変更できる
- 収入
  - 入金先として `debit_sub_account_id` を変更できる

### 確定時の仕訳再構成

確定は上書き項目の差し替えだけでなく、予定取引の仕訳を作り直す処理を含む。現行の再構成は
`TransactionRegistrar::buildPlannedJournalEntries()` と `RecurringTransactionPlan::confirmTransaction()`
に実装されており、いずれも支出テンプレートを前提に固定されている。

現行の支出前提:

- `gross_amount` と `tax_type` を常に借方行に付ける
- 金額の基準を貸方行の `net_amount` から取る
- `tax_type` を借方行からコピーする

この前提のままだと、収入の確定は次のように壊れる。

- 税なし収入
  - `tax_type = null` かつ `net_amount == gross_amount` のため、たまたま整合する
- 課税収入
  - 貸方に付いていた売上税区分が捨てられる
  - 税込額を税抜の `net_amount` から拾うため、税額が消えて貸借がずれる

したがって収入を正式サポートするには、上書き項目の分岐だけでなく、確定時の仕訳再構成そのものを
`type` と `is_withholding` で分岐させる。

- 支出
  - 借方に `gross_amount` と仕入・経費税区分
  - 貸方に `net_amount`
  - 金額基準は貸方 `net_amount`
- 収入（源泉なし）
  - 貸方に `gross_amount` と売上税区分
  - 借方に `net_amount`
  - 金額基準は貸方 `gross_amount`（税込入金額）
- 収入（源泉あり）
  - 貸方に `gross_amount` と売上税区分（1 行）
  - 借方に入金額行 `net_amount = gross_amount - withholding_tax_amount` と源泉徴収税行 `net_amount = withholding_tax_amount`（2 行）
  - 金額基準は貸方 `gross_amount`

現行の `buildPlannedJournalEntries()` は借方・貸方を各 1 行しか拾わないため、源泉あり収入の確定では
借方 2 行を保持できない。確定時の再構成は、単一借方の前提を外し、予定取引に紐づく借方行をすべて
拾い直す形にする。

この分岐により、生成側と同じく `JournalEntryValidator` の税区分方向ルールと整合し、課税収入・源泉あり収入も確定できる。

### 将来のリファクタ

将来的には `RecurringTransactionPlan::confirmTransaction()` を薄くし、`PlannedTransactionConfirmer` へ
raw input を渡す形に寄せる。上記の `type` / `is_withholding` 対応は、この薄型化とセットで
`buildPlannedJournalEntries()` の分岐として実装するのが最小構成になる。

## 家事按分

家事按分は支出計画だけを対象にする。

- `business_ratio` は支出計画の借方行に付く
- `TransactionRegistrar` が事業分と家事分を展開する
- `RecurringTransactionPlan` は按分済み仕訳を保持しない
- 生成済み予定取引の按分割合は、確定時の入力で上書きできる

家事按分は経費（借方の費用行）を事業分と家事分に割る概念であり、収入には「按分」という概念が存在しない。
したがって収入計画に `business_ratio` を持たせることはなく、`type = income` の計画に
`business_ratio` が指定された場合はバリデーションで拒否する。黙って無視すると入力ミスに気付けないため、
明示的なエラーにする。

## 現行実装との差分

現行実装で既に満たしていること:

- `interval = monthly / bimonthly / yearly`
- 現行は `is_income`（boolean）で支出/収入を区別している
- 税なし収入を表現できる最低限の仕訳方向
  - 借方に入金先、貸方に売上科目を指定し、`tax_amount = 0` とする場合
- `BusinessUnit::generatePlannedTransactionsForPlan()` が収入計画を拒否しないこと
- 同一計画・同一日付の重複生成防止

現行実装で未対応または支出向けに固定されていること:

- 種別が `is_income`（boolean）のままで、方向の enum `type` と源泉フラグ `is_withholding` に分離されていない
- `RecurringTransactionPlan::toTransactionData()` が常に借方へ `tax_type` を付ける
- `tax_amount > 0` かつ `tax_type = null` の既定税区分が常に `taxable_purchases_10` になる
- `confirmTransaction()` が `credit_sub_account_id` の差し替えだけを想定している
- `buildPlannedJournalEntries()` / `confirmTransaction()` の確定時仕訳再構成が支出前提で固定されている
  - `gross_amount` と `tax_type` を常に借方に付け、金額基準を貸方 `net_amount` から取るため、課税収入の確定で税区分と税額が失われる
- `business_ratio` が収入でもバリデーション上は受け取れてしまう
- 源泉徴収付き収入を表現するカラム（`withholding_tax_amount` / `withholding_sub_account_id`）が存在しない
- `toTransactionData()` / 確定時の再構成が単一借方前提のため、借方 2 行（入金額・源泉徴収税）を生成・保持できない
- UI コンポーネントは支出（固定費）向けであり、収入計画を作成・表示・確定しない

## 実装方針

収入計画をモデル/API として正式サポートする場合の最小変更は次の通り。

1. `is_income`（boolean）を `type`（`expense` / `income` の enum）へリネームするマイグレーションを入れる
   - 既存 `is_income = false` → `type = expense`、`is_income = true` → `type = income` にデータ移行する
   - モデルに定数 `TYPE_EXPENSE` / `TYPE_INCOME` と cast を定義する
2. 源泉フラグ `is_withholding`（boolean）と源泉カラム `withholding_tax_amount` / `withholding_sub_account_id` をマイグレーションで追加する
   - `type = expense` で `is_withholding = true` は拒否
   - `is_withholding = true` のとき `withholding_tax_amount > 0` かつ `withholding_sub_account_id` を必須
   - `is_withholding = true` のとき `withholding_tax_amount < gross_amount` を検証
   - `is_withholding = false` のとき源泉カラムは空
3. `RecurringTransactionPlan::toTransactionData()` を `type` と `is_withholding` で分岐する
4. 支出系税区分の既定値と収入系税区分の既定値を分ける
5. 収入時は `tax_type` を貸方行に付ける
6. `type = income` の計画に `business_ratio` が指定されたらバリデーションで拒否する
7. 源泉あり収入の `toTransactionData()` を、入金額行・源泉徴収税行・売上行の 3 行で組み立てる
8. 確定時の仕訳再構成（`buildPlannedJournalEntries()` / `confirmTransaction()`）を `type` と `is_withholding` で分岐する
   - 収入時は `gross_amount` と売上税区分を貸方に付け、金額基準を貸方 `gross_amount` から取る
   - 収入時は上書きで `debit_sub_account_id`（入金先）を差し替えられるようにする
   - 単一借方の前提を外し、源泉あり収入では借方 2 行を拾い直して保持する
   - これにより課税収入・源泉あり収入の確定でも税区分・税額が保持される
9. モデル/API テストに次を追加する
   - 毎月の税なし収入（生成）
   - 毎年の税なし収入（生成）
   - 毎月の課税売上（生成）
   - 毎年の課税売上（生成）
   - 源泉徴収付き月次報酬が借方 2 行（入金額・源泉徴収税）で生成されること
   - 税なし収入の確定時に入金先を変更できること
   - 課税売上の確定で売上税区分と税額が保持されること
   - 源泉あり収入の確定で借方 2 行が保持されること
   - 収入計画に `business_ratio` を指定するとバリデーションエラーになること
   - `type = expense` で `is_withholding = true` がバリデーションエラーになること
   - `is_withholding = true` で `withholding_tax_amount = 0` がバリデーションエラーになること
   - `withholding_tax_amount >= gross_amount` がバリデーションエラーになること

## 対象外

初期仕様では次を対象外とする。

- 任意の複数借方・複数貸方明細を持たせること
  - 源泉徴収付き収入（単一貸方 + 借方 2 行）だけは固定形として対応する。それ以外の任意の多明細化は対象外
- 源泉徴収税額の料率からの自動計算（`withholding_tax_amount` は固定のテンプレート値として持つ）
- 消費税と源泉徴収税の端数処理・按分ロジック（源泉徴収税額はユーザーが確定値として入力する前提）
- UI の収入タブ、フォーム、一覧、確定画面
- 取引先の自動付与

## 参考

- `app/Models/RecurringTransactionPlan.php`
- `app/Models/BusinessUnit.php`
- `app/Services/TransactionRegistrar.php`
- `app/Services/PlannedTransactionConfirmer.php`
- `docs/transaction-registration.md`
- `docs/household-allocation-design.md`
- `manual/recurring-transactions.md`
