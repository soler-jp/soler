# Blue Return Statement Design

このドキュメントは、所得税青色申告決算書(一般用)をアプリでどう生成するかの設計を整理したものです。

確定申告書(第一表・第二表)側の設計は tax-return-design.md を正とし、本ドキュメントは決算書のみを扱う。責務の分担は次の通り(詳細は tax-return-design.md の「責務の分担」)。

- 決算書の主語は **事業体 × 年度** であり、`FiscalYear` の責務とする
- 確定申告書の主語は **個人 × 年** であり、`TaxReturn`(別ドメイン)の責務とする

## 位置づけ

決算書は損益計算書・月別売上・減価償却明細・貸借対照表のすべてがほぼ帳簿から導出できる「帳簿の集計結果のビュー」である。したがって:

- `calculateSummary()` / `calculateBalanceSummary()` / `calculateRolloverData()` と同列の `FiscalYear` の集計メソッドとして提供する(実体は既存パターン通りサービスに委譲)
- 集計結果の値は DB に保存しない。正本は帳簿であり、毎回導出する(唯一の例外は確定時のスナップショット。「決算書の確定とスナップショット」を参照)
- tax-return-design.md の 3 分類(`ledger` / `user_input` / `computed`)のフィールド定義(`TaxFormField`)は決算書には適用しない。決算書の欄はほぼ全てが帳簿由来で、入力・計算・転記が入り混じる確定申告書とは性質が違うため

## 様式と欄番号の扱い

- 決算書(一般用)の様式は「令和五年分以降用」(様式 ID: 1ページ = FA3001、2ページ = FA3026、3ページ = FA3051、4ページ = FA3076)の表記どおり令和5年分から安定している。確定申告書第一表のような毎年の番号ズレがないため、**年度別の様式定義は持たない**。様式が変わったら帳票テンプレート側で対応する
- 本ドキュメントの欄一覧が、集計仕様と帳票出力時の欄番号マッピングの正本になる

## 集計の基準

- **金額はすべて税込(gross)で集計する** — 税抜経理はサポートしない。`JournalEntry` の `net_amount + tax_amount` を合算した金額を使う(既存 `FiscalYearSummaryCalculator` と同じ基準)
- **科目と欄の対応は科目名(文字列)で引く** — `Account` にコード列は追加せず、`BusinessUnit::$defaultAccounts` の名称をマッピングキーにする。マッピングは科目レコードの名称と完全一致させること(例: `期首商品（棚卸高）` / `期末商品（棚卸高）` は括弧も全角)。科目名の変更・科目追加の機能は未実装なので名前の不一致は現状起こらない。機能を追加するときにこのマッピングを見直す

## 青色申告特別控除額の扱い

決算書で唯一のユーザー判断が青色申告特別控除額(65/55/10万円の区分選択)だが、65万円控除の要件(e-Tax 提出等)は申告行為の属性であって帳簿の属性ではない。そのため:

- `FiscalYear` の決算書集計の正本は「青色申告特別控除前の所得金額(㊸)」までとし、㊹・㊺ は控除額を引数で受けて算出する
- `TaxReturn` が実装されるまでは、控除額は決算書作成時に UI から引数で渡すだけで、どこにも保存しない。確定時のスナップショットに含まれることで固定される
- `TaxReturn` 実装後は選択値の正本を申告書側(第一表 59)に置き、そこから引数で渡す(渡し手が UI から `TaxReturn` に変わるだけで、決算書側の形は変わらない)
- 締め済み年度の帳簿に申告の都合で書き込まない

## 損益計算書(決算書1ページ)の欄一覧 — 様式 FA3001(令和五年分以降用)

分類は `ledger`(帳簿から集計)/ `computed`(表内の計算)/ `argument`(申告書側から渡される引数)。

