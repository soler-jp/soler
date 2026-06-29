# Credit Card Design

このドキュメントは、クレジットカード明細取込機能の初版設計を整理したものである。

CSV明細の保存、レビュー、会計登録の責務を分離し、事業用カードと個人用カードを同じ基盤で扱えるようにする。

## 目的

- カード明細をCSVから全件保存できるようにする
- 必要な行だけを `Transaction` として会計登録できるようにする
- 個人用カードの私用明細も、将来の再判定のために保持できるようにする
- カード会社ごとのCSV差異を Parser に閉じ込められるようにする
- 年またぎや1-2か月遅れの取込でも混乱しないデータ構造にする

## 適用範囲

このドキュメントは次を対象とする。

- クレジットカードマスタ
- 月次明細
- 明細行
- CSV取込履歴
- `Transaction` との関係

このドキュメントは次を対象外とする。

- 画面詳細
- カード会社別 Parser 実装詳細
- 自動仕訳ルールの詳細仕様
- OCR や PDF 取込

## 1. 設計方針

### 1-1. CSVは全件保存する

- クレジットカード明細CSVは、事業利用か私用かに関係なく全件保存する。
- 個人用カードでも、不要と判断した行を削除しない。
- いったん私用扱いにした行も、将来 `Transaction` として登録できる余地を残す。

### 1-2. 明細と会計登録は分ける

- `CreditCardStatementLine` は会計仕訳ではなく、カード明細の原本として扱う。
- 借方科目、貸方科目、税区分、消費税額は `Transaction` 登録時に確定する。
- `CreditCardStatementLine` は `transaction_id` を持ち、登録済み取引との紐付けだけを持つ。

### 1-3. 事業用カードと個人用カードの差はレビュー運用で表現する

- 事業用カードは、全行をレビュー対象とする。
- 個人用カードは、必要な行だけ `Transaction` 登録し、不要な行は `private` として保持する。
- どちらのカードもCSV保存構造は共通にする。

### 1-4. 会計年度判定は利用日基準にする

- 月次明細の請求月や取込日は、会計年度判定の基準にしない。
- 会計上の基準日は `used_on` とする。
- 12月利用、1月請求、2月取込でも、12月分として扱える設計にする。

## 2. モデル構成

### 2-1. CreditCard

カード自体の定義を表す。

主な役割:

- どの事業体のカードかを持つ
- 事業用カードか個人用カードかを持つ
- どの発行会社・国際ブランドのカードかを持つ
- どの Parser を使うかを持つ
- 既定の貸方補助科目候補を持つ
- 明細取込と会計登録の起点となるカード設定を持つ

主なカラム:

- `business_unit_id`
- `name`
- `issuer_name`
- `network`
- `last_four`
- `ownership_type`
  - `business`
  - `personal`
- `parser_key`
- `liability_sub_account_id`
- `owner_draw_sub_account_id`
- `is_active`
- `notes`

補足:

- `CreditCard` は `User` ではなく `BusinessUnit` に属する。
- `issuer_name` は `Orico` や `AEON` のような発行会社を表す。
- `network` は `Visa` や `Mastercard` や `JCB` のような国際ブランドを表す。
- `last_four` は同一 `issuer_name + network` の複数カードを識別する補助情報として使う。
- `business_unit_id` と `name` は一意とし、同一事業体内で同名カードは作らない。

関連:

- `businessUnit()`
  - カードを所有する事業体
- `liabilitySubAccount()`
  - 事業カード向け既定補助科目
- `ownerDrawSubAccount()`
  - 個人カード向け既定補助科目
- `statements()`
  - このカードに紐づく請求明細

`ownership_type` の意味:

- `business`
  - 事業専用カード
  - 全明細をレビュー対象として扱う
  - 既定creditは `未払金-クレジットカード名称`
- `personal`
  - 個人カード
  - 必要な明細だけ選択的に登録する
  - 既定creditは `事業主借`

モデルメソッドの意図:

- `display_label`
  - `issuer_name`、`network`、`last_four` を組み合わせた表示用ラベル
- `requiresFullRegistration()`
  - 事業カードとして全明細レビュー前提かを返す
- `allowsSelectiveRegistration()`
  - 個人カードとして選択登録前提かを返す
- `defaultCreditSubAccountId()`
  - `ownership_type` に応じて既定の貸方補助科目を返す

次コミット向け ToDo:

- `business` の `CreditCard` を作成するときは、`未払金` 配下に `クレジットカード名称` の SubAccount を自動作成する
- 作成した SubAccount を `liability_sub_account_id` に設定する
- この自動作成は `CreditCard` モデルイベントではなく、カード作成フロー用の Action / Service で扱う
- `personal` の `CreditCard` ではカードごとの専用 SubAccount は自動作成せず、既存の `事業主貸` 系 SubAccount を使う前提で設計する
- カード名変更時に SubAccount 名を追随更新するかは別途設計判断する

