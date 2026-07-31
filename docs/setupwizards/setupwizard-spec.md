# SetupWizard 仕様

> このドキュメントは「何を・なぜ」を扱う仕様書である。
> 実装の「どう作るか」（Livewire コンポーネント構成、サービス設計、DB スキーマ、key→設定マッピングなど）は [setupwizard-design.md](setupwizard-design.md) を参照。

## 目的

このドキュメントは、Soler の初回 SetupWizard を再設計した仕様である。

従来の SetupWizard は、次の複数の責務を一つの流れで扱っていた。

- `BusinessUnit` の作成
- `FiscalYear` の作成
- 事業用現金・銀行口座の登録
- 期首残高の登録
- 消費税設定
- 固定資産・在庫・取引相手などの初期確認

本仕様では、初回 SetupWizard の役割を「すべての詳細設定を完了させること」ではなく、次のように定義する。

```text
初回 SetupWizard は、Soler が利用者に必要な準備を案内するための入口である。
```

そのため、初回 SetupWizard では、詳細入力を最小限にし、Yes / No 形式で利用者の状況を把握する。

詳細設定は、初回ログイン後の Dashboard に表示する個別 SetupWizard に分割する。

---

# 全体方針

## 基本方針

- 初回 SetupWizard は短めにする
- SetupWizard では、詳細設定ではなく「必要な準備の判定」を行う
- Dashboard に、必要な個別 SetupWizard をカードとして表示する
- 「会計に詳しくない人」が使えるよう、UI では会計用語を前面に出さない
- ただし、内部モデルは会計上の責務ごとに分離する
- `BusinessUnit` の setup と `FiscalYear` の setup を混ぜない
- 期首設定は独立した setup として扱う
- 銀行口座の登録と、その年度の開始残高は責務を分ける
- 固定資産、取引相手、定期的な収入・支出などは Dashboard 側の個別 SetupWizard で扱う

## 初回導線

```text
初回 SetupWizard
  ↓
BusinessUnit 作成
InitialSetupData 保存
FiscalYear 作成
標準 Account 作成
  ↓
初回 Dashboard
  ↓
必要な個別 SetupWizard をカード表示
  ↓
通常利用開始
```

## SetupWizard の位置づけ

```text
初回 SetupWizard
= Soler を使い始めるための入口
= 必要な追加設定を仕分ける場所

Dashboard の個別 SetupWizard
= 数字を正しくするための設定
= 入力を楽にするための設定
= 決算前に困らないための設定
```

---

# 責務の分離

## BusinessUnit Setup

事業そのものに関する設定を扱う。

年度に依存しない情報をここに置く。

### 対象

- 事業名
- 事業の種類
- 標準勘定科目
- 標準補助科目
- 事業用現金・銀行口座・カードなどの管理対象
- ユーザーとの紐付け（単一オーナー方式。`business_units.user_id`）

### BusinessUnit に置かないもの

- 記録開始年
- 期首残高
- 消費税設定
- 青色申告 / 白色申告
- 固定資産の当年度償却状態
- 年度締め状態

---

## FiscalYear Setup

年度ごとの設定を扱う。

### 対象

- 記録する年
- 新規開業か、前年から継続か
- 消費税申告の要否
- 期首設定の状態
- 固定資産 setup 状態
- 在庫 setup 状態
- 定期的な収入・支出 setup 状態

### 考え方

消費税は、事業全体ではなく年度ごとに変わる可能性がある。

そのため、`BusinessUnit` ではなく `FiscalYear` 側に持たせる。

---

## Opening Setup

その年度の開始時点の状態を扱う。

### 対象

- 事業用現金の開始金額
- 銀行口座の開始金額
- 売掛金
- 棚卸資産
- 開始時点で存在する固定資産
- 買掛金
- 未払金
- 借入金

元入金は利用者に入力させない。

元入金は、上記の資産・負債の差額として自動計算する。

### 方針

利用者には「期首仕訳」と言わない。

画面上では次のように表現する。

```text
この年のはじめに残っていた金額
```

または、

```text
Soler を始める時点の金額
```

期首仕訳をどう組み立てるか（1本の期首仕訳を追加・修正する仕組み、`OpeningEntryRegistrar` の拡張）は [setupwizard-design.md](setupwizard-design.md) を参照。

---

# 初回 SetupWizard 仕様