| 番号 | ラベル | key | 分類 | 備考・計算式 |
|---|---|---|---|---|
| ① | 売上(収入)金額(雑収入を含む) | `sales_amount` | ledger | 売上高・雑収入・家事消費等の合計。将来 revenue 科目を追加する場合(引当金戻入など)、①に含めるかは科目ごとに判断する |
| ② | 期首商品(製品)棚卸高 | `beginning_inventory` | ledger | `期首商品（棚卸高）` 勘定 |
| ③ | 仕入金額(製品製造原価) | `purchases_amount` | ledger | 仕入金額勘定 |
| ④ | 小計 | `purchases_subtotal` | computed | ② + ③ |
| ⑤ | 期末商品(製品)棚卸高 | `ending_inventory` | ledger | `期末商品（棚卸高）` 勘定 |
| ⑥ | 差引原価 | `cost_of_goods_sold` | computed | ④ − ⑤ |
| ⑦ | 差引金額 | `gross_profit` | computed | ① − ⑥ |
| ⑧ | 租税公課 | `taxes_and_dues` | ledger | |
| ⑨ | 荷造運賃 | `packing_and_freight` | ledger | |
| ⑩ | 水道光熱費 | `utilities` | ledger | |
| ⑪ | 旅費交通費 | `travel_expenses` | ledger | |
| ⑫ | 通信費 | `communication_expenses` | ledger | |
| ⑬ | 広告宣伝費 | `advertising_expenses` | ledger | |
| ⑭ | 接待交際費 | `entertainment_expenses` | ledger | |
| ⑮ | 損害保険料 | `casualty_insurance` | ledger | |
| ⑯ | 修繕費 | `repair_expenses` | ledger | |
| ⑰ | 消耗品費 | `supplies_expenses` | ledger | |
| ⑱ | 減価償却費 | `depreciation_expense` | ledger | `DepreciationEntry.deductible_amount` の合計と、記帳済みの `減価償却費` 勘定の残高が一致すること |
| ⑲ | 福利厚生費 | `welfare_expenses` | ledger | |
| ⑳ | 給料賃金 | `wages` | ledger | 専従者給与は含めない(㊳へ) |
| ㉑ | 外注工賃 | `outsourcing_costs` | ledger | |
| ㉒ | 利子割引料 | `interest_and_discounts` | ledger | |
| ㉓ | 地代家賃 | `rent_expenses` | ledger | |
| ㉔ | 貸倒金 | `bad_debts` | ledger | |
| ㉕〜㉚ | (空欄・任意科目) | `custom_expense_1`〜`custom_expense_6` | ledger | 科目追加機能が未実装のため初版では常に空欄 |
| ㉛ | 雑費 | `miscellaneous_expenses` | ledger | |
| ㉜ | 計 | `total_expenses` | computed | ⑧〜㉛の合計 |
| ㉝ | 差引金額 | `profit_before_reserves` | computed | ⑦ − ㉜ |
| ㉞ | 貸倒引当金(繰戻額等) | `bad_debt_reserve_reversal` | ledger | 貸倒引当金は初版では未対応・0 |
| ㉟〜㊱ | (空欄・繰戻額等) | `reserve_reversal_1`〜`reserve_reversal_2` | ledger | 当面未対応・0 |
| ㊲ | 計(繰戻額等) | `total_reserve_reversals` | computed | ㉞〜㊱の合計 |
| ㊳ | 専従者給与 | `family_employee_salaries` | ledger | 専従者給与勘定(標準科目に追加する)。2ページの内訳欄は決算書作成時のユーザー入力(後述) |
| ㊴ | 貸倒引当金(繰入額等) | `bad_debt_reserve_provision` | ledger | 貸倒引当金は初版では未対応・0 |
| ㊵〜㊶ | (空欄・繰入額等) | `reserve_provision_1`〜`reserve_provision_2` | ledger | 当面未対応・0 |
| ㊷ | 計(繰入額等) | `total_reserve_provisions` | computed | ㊳〜㊶の合計 |
| ㊸ | 青色申告特別控除前の所得金額 | `income_before_blue_return_deduction` | computed | ㉝ + ㊲ − ㊷。貸借対照表の同名欄と必ず一致。`FiscalYear` の集計の正本はここまで |
| ㊹ | 青色申告特別控除額 | `blue_return_deduction` | argument | 作成時に引数で受ける(「青色申告特別控除額の扱い」参照)。㊸ を上限とする |
| ㊺ | 所得金額 | `business_income` | computed | ㊸ − ㊹。第一表①の元になる |

