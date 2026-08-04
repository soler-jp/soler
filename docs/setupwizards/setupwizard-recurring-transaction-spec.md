# 定期的な収入・支出 SetupWizard 仕様

> このドキュメントは「何を・なぜ」を扱う仕様書である。
> 全体方針は [setupwizard-spec.md](setupwizard-spec.md) を参照。
> `RecurringTransactionPlan` のモデル/API 仕様は [recurring-transaction-design.md](../recurring-transaction-design.md) を参照。
> 実装の「どう作るか」は [setupwizard-design.md](setupwizard-design.md) を参照。

> **実装状況**:
> - `RecurringExpenseTodoHandler` / `RecurringIncomeTodoHandler` は実装済み（`AbstractRecurringTransactionPlanTodoHandler` を継承）。
> - `GeneralBusinessInitializer` は `wizard_recurring_expenses` Todo のみ発行する。`wizard_recurring_incomes` Todo を発行する枝は未実装のため、収入カードは現状 Dashboard に自動では現れない。

## 目的

毎月・毎年くり返す収入と支出を登録し、月次確認を楽にする。

登録した内容は `RecurringTransactionPlan` として保存し、予定取引の生成に使う。

```text
定期的な収入・支出のテンプレート
  -> RecurringTransactionPlan
```

この Wizard は入力を楽にするための設定であり、数字に直接影響しない。Dashboard の優先度は「低」。

---

## 定期支出と定期収入は独立した item にする

収入と支出は、性質も入力方式も違う。1つにまとめると認知負荷が高いため、
初回 SetupWizard の質問から Dashboard カードまで、**2つの独立した item** として扱う。

```text
定期支出（recurring_expense）: 毎月・毎年の支払いを登録する
定期収入（recurring_income） : 毎月・毎年の収入を登録する
```

### 分ける理由

| | 定期支出 | 定期収入 |
| --- | --- | --- |
| よくある項目 | 多くの事業者に共通（電気・水道・家賃など） | 事業ごとに大きく異なる |
| 初期表示 | プリセットを最初から表示（チェック済み） | プリセットは候補として提示（チェックなし） |
| 主な操作 | 使わないものを外す＋概算金額を入れる | 自分の収入を行として追加する |
| 会計上の向き | 費用 | 収益（源泉徴収の修飾あり） |

支出は「用意された項目から削る」、収入は「自分で足す」を基本操作にする。

### answer と Todo は item ごとに 1:1

初回 SetupWizard で、定期支出・定期収入をそれぞれ別の質問として聞く（質問5=定期支出、質問6=定期収入）。
そのため、`initial_setup_data` の回答カラムも Todo 種別も別々に持つ。

| item | answer カラム | todo_type |
| --- | --- | --- |
| 定期支出 | `recurring_expense_answer` | `wizard_recurring_expenses` |
| 定期収入 | `recurring_income_answer` | `wizard_recurring_incomes`（Handler は実装済み、Todo 発行は未実装） |

2つは完全に独立している。「支出はあるが、くり返す収入はない」といった事業でも、それぞれ別々に yes / no を答えられる。`unknown` は現行実装にない。

> このファイルは、性質が近い定期支出・定期収入をまとめて記述しているが、
> データ上・UI 上は2つの独立した Wizard（Todo / カード）である。

---

## 表示条件

### 定期支出カード

```text
recurring_expense_answer = yes で生成された wizard_recurring_expenses Todo が pending
```

### 定期収入カード（将来）

```text
recurring_income_answer = yes で生成された wizard_recurring_incomes Todo が pending
```

### answer 別の見え方

#### answer = yes

Dashboard に次のカードとして並ぶ。

```text
（定期支出）毎月・毎年の支払いを登録しましょう
（定期収入）毎月・毎年の収入を登録しましょう
```

#### answer = no

Todo は作られず、そのカードは表示されない（支出・収入それぞれ独立に判定する）。現行実装に `unknown` はない。

---

## 支出カード

### タイトル

毎月・毎年くり返す支払いを登録します

### 画面構成

1画面で完結する。複数の支払いは「次へ」でページを分けず、同じ画面に行を並べて一気に登録する。

```text
1画面: 定期的な支出の一覧入力

  ☑ 電気代        金額 / 周期 / 支払日
  ☑ 水道代        金額 / 周期 / 支払日
  ☑ 家賃          金額 / 周期 / 支払日
  ...（プリセットが最初からチェック済みで並ぶ）
  [＋ 支払いを追加]

  [保存]
```

### プリセット行（最初からチェック済みで表示）

よくある支払いを、あらかじめ行として並べておく。利用者は使わないもののチェックを外す。

```text
電気代
水道代
ガス代
家賃
携帯電話代
インターネット代
サーバー代
駐車場代
保険料
会計ソフト利用料
サブスク代
```

### 各行の入力

