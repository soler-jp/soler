# Counterparty Qualification Design

このドキュメントは、取引先の適格判定をどう保存し、どう過去日時点で判定するかを整理した設計メモである。

## 目的

- 取引先の現在状態と履歴の役割を分けて定義する
- 後から届いた報告をどう反映するかを明確にする
- `unknown` を含む判定ルールを固定する

## 基本方針

取引先の適格判定は、次の2層で扱う。

- `counterparties.qualification_status`
  - 現在の状態を表す
- `counterparty_qualification_events`
  - 状態変更の履歴を表す

現在値だけでなく履歴を残すことで、後から届いた報告や、特定日時点の判定を扱えるようにする。

## 状態の種類

適格判定は次の3値を持つ。

- `unknown`
- `qualified`
- `non_qualified`

### `unknown`

`unknown` は「未確定」を表す。

ただし、現行ルールでは `unknown` へは変更できない。

`unknown` は次の用途に限定する。

- 取引先作成直後の初期値
- 適格判定がまだ確定していない状態の表現

### `qualified`

適格事業者であることを表す。

### `non_qualified`

非適格事業者であることを表す。

## 更新ルール

`setQualificationStatus()` で変更できるのは `qualified` と `non_qualified` だけである。

- `unknown` への変更は拒否する
- 変更時は現在値を更新し、履歴を必ず記録する

これにより、`unknown` を「履歴の終点」として使わず、初期状態に限定できる。

## 履歴イベント

`CounterpartyQualificationEvent` は、状態変更の事実を保存する。

保持する主な情報は次の通り。

- `qualification_status`
- `effective_from`
- `recorded_at`

### `effective_from`

この状態が実際に有効になる日時である。

報告日と有効日が異なる場合に使う。

例:

- 3/12 に「1/4 から適格だった」と報告を受けた
- この場合、`recorded_at` は 3/12 でも、`effective_from` は 1/4 にできる

### `recorded_at`

このイベントを記録した日時である。

いつ入力されたかを追跡するために使う。

## 時点判定

`qualificationStatusAt(Carbon $date)` は、その日時点の適格判定を返す。

判定の考え方は次の通り。

- `unknown` イベントは履歴判定からは除外する
- 最初に確定した状態を基準値とする
- その後の `effective_from` が指定日時以下の最新イベントを採用する
- 指定日時より前に確定イベントがなければ、最初の確定状態を遡及適用する

このルールにより、後から登録された「過去から適格だった」という報告も扱える。

### 例 1: 後から適格になった報告

- 1/3 に `unknown` で作成
- 1/10 に `qualified` を登録

この場合は、1/1, 1/3, 1/8, 1/10 のいずれも `qualified` と判定される。

### 例 2: 適格と非適格の切り替え

- 1/3 に `qualified`
- 10/1 に `non_qualified`

この場合は、10/1 より前は `qualified`、10/1 以降は `non_qualified` となる。

### 例 3: `unknown` を挟むケース

現行ルールでは `unknown` への変更はできないため、履歴上は発生させない。

そのため、「一度 `qualified` にしたあと `unknown` に戻す」という運用は想定しない。

## 実装上の責務

### `Counterparty`

- 現在の `qualification_status` を持つ
- 履歴リレーションを持つ
- 状態変更時に履歴を記録する
- 時点判定の入口を提供する

### `CounterpartyQualificationEvent`

- 1 回の判定変更を表す
- 現在値ではなく履歴の事実を保存する
- `effective_from` と `recorded_at` の差分を表現できる

## 今後の拡張余地

この設計は「後から過去日付で適格だったことが分かる」ケースを支える。

一方で、次のような別ルールが必要になった場合は再設計が必要になる。

- `unknown` を履歴上の状態として再利用したい
- `unknown` を境界として前後を分断したい
- 有効期間の終端 `effective_to` を明示したい