補足:

- ①〜③・⑤の棚卸欄は `InventoryClosingService` が計上する決算整理仕訳込みの帳簿残高から導出する。⑤の `期末商品（棚卸高）` 勘定は貸方残高(負の費用)になるので、欄には正の値として出す
- ㉕〜㉚の任意科目は、科目追加機能を実装するときに割当ルール(並び順の安定性、6 欄を超える場合の扱い)とあわせて決める。初版では対象科目が存在しないため常に空欄
- 家事消費は決算整理仕訳(借方: 事業主貸/貸方: 家事消費等)として帳簿に計上する。決算書側に入力欄は設けず、①と月別売上表の「家事消費等」行には帳簿残高が乗る

## 決算書2〜4ページ

可変行の明細は「帳簿から導出」「決算書作成時のユーザー入力」「サポートしない」の 3 つに分ける。

帳簿・既存データから導出する:

- **月別売上(収入)金額及び仕入金額**(2ページ) — 仕訳の月別集計。家事消費等・雑収入の行を含む。「うち軽減税率対象」は様式上も記入省略可とされているため省略する
- **青色申告特別控除額の計算**(2ページ) — 引数で受けた控除額から出力する
- **減価償却費の計算**(3ページ) — `FixedAsset` / `DepreciationEntry` から生成する
- **貸借対照表**(4ページ) — `FiscalYearBalanceCalculator` から生成する。様式は期首・期末の両列を要求するので、両方を当期の帳簿から導出する
  - 期末列 = 当期の全取引を集計した残高(現行の `FiscalYearBalanceCalculator::calculate()`)
  - 期首列 = 当期の `is_opening_entry = true` の取引だけを集計した残高。前年度の `FiscalYear` は参照しない
  - 期首残高は繰越処理(`FiscalYearRollover` が `calculateRolloverData()` の結果から作る開始仕訳)として当期の帳簿に既に記帳されており、事業主貸・事業主借を期首ゼロに、元入金を繰越後の金額にした状態になっている。前年の期末残高を直接読むとこの繰越が反映されず様式と合わないため、必ず開始仕訳から導出する
  - 利用開始初年度は前年度が存在しないが、セットアップ時の開始仕訳(`OpeningEntryRegistrar`)が同じく `is_opening_entry = true` なので、同一ロジックで期首列が出る
  - 不変条件: 期首列の借方合計 = 貸方合計、元入金の期首 = 期末(様式の注記どおり)

決算書作成時のユーザー入力(保存先は後述):

- **専従者給与の内訳**(2ページ) — 氏名・年齢・延べ従事月数・給料・賞与・源泉徴収税額は帳簿の外の情報。金額の正本はあくまで帳簿(㊳)であり、内訳の給料・賞与の合計が ㊳ と一致することをバリデーションする
- **地代家賃の内訳**(2ページ) — 支払先の住所・氏名は帳簿の外の情報。賃借料と必要経費算入額は家事按分の `allocation_group_id` から導出できる余地があるが、初版では金額も含めて入力とし、帳簿からのプレフィルは将来の改善とする

サポートしない(空欄で出力し、必要なら提出時に手で補う):

- 給料賃金の内訳(2ページ) — 専従者以外の使用人は当面想定しない
- 利子割引料の内訳・税理士等の報酬・料金の内訳(3ページ)
- 貸倒引当金繰入額の計算(2ページ) — 損益計算書の引当金欄(㉞・㊴)とあわせて未対応
- 売上(収入)金額の明細・仕入金額の明細(3ページ)の取引先別内訳 — 将来 `Counterparty`(登録番号は `CounterpartyQualificationEvent`)から導出できる可能性はあるが対象外。PDF 出力では「上記以外の売上先の計」「計」の行に①・③の金額のみ印字する(blue-return-statement-pdf-design.md 参照)
- 製造原価の計算(4ページ)