| 項目 | 必須 | 説明 |
| --- | --- | --- |
| 使う / 使わない（チェック） | ― | チェックを外すとその行は登録しない |
| 名称 | 必須 | プリセットは既定値が入る。編集可 |
| 金額 | 任意（0 可） | 1回あたりの概算金額（税込）。空欄・0 でも保存できる |
| 周期 | 必須 | 毎月／毎年（プリセットの既定は毎月） |
| 支払月・支払日 | 任意 | 予定取引の生成日に使う |
| 取引相手 | 任意 | 登録済みの `Counterparty` から選ぶ。`RecurringTransactionPlan.counterparty_id` に紐づく |

### 金額は概算・0 円でよい

金額は必須にしない。空欄や 0 円でも保存できる。

理由: 定期支出は実際に支払った金額で確定するため、SetupWizard の時点で正確な金額は不要である。
ただし、水道光熱費や携帯電話代など、毎月ほとんど変わらないものは概算を入れておくと月次確認が楽になる。

### 画面文言案

```text
毎月・毎年、決まって支払っているものを登録してください。
よくある項目をあらかじめ表示しています。
使わないものはチェックを外してください。

金額は、実際に支払った金額で確定します。
水道光熱費や携帯電話代など、ほとんど変わらないものや概算金額を入力しておくと便利です。
（あとで変わる支払いは、空欄のままでも構いません。）
```

### 操作方針

- **使わないプリセットはチェックを外す**（または行を削除する）
- **金額はわかる範囲で入れる**。空欄・0 円でもよい
- **足りないものは「＋ 支払いを追加」で行を足す**

### 方針

- チェックが付いた各行を `RecurringTransactionPlan`（`type = expense`）として登録する（金額 0 を含む）
- 税込経理を前提とするため、金額は税込で受け取る

---

## 収入カード

### タイトル

毎月・毎年くり返す収入を登録します

### 画面構成

1画面で完結する。複数の収入は「次へ」でページを分けず、同じ画面に行を並べて一気に登録する。

```text
1画面: 定期的な収入の一覧入力

  行1:  名称 / 金額 / 周期 / 入金日 / 源泉徴収
  行2:  名称 / 金額 / 周期 / 入金日 / 源泉徴収
  [＋ 収入を追加]

  [保存]
```

### 入力候補（チェックなしで提示）

収入は事業ごとに異なるため、プリセットは「例」として示すだけにし、既定ではチェックしない。
候補をクリックすると、名称が入った行が追加される。

```text
顧問料・委託料
家賃収入
サブスク売上
定額の請負・保守料
```

### 各行の入力

| 項目 | 必須 | 説明 |
| --- | --- | --- |
| 名称 | 必須 | 例:「〇〇社 顧問料」 |
| 金額 | 任意（0 可） | 1回あたりの概算金額（税込）。空欄・0 でも保存できる |
| 周期 | 必須 | 毎月／毎年 |
| 入金月・入金日 | 任意 | 予定取引の生成日に使う |
| 取引相手 | 任意 | 登録済みの `Counterparty` から選ぶ。`RecurringTransactionPlan.counterparty_id` に紐づく |
| 源泉徴収されるか | 任意 | される場合は源泉徴収税額を入力 |
| 源泉徴収税額 | 源泉ありの場合のみ | 1回あたりの源泉徴収税額 |

### 金額は概算・0 円でよい

支出カードと同じく、金額は必須にしない。空欄・0 円でも保存できる。
実際の入金額で確定するため、SetupWizard では概算で構わない。

### 画面文言案

```text
毎月・毎年、決まって入ってくる収入を登録してください。

金額は、実際の入金額で確定します。概算金額を入れておくと月次確認が楽になります。

報酬から所得税があらかじめ差し引かれて入金される場合は、
「源泉徴収されている」を選び、差し引かれる金額を入力してください。
```

### 操作方針

- **「＋ 収入を追加」で行を足す**（候補をクリックすると名称が入った行が追加される）
- 複数の収入を同じ画面でまとめて登録できる

### 方針

- 各行を `RecurringTransactionPlan`（`type = income`）として登録する（金額 0 を含む）
- 源泉徴収ありの収入は `is_withholding = true` とし、`withholding_tax_amount` を持たせる
- 源泉徴収付き収入は、単一貸方（売上）に対して借方を2行（入金額・源泉徴収税）に分ける（[recurring-transaction-design.md](../recurring-transaction-design.md) に従う）
- 源泉徴収税を受ける借方補助科目は、通常「事業主貸」を指定する（利用者には見せず、内部で決定してよい）

---

## 取引相手（Counterparty）との紐付け

各行には、任意で取引相手を紐づけられる。紐づけると `RecurringTransactionPlan.counterparty_id` に保存され、
生成される予定取引にも取引先が付与される（`CounterpartySummaryCalculator` の集計対象になる）。

### 強制はしない（順序の固定もしない）

取引相手の登録を、定期支出・定期収入の前提条件にはしない。

Dashboard の並びでは取引相手を定期支出・定期収入より前に置くが（優先度低グループ内での先行案内）、順序を保証・強制はしない。

理由:

- 取引相手 SetupWizard は Dashboard 優先度「低」であり、先に必ず終わっている保証がない
- 紐付けは「あると便利」だが、なくても定期取引は登録・生成できる
- 順序を固定すると初回導線が固くなり、`unknown` を尊重する全体方針とも噛み合わない