## 目的

初回 SetupWizard の目的は、次の2つに絞る。

1. Soler を開始するために最低限必要なデータを作る
2. Dashboard に表示すべき個別 SetupWizard を判定する

詳細設定はここで完了させない。

---

## Step 1: 事業名

### タイトル

あなたの事業について教えてください

### 入力項目

- 事業名

### 画面文言案

```text
屋号や、あとで見て分かりやすい名前を入力してください。
屋号がない場合は、「個人事業」などの名前でも構いません。
```

### 作成・更新するもの

- `BusinessUnit`（`user_id` に作成者を設定する単一オーナー方式）
- 標準 `Account`
- 標準 `SubAccount`

---

## Step 2: 記録を始める年

### タイトル

何年分から記録を始めますか？

### 入力項目

- 記録開始年

### 画面文言案

```text
Soler で記録を始める年を選んでください。
```

例:

```text
2026年分から記録する
```

### 作成・更新するもの

- `InitialSetupData` に記録開始年を保存し、完了時にその値を使って `FiscalYear` を作成する

---

## Step 3: 開始状態

### タイトル

この年は、どの状態から始めますか？

### 選択肢

#### この年に新しく事業を始めた

```text
前年の決算書から引き継ぐ金額はありません。
開業のために用意した現金・預金などの元手は、このあと入力できます。
```

#### 前年以前から事業を続けている

```text
前年の決算書から、現金・銀行口座・売掛金・借入金などを引き継ぎます。
詳しい設定は、Soler を始めたあとに行えます。
```

「わからない」は用意しない。この年に新しく始めたか、前年以前から続けているかは、利用者が必ず判断できるため。

### 内部値

```php
'opening_context' => 'first_year'
```

```php
'opening_context' => 'carry_forward'
```

### 作成・更新するもの

- `InitialSetupData` に保存し、完了時にその値を使って `FiscalYear.opening_context` を確定する

---

## Step 4: 確認すること

### タイトル

事業に当てはまるものを確認します

### 目的

詳細設定が必要な項目を Yes / No で仕分ける。

### 表示方針

すべての質問に、次の2択を用意する。

```text
はい
いいえ
```

ここで選んだ内容は、使い始めてから変更できる前提で進める。
あとで変更できる前提で、今わかる範囲で選べるようにする。

---

### 質問1: 事業用の銀行口座

```text
事業用として管理している銀行口座はありますか？
```

### 補足文言

```text
事業専用、または事業のお金として残高を管理したい銀行口座があれば「はい」を選んでください。
生活費にもよく使う個人口座は、登録しなくても大丈夫です。
```

### key

```text
bank_account
```

---

### 質問2: 事業専用の現金

```text
事業用として分けている現金はありますか？
```

### 補足文言

```text
レジ現金、金庫、小口現金、イベント用現金などがある場合は「はい」を選んでください。
普段使いの財布の中のお金は、ここでは登録しなくても大丈夫です。
```

### key

```text
cash_on_hand
```

---

### 質問3: 固定資産

```text
仕事で使う高額なものはありますか？
```

### 補足文言

```text
車、パソコン、機械、設備など、長く使う高額なものがある場合は「はい」を選んでください。
```

### key

```text
fixed_asset
```

---

### 質問4: 毎月・毎年くり返す支払い

```text
毎月・毎年くり返す支払いはありますか？
```

### 補足文言

```text
家賃・スマホ代・インターネット代・サーバー代・サブスクなどの支払いがある場合は「はい」を選んでください。
```

### key

```text
recurring_expense
```

---

### 質問5: 毎月・毎年くり返す収入

```text
毎月・毎年くり返す収入はありますか？
```

### 補足文言

```text
毎月の顧問料・家賃収入・サブスク売上などの収入がある場合は「はい」を選んでください。
```

### key

```text
recurring_income
```

---

### 質問6: よく使う取引相手

```text
よく請求する相手や、よく支払う相手はありますか？
```

### 補足文言

```text
登録しておくと、売上や支払いの入力が楽になります。
あとで登録しても問題ありません。
```

### key

```text
counterparty
```

---

## Step 5: 消費税申告

### タイトル

xxxx 年の消費税の申告は必要ですか？

### 選択肢

#### 必要

```text
課税事業者として記録します。
詳しい消費税設定はあとで確認できます。
```