### 2-2. CreditCardStatement

カード会社の1請求分を表す明細ヘッダを表す。

主な役割:

- 「2026年1月請求分」のような月次単位を持つ
- 明細対象期間と請求・引落日を持つ
- 配下の明細行と取込バッチを束ねる

主なカラム:

- `credit_card_id`
- `statement_year`
- `statement_month`
- `period_start_on`
- `period_end_on`
- `billed_on`
- `paid_on`
- `total_amount`
- `line_count`
- `imported_at`

補足:

- `statement_year` と `statement_month` は請求月であり、会計年度判定には使わない。
- 進捗状態は保存カラムではなく、配下の `statement_lines` から自動算出する。
- 1つのCSVファイルは、原則 1 つの `CreditCardStatement` として扱う。
- 請求期間が月をまたいでも分割しない。
- たとえば 20 日締めのカードで、対象期間が `2026-12-21` から `2027-01-20` のCSVは、`statement_year = 2027`、`statement_month = 1`、`period_start_on = 2026-12-21`、`period_end_on = 2027-01-20` の 1 Statement として保持する。
- 月次会計の切り分けは Statement 単位ではなく、`CreditCardStatementLine.used_on` を基準に行う。

### 2-3. CreditCardStatementLine

カード明細1行を表す。

主な役割:

- CSVから取り込んだ原本データを保持する
- 行単位のレビュー状態を持つ
- 登録済みの `Transaction` と紐づく

主なカラム:

- `credit_card_statement_id`
- `credit_card_import_batch_id`
- `line_number`
- `used_on`
- `posted_on`
- `merchant_name`
- `description`
- `amount`
- `fingerprint`
- `status`
  - `unreviewed`
  - `registered`
  - `private`
  - `duplicate`
  - `ignored`
- `memo`
- `raw_payload`
- `transaction_id`
- `reviewed_by`
- `reviewed_at`

補足:

- `credit_card_statement_id` は「この行がどの請求明細に属するか」を表す。
- `credit_card_import_batch_id` は「この行がどのCSV取込で作られたか」を表す。
- 前者は所属先、後者は作成元であり、意味が異なるため両方を持つ。
- `credit_card_statement_id` により、Statement 配下の一覧取得、進捗計算、未レビュー抽出を直接行える。
- `credit_card_import_batch_id` により、再取込時に旧 batch 由来の行をまとめて追跡・無効化できる。
- `status = private` は「今は私用扱いだが、明細は保持する」を意味する。
- `status = registered` のときだけ `transaction_id` が入る想定で扱う。
- 借方・貸方・税区分・消費税額はこのモデルに持たせない。
- `fingerprint` は、CSV内の行番号ではなく、明細内容から作る論理的な識別子として使う。
- `line_number` は同一 batch 内での物理的な行位置の識別に使い、`fingerprint` は再取込や表記揺れをまたいだ重複検知に使う。
- 同一 Statement 配下で同じ `fingerprint` を持つ active 行があれば、重複候補として `duplicate` 判定に使えるようにする。

### 2-4. CreditCardImportBatch

CSV取込の履歴を表す。

主な役割:

- どのファイルを、いつ、どの Parser で取り込んだかを残す
- 再取込時の監査情報を持つ
- 配下の明細行の作成元を追えるようにする

主なカラム:

- `credit_card_statement_id`
- `uploaded_by`
- `source_filename`
- `source_hash`
- `parser_key`
- `status`
  - `processing`
  - `completed`
  - `failed`
- `is_active`
- `row_count`
- `success_count`
- `duplicate_count`
- `error_count`
- `imported_at`
- `deactivated_at`
- `deactivated_by`
- `deactivation_reason`
- `error_summary`

補足:

- 同じ `CreditCardStatement` に対して、通常画面で有効とみなす batch は `is_active = true` のものだけとする。
- 修正版CSVを再アップロードするときは、旧 batch を `inactive` にし、そこから作られた `StatementLine` と `Transaction` もまとめて `inactive` にする。
- 履歴は削除せず保持し、通常の一覧・進捗計算・登録対象抽出では `active` のみを見る。
- `credit_card_statement_id` を batch にも持つことで、「どの請求明細に対する取込か」と「どの取込から作られた行か」をそれぞれ1段で辿れるようにする。

## 3. 状態管理

### 3-1. Statement の状態

- `CreditCardStatement` の状態は保存しない。
- 一覧や画面表示で必要な状態は、配下の `statement_lines` から自動算出する。
- 想定する導出状態は次の通り。
  - `empty`
    - 明細行がまだ0件
  - `imported`
    - 全行が `unreviewed`
  - `reviewing`
    - `unreviewed` とレビュー済み行が混在している
  - `completed`
    - `unreviewed` が0件

