# 事業専用現金 SetupWizard 仕様

> このドキュメントは「何を・なぜ」を扱う仕様書である。
> 全体方針は [setupwizard-spec.md](setupwizard-spec.md) を参照。
> 実装の「どう作るか」は [setupwizard-design.md](setupwizard-design.md) を参照。

## 目的

事業用として分けている現金を登録し、その年の開始金額を確定させる。

この SetupWizard は次の2つを同時に扱う。

- 事業用現金そのもの（年度に依存しない管理対象）
- その年のはじめの金額（年度に依存する期首状態）

構造は [銀行口座 SetupWizard](setupwizard-bank-account-spec.md) と同じで、口座番号などの識別情報がない分だけ簡素になる。

```text
事業用現金そのもの
  -> BusinessUnit / SubAccount

この年のはじめの金額
  -> FiscalYear / Opening Setup
```

---

## 位置づけ

- 初回 SetupWizard の質問2「事業専用の現金」（key: `cash_on_hand`）に対する Dashboard カードとして表示する
- 詳細設定はここで完了させる

---

## 表示条件

```text
cash_on_hand_answer = yes で生成された wizard_cash_on_hand Todo が pending
```

### answer 別の見え方

#### answer = yes

`GeneralBusinessInitializer::registerRequestedTodos()` が `wizard_cash_on_hand` Todo を作り、次のカードとして並ぶ。

```text
事業用の現金を登録しましょう
```

#### answer = no

Todo は作られず、Dashboard には表示されない。現行実装に `unknown` はない。

---

## 画面構成

1画面で完結する。複数の現金管理単位（レジ現金・金庫・イベント用現金など）は、同じ画面に行を並べて一気に登録する。

```text
1画面: 事業用現金の一覧入力

  行1:  表示名 / この年のはじめの金額
  行2:  表示名 / この年のはじめの金額
  [＋ 現金を追加]

  [保存]
```

### 銀行口座との違い

| 項目 | 銀行口座 | 事業専用現金 |
| --- | --- | --- |
| 銀行名・口座種別・下4桁 | あり（任意） | なし |
| 表示名 | 必須 | 必須 |
| 開始残高 | 必須 | 必須 |

現金は識別情報が少ないため、表示名で区別する。行の構成が単純な分、銀行口座 SetupWizard より簡素になる。

---

## 入力欄（各行）

| 項目 | 必須 | 説明 |
| --- | --- | --- |
| 表示名 | 必須 | 例:「レジ現金」「金庫」「小口現金」「イベント用現金」 |
| この年のはじめの金額 | 必須 | 年始時点の有り高。円単位 |

### 画面文言案

```text
事業用として分けている現金を登録してください。
複数ある場合は、行を追加してまとめて登録できます。

レジ現金、金庫、小口現金、イベント用現金などが対象です。
普段使いの財布の中のお金は、ここでは登録しなくても大丈夫です。
```

### 表示名の表示例（プレースホルダ）

```text
レジ現金
金庫
小口現金
イベント用現金
```

#### 金額欄の補足（opening_context = first_year の場合）

```text
開業時点で入っていた金額を入力してください。
```

#### 金額欄の補足（opening_context = carry_forward の場合）

```text
前年の決算書の「現金」の期末残高を、この年のはじめの金額として入力してください。
```

### 「わからない」の扱い

金額欄に「わからない」は用意しない。

理由: 手元の現金は数えれば確認できるため。

Todo 全体を「あとで登録する」形で残すことは許すが、現行の Todo モデルに `skipped` は存在しない。後回しにする場合は `pending` のまま Dashboard に残る。

---

## 完了条件

### Todo status の遷移

| status | 遷移条件 |
| --- | --- |
| `pending` | `cash_on_hand_answer = yes` で Todo が発行されてから、まだ Handler の実行が成功していない |
| `completed` | `CashOnHandTodoHandler::execute()` が成功し、`Todo::markCompleted()` が呼ばれた |
| `dismissed` | 利用者が明示的にこの Todo を取りやめた |

`answer = no` の場合は Todo 自体が発行されない。

### completed の判定条件

`CashOnHandTodoHandler::execute()` は次を1トランザクションで行い、成功すると Todo を `completed` にする。

- 「現金」勘定配下に、入力された事業用現金ごとに `SubAccount` を作成する
- 開始残高が 0 を超える現金について、期首仕訳へ追記する

---

## answer 遷移

`cash_on_hand_answer` は初回 SetupWizard の Step 4 で確定させ、以降は `initial_setup_data` に固定される。銀行口座 SetupWizard と同じく、初回完了後に回答を変える UI は現時点で持たない。

---

## Opening Setup との連携

登録した現金の開始金額は、Opening Setup の期首仕訳に組み込まれ、元入金の自動計算に反映される。

Wizard 内では元入金を明示しない。銀行口座 SetupWizard と同じ方針。

---

## 扱わないもの

### 普段使いの財布・個人の現金

事業専用として分けていない現金は登録させない。

事業の支払いを個人の現金で行った場合は、取引入力時に「支払元: 個人のお金」（内部では `事業主借`）として扱う。

### 小口現金の運用ルール

小口現金の定額前渡制などの運用ルールは、この Wizard では扱わない。

---

## 画面文言まとめ

| 会計用語（内部） | UI 表現 |
| --- | --- |
| 期首残高 | この年のはじめに残っていた金額 |
| 期首仕訳 | （表示しない） |
| 元入金 | （表示しない） |
| 現金（勘定科目） | 現金 / 事業用の現金 |
| 事業主借 | 個人のお金 |

---

## まとめ

事業専用現金 SetupWizard は、銀行口座 SetupWizard の簡易版として、次を1画面で完了させる。

```text
1. 事業用の現金を行として登録する（BusinessUnit / SubAccount）
2. 各行にこの年のはじめの金額を入力する（FiscalYear / Opening Setup）
3. 複数ある場合は行を追加してまとめて登録する
```