#### 不要

```text
免税事業者として記録します。
```

「わからない」は用意しない。消費税の申告義務の有無は、記録開始時点で必ずどちらかに確定させる。

### 内部値

```php
'is_taxable' => true
```

```php
'is_taxable' => false
```

### 作成・更新するもの

- `InitialSetupData` に保存し、完了時にその値を使って `FiscalYear.is_taxable` を確定する

### 方針

課税事業者・免税事業者のどちらでも記録に対応する。

税抜経理は初期版では扱わず、税込経理を前提にする。

```php
'is_tax_exclusive' => false
```

この項目は利用者には表示しない。

---

## Step 6: 確認して開始

### タイトル

Soler を始める準備ができました

### 表示するもの

- 事業名
- 記録を始める年
- 新規開業 / 前年から継続
- 消費税申告の要否
- Dashboard に表示される開始準備

### 画面文言案

```text
ここまでの内容をもとに、Soler を使い始める準備をします。

詳しい設定が必要なものは、Dashboard に表示されます。
あとから確認しながら進められます。
```

### ボタン

```text
Soler を始める
```

---

# 初回 Dashboard 仕様

## 目的

初回 Dashboard では、SetupWizard の回答をもとに、必要な個別 SetupWizard をカードとして表示する。

## 表示イメージ

```text
2026年分の開始準備

□ 銀行口座を登録する
□ 事業専用現金を登録する
□ 固定資産を登録する
□ 取引相手を登録する
□ 毎月・毎年の支払いを登録する
□ 毎月・毎年の収入を登録する
```

継続事業（`opening_context = carry_forward`）の場合は、必要に応じて次も表示する。

```text
□ 在庫の扱いを確認する
```

## 基本ルール

### answer = yes

具体的な setup カードを表示する。

例:

```text
固定資産を登録しましょう
```

### answer = no

通常はカードを表示しない。

ただし、決算前チェックでは再確認してもよい。

例:

```text
固定資産は「なし」と登録されています。
車・パソコン・機械などを事業で使っている場合は登録してください。
```

---

# 個別 SetupWizard

## 共通 UI 方針

すべての個別 SetupWizard は、次の UI 方針を共通で持つ。

### 複数登録は1ページで行う

同じカテゴリ（銀行口座・現金・固定資産・定期支出・定期収入・取引相手）の中で複数を登録する場合、
「次へ」でページを分けない。

1つの画面の中に複数の行を並べ、その場で追加・削除・編集できるようにする。

```text
悪い例:
  1件目を入力 → [次へ] → 2件目を入力 → [次へ] → ...

良い例:
  1画面に複数行を並べる
    行1: ...
    行2: ...
    [＋ 行を追加]
  まとめて [保存]
```

理由: 同種の登録を1件ずつページ送りするのは手間で、全体像も見えないため。

### プリセット行を先に見せる（該当する Wizard）

よくある項目が決まっている Wizard（特に定期支出）では、
利用者がゼロから考えなくてよいよう、よくある項目をあらかじめ行として表示しておく。

- 該当するものはそのまま金額を入力する
- 該当しないものはチェックを外す（または削除する）
- 足りないものはその場で行を追加する

### 完了は「保存」で確定する

各 Wizard は「次へ」ではなく、1画面での入力を終えたら「保存」で完了させる。

「保存」を押した時点で、必要な登録が1件以上あれば `status = completed` とする。

---

## 1. 銀行口座 SetupWizard

詳細は [setupwizard-bank-account-spec.md](setupwizard-bank-account-spec.md) を参照。

### 概要

- 表示条件: `answer(bank_account) = yes or unknown` かつ `bank_account_setup_status != completed`
- 目的: 事業用として残高管理する銀行口座と、その年の開始残高を登録する
- UI 上は1つの流れで聞くが、内部責務は分ける

```text
銀行口座そのもの
  -> BusinessUnit / SubAccount

この年のはじめの残高
  -> FiscalYear / Opening Setup
```

---

## 2. 事業専用現金 SetupWizard

詳細は [setupwizard-cash-on-hand-spec.md](setupwizard-cash-on-hand-spec.md) を参照。

### 概要