### 3-2. StatementLine の状態

- `unreviewed`
  - まだ判断していない
- `registered`
  - `Transaction` を作成済み
- `private`
  - 今は私用扱いで会計登録しない
- `duplicate`
  - 重複取込と判断した
- `ignored`
  - キャンセルやノイズ行など、保持はするが登録対象外

### 3-3. 状態遷移の考え方

- 初回取込時は原則 `unreviewed`
- 会計登録したら `registered`
- 私用と判断したら `private`
- `private` から将来 `registered` に変更できる
- `duplicate` と `ignored` も原本保持のため削除しない

## 4. Transaction との関係

### 4-1. 役割分担

- `CreditCardStatementLine`
  - 明細の保存とレビュー状態管理
- `Transaction`
  - 実際の会計登録
- `TransactionRegistrar`
  - 貸借一致、税区分、会計年度クローズなどの登録ルール適用

### 4-2. 登録時に決めるもの

`Transaction` を作成するときに、次を確定する。

- 借方補助科目
- 貸方補助科目
- 税区分
- 消費税額
- 摘要や備考

### 4-3. 登録後の関係

- `CreditCardStatementLine.transaction_id` に作成した `Transaction` を保持する
- これにより、どの明細からどの会計取引が作られたか追跡できる

## 5. 事業用カードと個人用カードの扱い

### 5-1. 事業用カード

- CSVは全件保存する
- すべての行をレビュー対象とする
- 全行を `registered` / `private` / `ignored` / `duplicate` のいずれかに整理する
- 運用上は `private` を原則使わない想定だが、データ構造上は禁止しない
- ここでいう「全件登録前提」は、全行について判断が必要という意味であり、全行について `Transaction` 作成を必須とする意味ではない

### 5-2. 個人用カード

- CSVは全件保存する
- 必要な行だけ `registered` にする
- 不要な行は `private` として保持する
- 後から `private` を `registered` に切り替えられる

## 6. 年またぎ・遅延取込

### 6-1. 遅延の前提

- カード会社によっては利用月の1か月後、または2か月後にCSV取得可能になる
- そのため、登録タイミングと利用タイミングはズレる

### 6-2. 設計上の扱い

- `CreditCardStatement` は請求月単位で保持する
- `CreditCardStatementLine.used_on` を会計上の基準日とする
- 取込日時や請求月は、監査情報として保持する

### 6-3. 例

- 利用日: 2025-12-28
- 請求月: 2026-01
- CSV取込日: 2026-02-03

この場合でも、会計年度判定は 2025-12-28 を基準に行う。

## 7. CSV Parser の責務

### 7-1. Parser の役割

- カード会社ごとのCSV形式差異を吸収する
- CSVから正規化済みの明細ヘッダと明細行を生成する

### 7-2. Parser がやること

- 列名や並びの違いを吸収する
- `used_on`、`posted_on`、`merchant_name`、`description`、`amount` を正規化する
- `raw_payload` に元データを残す
- `fingerprint` の元になる値を揃える
- 同じ内容の行が再取込されても同じ `fingerprint` になりやすい形へ正規化する

### 7-3. Parser がやらないこと

- 借方・貸方の決定
- 税区分の決定
- `Transaction` の作成

これらは会計登録時に別レイヤで扱う。

## 8. 想定フロー

### 8-1. CSV取込

1. ユーザーがカードを選ぶ
2. CSVをアップロードする
3. 対応する Parser で `CreditCardStatement` と `CreditCardStatementLine` を生成する
4. `CreditCardImportBatch` を保存する
5. 各行を原則 `unreviewed` で保存する

### 8-2. レビュー

1. 明細行一覧を表示する
2. 事業用か個人用かに応じてレビューする
3. 必要な行は会計登録へ進める
4. 不要な行は `private` または `ignored` にする

### 8-3. 会計登録

1. 対象の `CreditCardStatementLine` を選ぶ
2. `Transaction` 入力画面に明細情報を初期値として渡す
3. 借方・貸方・税区分を決める
4. `TransactionRegistrar` で登録する
5. `statement_line.transaction_id` を更新し、`status = registered` にする

## 9. 初版で持たないもの

- 明細行への借方補助科目保持
- 明細行への貸方補助科目保持
- 明細行への税区分保持
- 明細行への消費税額保持
- 外貨建て明細の通貨管理
- 明細行削除による取込補正

これらは、将来「自動仕訳候補」を強化したくなった段階で検討する。

## 10. 今後の拡張余地

- カード会社別 Parser 実装追加
- 勘定科目自動提案ルール
- 取引候補の一括登録
- `private` 明細の再レビュー機能
- `used_on` から会計年度を自動特定するサービス
- 同一行の重複取込判定精度向上
