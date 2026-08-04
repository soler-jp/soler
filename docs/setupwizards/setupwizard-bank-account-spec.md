# 銀行口座 SetupWizard 仕様

> このドキュメントは「何を・なぜ」を扱う仕様書である。
> 全体方針は [setupwizard-spec.md](setupwizard-spec.md) を参照。
> 実装の「どう作るか」（Livewire コンポーネント構成、サービス設計、DB スキーマ、Opening Setup との連携詳細）は [setupwizard-design.md](setupwizard-design.md) を参照。

## 目的

事業用として残高管理する銀行口座を登録し、その年の開始残高を確定させる。

この SetupWizard は次の2つを同時に扱う。

- 銀行口座そのもの（年度に依存しない管理対象）
- その年のはじめの残高（年度に依存する期首状態）

UI 上では1つの流れで聞くが、内部責務は分ける。

```text
銀行口座そのもの
  -> BusinessUnit / SubAccount

この年のはじめの残高
  -> FiscalYear / Opening Setup
```

---

## 位置づけ

- 初回 SetupWizard の質問1「事業用の銀行口座」（key: `bank_account`）に対する Dashboard カードとして表示する
- 詳細設定はここで完了させる
- 完了後は Dashboard から該当カードが消える

---

## 表示条件

Dashboard に次の条件でカードを表示する。

```text
bank_account_answer = yes で生成された wizard_bank_account Todo が pending
```

### answer 別の見え方

#### answer = yes

`GeneralBusinessInitializer::registerRequestedTodos()` が `wizard_bank_account` Todo を作り、Dashboard に次のカードとして並ぶ。

```text
銀行口座を登録しましょう
```

#### answer = no

Todo は作られず、Dashboard には表示されない。

決算前チェックのタイミングで再確認したい場合は、その時点で別途 Todo を作る形になる（現時点でそのような自動再確認機能はない）。

現行実装に `unknown` は存在せず、初回 SetupWizard も yes / no のみを扱う。

---

## 画面構成

### 全体構成

1画面で完結する。複数口座は「次へ」でページを分けず、同じ画面に行を並べて一気に登録する。

```text
1画面: 銀行口座の一覧入力

  行1:  表示名 / 銀行名 / 種別 / 下4桁 / この年のはじめの残高
  行2:  表示名 / 銀行名 / 種別 / 下4桁 / この年のはじめの残高
  [＋ 銀行口座を追加]

  [保存]
```

各行が1つの銀行口座に対応する。行の追加・削除がその場でできる。


---

## 入力欄（各行）

現行の `BankAccountTodoHandler` は次の 2 項目のみを受け付ける。

| 項目 | 必須 | 説明 |
| --- | --- | --- |
| 表示名（銀行名） | 必須 | 一覧で識別しやすい名称。例:「メインバンク」「〇〇銀行 営業用」。`SubAccount.name` に保存 |
| この年のはじめの残高 | 必須（0 以上） | 年始時点の残高。円単位 |

将来的に銀行名 / 口座種別 / 下4桁を分けて持ちたい場合は、`BankAccountTodoHandler::inputSchema()` と `BankAccountRegistrationService` の `bank_accounts` 入力仕様を拡張する。

### 画面文言案

```text
事業用として残高管理する銀行口座を登録してください。
複数ある場合は、行を追加してまとめて登録できます。

生活費にもよく使う個人口座は、登録しなくても大丈夫です。
その場合は、事業の入出金があったときに「個人のお金」として記録できます。
```

#### 残高欄の補足（opening_context = first_year の場合）

```text
開業時点で口座に入っていた金額を入力してください。
```

#### 残高欄の補足（opening_context = carry_forward の場合）

```text
前年の決算書の「期末残高」を、この年のはじめの残高として入力してください。
```

### 方針

- 表示名と残高は必須にする
- 「期首残高」「期首仕訳」という会計用語は UI に出さない
- 内部では Opening Setup の期首仕訳に反映される（詳細は [setupwizard-design.md](setupwizard-design.md) を参照）
- 元入金は利用者に入力させない。ここで入力した残高は、元入金の自動計算に組み込まれる