### 決算書入力の保存

内訳のユーザー入力は帳簿からの導出ではないため、「集計結果は保存しない」の原則の例外として保存する。tax-return-design.md の `tax_return_inputs` と同じ発想で、`FiscalYear` にぶら下がる決算書入力テーブル(`blue_return_inputs` — `fiscal_year_id` / `key` / `value`)を用意する。内訳は可変行のため `value` は行構造ごと JSON で保持する(1 レコード = 1 内訳種別)。主語は事業体 × 年度なので `TaxReturn` 側には置かない。実装は `BlueReturnInput` モデルと `BlueReturnInputRegistrar`(保存時バリデーション。専従者給与内訳の合計と ㊳ の一致チェックもここで行う)。使い方は manual/blue-return-inputs.md を参照。

### 決算書の確定とスナップショット

確定後の帳票再現性(集計ロジックや帳票テンプレートの変更に対する耐性)を保つため、「決算書の確定」を締めとは別のアクションとして持ち、確定時に決算書全ページの出力値スナップショット(JSON)を `FiscalYear` 側に保存する。

- 確定の前提は、年度が締め済みであることと、控除額が引数で与えられていること
- 確定前の表示は帳簿から毎回再計算し、確定後の控え・再出力はスナップショットを正とする
- `TaxReturn` を実装した後も、決算書スナップショットは `FiscalYear` 側のまま維持する(第一表のスナップショットは `TaxReturn` 側が持つ。各書類が自分のページのスナップショットを持つ分担)

## 制限事項・TODO

- 貸倒引当金は初版では未対応。将来対応するときは、貸倒引当金繰戻額(㉞)や繰入額(㊴)を `revenue` / `expense` の単純集計に混ぜず、①や㉜ではなく ㊲ / ㊷ にだけ反映する。あわせて、㊸ と `FiscalYearSummaryCalculator::calculate()['actual']['profit']` の恒等テストは「引当金未対応または引当金を除外した同一基準」の検証に見直す。
- 決算書確定スナップショットのテーブル構成と、確定のやり直し(確定解除)の扱いは未設計。実装時に詰める。

## テスト方針

1. **記載例による検算テスト** — 「青色申告決算書(一般用)の書き方」(`https://www.nta.go.jp/taxes/shiraberu/shinkoku/tebiki/2025/pdf/037.pdf`)の記載例(国税太郎)の数字で仕訳を投入し、記載例と一致することを検証する(test-journal-review-tool.md の「人間が読める形で検証する」方針の延長)。ただし貸倒引当金は初版未対応のため、引当金を除いた調整後期待値を使う: ㊲ = 0、㊷ = 専従者給与のみ 1,200,000、㊸ = 5,331,400 + 0 − 1,200,000 = 4,131,400。引当金対応後に記載例どおりの全欄一致(㊸ = ㉝ + ㊲ − ㊷ = 5,331,400 + 64,460 − 1,274,140 = 4,121,720)へ引き上げる
2. **既存サービスとの突合** — ㊸ が `FiscalYearSummaryCalculator::calculate()` の actual 側 profit と一致することを検証する(どちらも税込ベースで、引当金未対応の現状では同じ計算になるため恒等が成り立つ)

## 参照資料

- `https://www.nta.go.jp/taxes/shiraberu/shinkoku/tebiki/2025/pdf/037.pdf` — 令和7年分 青色申告決算書(一般用)の書き方(記載例あり)
- `https://www.nta.go.jp/taxes/shiraberu/shinkoku/tebiki/2025/pdf/025.pdf` — 令和7年分 青色申告の決算の手引き(一般用)
