# 取引相手 SetupWizard 仕様

> このドキュメントは「何を・なぜ」を扱う仕様書である。
> 全体方針は [setupwizard-spec.md](setupwizard-spec.md) を参照。
> `Counterparty` の適格判定・取引集計の設計は [counterparty-design.md](../counterparty-design.md) を参照。
> 実装の「どう作るか」は [setupwizard-design.md](setupwizard-design.md) を参照。

## 目的

よく請求する相手や、よく支払う相手を登録し、売上や支払いの入力を楽にする。

登録した内容は `Counterparty` として保存する。

```text
取引相手
  -> Counterparty
```

この Wizard は入力を楽にするための設定であり、数字に直接影響しない。Dashboard の優先度は「低」。

---

## 位置づけ

- 初回 SetupWizard の質問6「よく使う取引相手」（key: `counterparty`）に対する Dashboard カードとして表示する
- Dashboard の優先度は「低」（入力を楽にするもの）

---

## 表示条件

```text
counterparty_answer = yes で生成された wizard_counterparty Todo が pending
```

### answer 別の見え方

#### answer = yes

`GeneralBusinessInitializer::registerRequestedTodos()` が `wizard_counterparty` Todo を作り、次のカードとして並ぶ。

```text
取引相手を登録しましょう
```

#### answer = no

Todo は作られず、Dashboard には表示されない。現行実装に `unknown` はない。

---

## 画面構成

1画面で完結する。複数の取引相手は「次へ」でページを分けず、同じ画面に行を並べて一気に登録する。

```text
1画面: 取引相手の一覧入力

  行1:  取引相手名 / メモ
  行2:  取引相手名 / メモ
  [＋ 取引相手を追加]

  [保存]
```

各行が1つの取引相手に対応する。行の追加・削除がその場でできる。

---

## 入力欄（各行）

現行の `CounterpartyTodoHandler` は次の 2 項目を受け付ける。

| 項目 | 必須 | 説明 |
| --- | --- | --- |
| 取引相手名 | 必須 | 会社名・屋号・個人名など。同じ名前は同時入力・既存登録済みのどちらでもエラー |
| メモ | 任意 | 備考。担当者名など |

### 画面文言案

```text
よく請求する相手や、よく支払う相手を登録してください。
複数いる場合は、行を追加してまとめて登録できます。
登録しておくと、売上や支払いの入力が楽になります。
あとで登録しても問題ありません。
```

### 方針

- 各行を `Counterparty` として登録する
- 売掛金・買掛金・売上高の補助科目は作らない（取引相手ごとに勘定科目を分けない）
- 「請求先／支払先／両方」の区分は現行の `Counterparty` に持たせていない。必要になった段階でモデルとハンドラの両方に追加する

---

## 完了条件

### Todo status の遷移

| status | 遷移条件 |
| --- | --- |
| `pending` | `counterparty_answer = yes` で Todo が発行されてから、まだ Handler の実行が成功していない |
| `completed` | `CounterpartyTodoHandler::execute()` が成功し、`Todo::markCompleted()` が呼ばれた |
| `dismissed` | 利用者が明示的にこの Todo を取りやめた |

`answer = no` の場合は Todo 自体が発行されない。

### completed の判定条件

現行の `CounterpartyTodoHandler` は `counterparties` を `required|array|min:1` で受けるため、1件以上を「保存」した場合のみ Todo が `completed` になる。同名の Counterparty が既に登録済みだとバリデーションエラーになる。

### 「1つも登録せずに終える」場合

1人も登録しないままの「保存」は現状バリデーションで弾かれる。後回しにしたい場合は Todo を `pending` のまま残す。

---

## answer 遷移

`counterparty_answer` は初回 SetupWizard の Step 4 で確定させ、`initial_setup_data` に固定される。現行実装に `unknown` はなく、初回完了後に回答を変える UI もまだ持たない。

---

## Opening Setup との連携

この Wizard は期首状態を作らない。Opening Setup とは連携しない。

### 期首の売掛金・買掛金との関係

取引相手の登録と、期首時点の売掛金・買掛金の登録は別物である。

- 取引相手そのもの → この Wizard（`Counterparty`）
- 期首の売掛金・買掛金の金額 → Opening Setup（銀行口座 SetupWizard などと同じ期首の扱い）

この Wizard では金額を扱わない。取引相手の名簿を作るだけにする。

---

## 適格請求書発行事業者（インボイス）の扱い

### この Wizard では確定させない

適格請求書発行事業者かどうか（適格判定）は、この Wizard では必須にしない。

理由:

- 適格判定は初期状態で「未確定（unknown）」を許す設計になっている（[counterparty-design.md](../counterparty-design.md)）
- 後から過去日付で「実は適格だった」と判明するケースを、履歴（`CounterpartyQualificationEvent`）で扱える

### 方針

- SetupWizard では取引相手の名簿づくりに専念する
- 適格判定は、必要になったタイミング（仕入・支払いの消費税処理が必要になったとき）に別途確認する
- 初回登録時点の適格判定は「未確定」を既定とする

---

## 扱わないもの

### 取引相手ごとの補助科目

売掛金・買掛金・売上高を取引相手ごとの補助科目に分けることはしない。取引相手は `Counterparty` として独立に管理する。

### 請求書の発行

請求書テンプレートや発行機能は、この Wizard では扱わない。

### 振込先口座情報

取引相手の振込先口座などの情報は、必要になったときに別途扱う。この Wizard では名簿の最小項目のみ。

---

## 画面文言まとめ

| 会計用語（内部） | UI 表現 |
| --- | --- |
| Counterparty | 取引相手 |
| 適格請求書発行事業者 | （この Wizard では扱わない） |

---

## まとめ

取引相手 SetupWizard は、次を1画面で完了させる。

```text
1. よく取引する相手を行として名簿に登録する（Counterparty）
2. 複数いる場合は行を追加してまとめて登録する
```

数字に直接影響しない「入力を楽にする」設定であり、金額・適格判定・補助科目は扱わない。