### 「わからない」の扱い

残高欄に「わからない」は用意しない。

理由: 銀行口座の残高は、通帳・アプリで必ず確認できるため。

Todo 全体を「あとで登録する」形で残すことは許すが、現行の Todo モデルに `skipped` は存在しない。後回しにする場合は `pending` のまま Dashboard に残る。

---

## 完了条件

### Todo status の遷移

| status | 遷移条件 |
| --- | --- |
| `pending` | `bank_account_answer = yes` で Todo が発行されてから、まだ Handler の実行が成功していない |
| `completed` | `BankAccountTodoHandler::execute()` が成功し、`Todo::markCompleted()` が呼ばれた |
| `dismissed` | 利用者が明示的にこの Todo を取りやめた |

`answer = no` の場合は Todo 自体が発行されない。

### completed の判定条件

`BankAccountTodoHandler::execute()` は次を1トランザクションで行い、成功すると Todo を `completed` にする。

- 「その他の預金」配下に、入力された銀行口座ごとに `SubAccount` を作成する（同名は `DomainException`）
- 開始残高が 0 を超える口座について、期首仕訳へ追記する（既存の期首仕訳があれば `TransactionRevisor::revise()` で借方追記・貸方再計算、なければ `FiscalYear::registerOpeningEntry()` で新規登録）

---

## answer 遷移

`bank_account_answer` は初回 SetupWizard の Step 4 で確定させ、以降は `initial_setup_data` に固定される。初回 SetupWizard 完了後に「やっぱりない」となるケースは、現時点では専用の UI を持たない。将来的に Dashboard から回答を変えられるようにするなら、`initial_setup_data.bank_account_answer` を更新する API と、対応する `wizard_bank_account` Todo の扱い（dismiss または削除）を追加する必要がある。

---

## Opening Setup との連携

### 元入金への影響

この Wizard で登録した各口座の開始残高は、Opening Setup の期首仕訳に組み込まれる。

元入金は、次の差額として自動計算される。

```text
元入金 = 資産合計 - 負債合計
```

そのため、銀行口座の開始残高を登録するたびに元入金が変動する。

### 画面での表現

Wizard 内では元入金の変動を明示しない。

利用者に見せるのは、次のみ。

- 登録した各口座の残高一覧
- 全体の資産合計（任意。design 側で判断）

---

## 扱わないもの

次の項目は、この Wizard では扱わない。

### 通帳明細のインポート

CSV や電子明細のインポートは、通常利用開始後の別機能とする。

### 過去仕訳の遡り登録

前年以前の記録をこの Wizard で入力することはしない。

`opening_context = carry_forward` の場合、利用者には「前年の決算書の期末残高」を入力してもらう。

### インボイス・振込先情報

請求書に載せる振込先情報は、Wizard では扱わない。取引相手 SetupWizard や別画面で扱う。

### 個人口座

普段使いの個人口座は、Wizard では管理対象として登録させない。

利用者が個人口座から事業の支払いをした場合は、取引入力時に「支払元: 個人のお金」として扱う（内部では `事業主借`）。

---

## 画面文言まとめ

Wizard 全体を通して、次の日常語で統一する。

| 会計用語（内部） | UI 表現 |
| --- | --- |
| 期首残高 | この年のはじめに残っていた金額 |
| 期首仕訳 | （表示しない） |
| 元入金 | （表示しない） |
| 補助科目 | 口座 / 銀行口座 |
| 事業主借 | 個人のお金 |

---

## まとめ

銀行口座 SetupWizard は、次を1画面で完了させる。

```text
1. 事業用の銀行口座を行として登録する（BusinessUnit / SubAccount）
2. 各行にこの年のはじめの残高を入力する（FiscalYear / Opening Setup）
3. 複数ある場合は行を追加してまとめて登録する
```

内部責務は分けつつ、利用者には日常語で1画面の入力として見せる。

`answer` と `status` を分けて扱うことで、「該当するかどうか」と「設定の進行状態」を独立に管理する。