### ヒントメッセージで先行登録を促す

取引相手を先に登録しておくと紐付けが楽になるため、定期支出・定期収入のカード内に案内を出す。

#### 取引相手が1件も登録されていない場合

```text
取引相手を先に登録しておくと、この収入・支払いに紐づけられます。
「取引相手を登録する」から先に登録すると便利です。（あとで紐づけることもできます。）
```

- 案内から取引相手 SetupWizard（[setupwizard-counterparty-spec.md](setupwizard-counterparty-spec.md)）へ遷移できるようにする
- 取引相手を登録して戻ってきたら、各行の「取引相手」欄で選べるようになる

#### 取引相手が登録済みの場合

- 各行の「取引相手」欄をプルダウンなどで選択できるようにする
- 案内メッセージは出さない、または控えめにする

### あとから紐づけられる

紐付けは必須でないため、定期取引を先に登録し、あとで取引相手を作って紐づけてもよい。
通常利用開始後の予定取引の確認・調整の中で、取引相手を後付けできるようにする。

---

## 完了条件

定期支出・定期収入は、それぞれ独立した Todo として完了する。answer も Todo も別々に持つ。

### 定期支出の Todo status

| status | 遷移条件 |
| --- | --- |
| `pending` | `recurring_expense_answer = yes` で Todo が発行されてから、まだ Handler の実行が成功していない |
| `completed` | `RecurringExpenseTodoHandler::execute()` が成功し、`Todo::markCompleted()` が呼ばれた |
| `dismissed` | 利用者が明示的にこの Todo を取りやめた |

### 定期収入の Todo status（将来）

| status | 遷移条件 |
| --- | --- |
| `pending` | `recurring_income_answer = yes` で Todo が発行されてから、まだ Handler の実行が成功していない |
| `completed` | `RecurringIncomeTodoHandler::execute()` が成功し、`Todo::markCompleted()` が呼ばれた |
| `dismissed` | 利用者が明示的にこの Todo を取りやめた |

### completed の判定条件（各カード共通）

`AbstractRecurringTransactionPlanTodoHandler` は `plans` を 1 件以上要求する（`EMPTY_PLANS_MESSAGE` を参照）。保存時に少なくとも 1 件の `RecurringTransactionPlan` を作れないと `completed` にはならない。金額は 0 円でも登録として成立する。

---

## answer 遷移

`recurring_expense_answer` と `recurring_income_answer` は独立し、互いに影響しない。いずれも初回 SetupWizard の Step 4 で確定させ、以降は `initial_setup_data` に固定される。現行実装に `unknown` はなく、初回完了後に回答を変える UI もまだ持たない。

「支出だけ登録して収入は後回し」「収入は no、支出だけ設定する」のような組み合わせは、初回 SetupWizard で自然に表現できる。

---

## Opening Setup との連携

この Wizard は期首状態を作らない。Opening Setup とは連携しない。

登録した `RecurringTransactionPlan` は、通常利用開始後に予定取引を生成し、利用者が確認・確定する。

---

## 扱わないもの

### 予定取引の自動記帳

`RecurringTransactionPlan` から生成された予定取引は、自動では記帳しない。利用者が確認・確定して初めて記帳される（[recurring-transaction-design.md](../recurring-transaction-design.md) の `confirmTransaction()` に従う）。

### 変動費・都度の取引

くり返しでない、都度発生する支出・収入は、この Wizard では扱わない。都度の取引入力で扱う。

（金額が毎回変わるだけの「くり返す支払い」は、概算 0 円で登録してよい。ここで除外するのは、そもそも定期的でないもの。）

### 消費税の税抜処理

税抜経理は初版では扱わない。金額は税込で受け取る。

---

## 画面文言まとめ

| 会計用語（内部） | UI 表現 |
| --- | --- |
| RecurringTransactionPlan | 毎月・毎年の収入 / 支払い |
| type = expense | 支払い |
| type = income | 収入 |
| is_withholding | 源泉徴収されている |
| withholding_tax_amount | 差し引かれる金額 |
| 事業主貸（源泉の借方） | （表示しない。内部で決定） |
| 予定取引 | 毎月・毎年の予定 |
| Counterparty / counterparty_id | 取引相手 |

---

## まとめ

定期支出・定期収入は、認知負荷を下げるため **独立した2つの item（カード）** とし、
それぞれ1画面で複数登録を完了させる。

```text
定期支出（recurring_expense）: くり返す支払い（プリセットから削る＋概算金額）（RecurringTransactionPlan: expense）
定期収入（recurring_income） : くり返す収入（自分で追加、源泉対応）（RecurringTransactionPlan: income）
```

- 支出は「用意された項目を削る」、収入は「自分で足す」を基本操作にする
- 金額は概算・0 円でよい（実際の金額で確定するため）
- `answer` も `status` も item ごとに 1:1 で持ち、支出・収入は完全に独立する

数字に直接影響しない「入力を楽にする」設定であり、Dashboard の優先度は低い。