- 表示条件: `answer(cash_on_hand) = yes or unknown` かつ `cash_on_hand_setup_status != completed`
- 目的: 事業用として分けている現金と、その年の開始金額を登録する
- 構造は銀行口座 SetupWizard の簡易版（識別情報がない分だけ簡素）
- 表示例: レジ現金 / 金庫 / 小口現金 / イベント用現金

---

## 3. 固定資産 SetupWizard

詳細は [setupwizard-fixed-asset-spec.md](setupwizard-fixed-asset-spec.md) を参照。

### 概要

- 表示条件: `answer(fixed_asset) = yes or unknown` かつ `fixed_asset_setup_status != completed`
- 目的: 仕事で使う高額なものを登録し、当年度の減価償却見込みを作る
- SetupWizard は入力収集に専念し、台帳登録・償却計算は `DepreciationService` に委ねる
- 開始時点で存在する固定資産は Opening Setup と連携する

---

## 4. 在庫 SetupWizard

詳細は [setupwizard-inventory-spec.md](setupwizard-inventory-spec.md) を参照。

### 概要

- 表示条件: `opening_context = carry_forward` かつ `inventory_setup_status != completed`
- 目的: 仕入・在庫がある事業かを確認し、必要なら開始時点の棚卸資産を登録する
- 他の Wizard と異なり管理対象（SubAccount）は作らず、金額ベースで扱う
- 年末棚卸が必要であることを周知する

---

## 5. 定期支出 SetupWizard

詳細は [setupwizard-recurring-transaction-spec.md](setupwizard-recurring-transaction-spec.md) を参照（定期支出・定期収入を同一ファイルで扱う）。

### 概要

- 表示条件: `answer(recurring_expense) = yes or unknown` かつ `recurring_expense_setup_status != completed`
- 目的: 毎月・毎年くり返す支払いを登録し、月次確認を楽にする
- プリセットを最初から表示して、使わないものを削る方式
- 金額は概算・0 円でも保存できる（実際の金額で確定するため）
- `RecurringTransactionPlan`（`type = expense`）として登録する
- 数字に直接影響しない「入力を楽にする」設定（Dashboard 優先度は低）

---

## 6. 定期収入 SetupWizard

詳細は [setupwizard-recurring-transaction-spec.md](setupwizard-recurring-transaction-spec.md) を参照（定期支出・定期収入を同一ファイルで扱う）。

### 概要

- 表示条件: `answer(recurring_income) = yes or unknown` かつ `recurring_income_setup_status != completed`
- 目的: 毎月・毎年くり返す収入を登録し、月次確認を楽にする
- 事業ごとに異なるため、候補は例示のみで自分で追加する方式
- 金額は概算・0 円でも保存できる（実際の金額で確定するため）
- `RecurringTransactionPlan`（`type = income`）として登録する（源泉徴収あり・なしに対応済み）
- 数字に直接影響しない「入力を楽にする」設定（Dashboard 優先度は低）

---

## 7. 取引相手 SetupWizard

詳細は [setupwizard-counterparty-spec.md](setupwizard-counterparty-spec.md) を参照。

### 概要

- 表示条件: `answer(counterparty) = yes or unknown` かつ `counterparty_setup_status != completed`
- 目的: よく請求する相手や、よく支払う相手を `Counterparty` として登録する
- 売掛金・買掛金・売上高の補助科目は作らない
- 適格請求書発行事業者の状態は、必要に応じて後続で確認する（Wizard では確定させない）
- 数字に直接影響しない「入力を楽にする」設定（Dashboard 優先度は低）

---

# Yes / No の扱い

初回 SetupWizard では Yes / No のみを扱う。

| answer | 意味 |
| --- | --- |
| `yes` | 該当すると答えた |
| `no` | 該当しないと答えた |
値の集合をどう定義するか（`SetupAnswer` クラス）は [setupwizard-design.md](setupwizard-design.md) を参照。

---

# setup status の扱い

Yes / No は「該当するかどうか」である。

それとは別に、setup の進行状態を持つ。

| status | 意味 |
| --- | --- |
| `not_needed` | 該当しないため設定不要 |
| `pending` | 設定が必要だが未完了 |
| `completed` | 設定完了 |
| `skipped` | 利用者があとで設定することを選んだ |

## answer と status の組み合わせ例

```text
answer = yes
status = pending
```

意味:

```text
該当すると答えたが、詳細設定はまだ終わっていない
```

```text
answer = no
status = not_needed
```

意味:

```text
該当しないため、設定不要
```

値の集合をどう定義するか（`SetupStatus` クラス）は [setupwizard-design.md](setupwizard-design.md) を参照。

---

# 初回 Dashboard の優先順位

Dashboard に表示する開始準備カードは、次の優先順位で並べる。

## 優先度 高

数字に直接影響するもの。

```text
銀行口座を登録する
事業専用現金を登録する
開始時点の金額を確認する
固定資産を登録する
在庫の扱いを確認する
```

## 優先度 低

入力を楽にするもの。

取引相手は、定期支出・定期収入への紐付けの前提になるため、先に案内する。

```text
取引相手を登録する
毎月・毎年の支払いを登録する
毎月・毎年の収入を登録する
```

---

# SetupWizard で扱わず、入力画面で扱うもの

## 請求後に入金される売上（売掛金）

事前に「請求後に入金される売上があるか」は聞かない。

売上登録画面で「即時入金」「後日入金予定」などを選べるようにし、後日入金なら売掛金として扱う。

そのため、専用の SetupWizard やカードは設けない。

---

## 仕事と私生活で共用する支払い（家事按分）

事前にカテゴリ別の初期割合は設定しない。

経費登録画面の `business_ratio` 入力欄に説明を添え、その場で仕事使用割合を入力してもらう。

そのため、専用の SetupWizard やカード、初期割合を保持するモデルは設けない。

---

# 初回 SetupWizard に入れないもの

## 申告方法（青色 / 白色）

初回 SetupWizard では、青色申告か白色申告かを聞かない。

日々の記録に申告方法は影響しないため、Dashboard にもカードを出さない。

申告方法および青色申告特別控除額は、決算書作成フローで扱う。

---

## 消費税の詳細

初回 SetupWizard では、消費税の申告が必要かどうかだけを扱う。

扱わないもの:

- 本則課税
- 簡易課税
- 2割特例
- 税抜経理
- インボイス登録日
- 課税売上割合

---

## 源泉徴収の詳細

源泉徴収は、売上入力時や入金明細入力時に扱う。

初回 SetupWizard では扱わない。

必要なら Dashboard で「源泉徴収される売上があるか」を確認するカードを追加できる。

---

## 決算書の帳簿外明細

次のような情報は、決算書作成時に扱う。

- 専従者給与の内訳
- 地代家賃の内訳
- 青色申告特別控除額

---

# 方針上の注意

## UI と会計処理を分ける

利用者には、なるべく日常語で聞く。

```text
この年のはじめに残っていた金額
```

内部では、会計上正しい処理に変換する。処理の詳細は [setupwizard-design.md](setupwizard-design.md) を参照。

---

## 個人のお金は登録させすぎない

普段使いの財布、個人口座、個人クレジットカードは、SetupWizard で管理対象として登録させない。

事業の支払いを個人のお金で行った場合は、取引入力時に扱う。

UI 上は次のようにする。

```text
支払元: 個人のお金
```

内部では `事業主借` として処理する。

---

## 消費税は FiscalYear を正とする

消費税の状態は、`FiscalYear` を正とし、`BusinessUnit` には持たせない。

実装上の詳細（`GeneralBusinessInitializer` の修正など）は [setupwizard-design.md](setupwizard-design.md) を参照。

---

## Unknown の扱い

`unknown` は初回 SetupWizard では使わない。

個別 SetupWizard や後続の判定で必要になった場合のみ使う。

---

# 最終まとめ

本仕様では、初回 SetupWizard を詳細設定の場にしない。

初回 SetupWizard は、次の役割に絞る。

```text
1. BusinessUnit を作る
2. FiscalYear を作る
3. 利用者の状況を Yes / No で把握する
4. Dashboard に出す個別 SetupWizard を決める
```

詳細設定は Dashboard に分割する。

```text
銀行口座 SetupWizard
事業専用現金 SetupWizard
固定資産 SetupWizard
在庫 SetupWizard
定期的な支出 SetupWizard（カード）
定期的な収入 SetupWizard（カード）
取引相手 SetupWizard
```

この設計により、初回 SetupWizard で利用者を止めず、Soler 側が必要な準備を順番に案内できる。

```text
SetupWizard は、設定を完了させる場所ではない。
SetupWizard は、Soler が利用者に必要な準備を案内するための入口である。
```
