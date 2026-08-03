# Unclassified Expense Design

このドキュメントは、勘定科目が未確定な経費の登録をどのレイヤーで扱うかと、その運用ルールを整理したものです。

初心者ユーザーが「金額と支払元だけ決まっているが、どの経費科目に入れるか分からない」状態でも入力を止めない、かつ帳簿の正確性は損なわないことを目的とする。

## 目的

- 借方の勘定科目が決まっていない経費でも、金額と支払元さえ分かれば登録できるようにする
- 未分類のまま集計・決算に紛れ込むことを構造的に防ぐ
- 後日の分類作業を、取引の改訂ではなく `sub_account_id` の差し替えで完結させる
- 既存の集計・元帳・青色申告決算書・消費税集計に「未分類」を意識させない

## 基本方針

`sub_account_id` を nullable にはせず、専用の予約勘定・補助科目を明示的に埋める。

- 借方は `未分類費用` 勘定 ＋ `未分類` 補助科目（予約）を使う
- 貸方は通常の経費登録と同じくユーザーが選ぶ（`現金` / `普通預金` / `未払金` / `事業主借` など）
- 家事按分の予約補助科目（`事業主貸 / 家事按分`）と同じ「システム予約 SubAccount を明示的に埋める」パターンに揃える

### nullable を採らない理由

- 既存の全計算（`FiscalYearBalanceCalculator` / `BlueReturnStatementCalculator` / `GeneralLedgerService` / `Transaction::journalEntrySummary` / 家事按分 / 税区分）が `subAccount->account` 前提で動いており、nullable 化は波及が大きい
- 貸借一致・税区分・按分など既存 Validator の前提が崩れる
- 「未分類」は業務的にも「不明」ではなく「後で決める意思を持って一時的にここへ置いた」状態であり、明示された勘定科目の方が意味が正確に表現できる

### 貸方を必須にする理由

- 「借方も貸方も未確定」を許すと、金額だけが浮遊した状態になり、支払手段の残高（現金・預金）が実勢と乖離する
- 貸方（支払元）は当事者にとって明らかであることが多く、これを必須にしても入力の壁にはならない
- 支払元を選ばせることで、後日の分類作業でも「いつ・どこから支払った経費か」を絞り込める

## 独立勘定として持つ理由

`未分類費用` は既存の `雑費` の下位補助科目にせず、`Account::TYPE_EXPENSE` の独立勘定として追加する。

- 決算前ブロック（後述）と相性が良く、「残っていること」が青色申告決算書上でも 1 行として自明になる
- 分類確定時に `雑費` 以外の科目（`通信費` / `消耗品費` など）へ振り替えるのが自然な運用であり、`雑費` 配下に置くと「未分類 → 雑費」の直感的な階層に引きずられて誤分類を招く
- 集計・レポートで `未分類費用` の残高がゼロでない状態を検出しやすい

青色申告決算書上の見え方は「決算前に必ず消す運用ガード」を前提にしており、正しく運用されていれば決算書には出現しない。

## 予約勘定・補助科目

`BusinessUnit` の既定勘定に予約項目を追加する。

- 予約勘定
  - 名前: `未分類費用`
  - タイプ: `Account::TYPE_EXPENSE`
- 予約補助科目
  - 親勘定: `未分類費用`
  - 名前: `未分類`

家事按分と同じく、名前定数を `BusinessUnit` に定義する。

- `BusinessUnit::UNCLASSIFIED_EXPENSE_ACCOUNT_NAME = '未分類費用'`
- `BusinessUnit::UNCLASSIFIED_EXPENSE_SUB_ACCOUNT_NAME = '未分類'`

新規事業体・既存事業体の両方に対して、`未分類費用` Account と `未分類` SubAccount が必ず存在する状態を保証する。

- 新規事業体は `BusinessUnit::$defaultAccounts` と `$defaultSubAccounts` を更新して自動的に持つ
  - `$defaultAccounts` の費用ブロック末尾（`雑費` の直後）に `['name' => '未分類費用', 'type' => Account::TYPE_EXPENSE]` を追加
  - `$defaultSubAccounts` に `'未分類費用' => ['未分類']` を追加
- 既存事業体は migration で `未分類費用` Account と `未分類` SubAccount の両方を作成する

SubAccount まで migration で eager に作成する理由:

- 予約 SubAccount は BU あたり 1 件・名前固定で曖昧さがなく、遅延生成する理由がない
- 家事按分の予約 SubAccount は「按分を使わない BU では作らない」意義があったが、未分類費用は初心者向け救済であり全 BU で存在を保証したい
- Registrar・分類確定サービス・UI の科目セレクタで `firstOrCreate` の分岐が不要になり、`事業主貸` と同じ `firstOrFail` 前提でコードが揃う

## TransactionRegistrar での扱い

入口は既存の `TransactionRegistrar` に統一する（`transaction-registration.md` の方針を維持）。

- 借方の `sub_account_id` が未指定または「未分類」を意味する明示的マーカーで渡された場合、Registrar が予約 SubAccount を `firstOrCreate` して埋める（`resolveHouseholdAllocationSubAccount()` と同型のヘルパーを追加）
- 貸方の `sub_account_id` は必須。未指定は ValidationException を投げる
- 借方が未分類の場合、`business_ratio` の指定は不可とする。按分は分類確定後に改めて設定する

税区分は通常経費と同じ扱いとする。

- 課税事業者は `tax_type` の指定が必要（既存ルールと同じ）
- 免税事業者は既定で `deemed_taxable_purchases_10` に分解される（既存ルールと同じ）
- 未分類の段階で税区分の判断が難しいケースは実務上ありうるが、事業者区分によるデフォルト分解に委ねる

## 分類の確定

未分類経費の後日分類は、`TransactionRevisor` による改訂ではなく借方 `JournalEntry.sub_account_id` の差し替えで扱う。

- 改訂チェーンを作らないため、家事按分と同じく「同一 `Transaction` の借方明細の更新」として扱う
- 貸方・金額・日付・取引先・税区分は分類時に変更しない。金額や税区分の訂正が必要なときは通常の改訂フロー（`TransactionRevisor`）に載せる
- 分類確定操作の入口は専用サービス（例: `UnclassifiedExpenseClassifier`）を用意し、次を行う
  - actor ガード（`AuthorizesBusinessUnitAccess`）
  - 対象 `JournalEntry` が予約 SubAccount であることの確認
  - 差し替え先 SubAccount が同一 `BusinessUnit` かつ `Account::TYPE_EXPENSE` であることの確認
  - `business_ratio` の設定（家事按分が発生する場合は、この時点で分割を行う）
- 分類確定は締め済み年度に対しては不可とする（`FiscalYearCloser` のガードと同じ理由）

按分を伴う分類確定は、Registrar 内の按分分割ロジックを再利用できるよう、実装時に共通化を検討する。

## 決算前ブロック

`FiscalYearCloser::validate()` に、未分類残高チェックを error として追加する。

- error: 未分類費用 の期末残高が 0 でない
  - `key`: `unclassified_expenses_remaining`
  - `count`: 未分類 SubAccount を借方に持つ有効な `JournalEntry` の件数
- 残高ではなく件数を返すのは、UI で「あと N 件分類してください」と表示するため
- ゼロなら error に含めない（表示しない）

`FiscalYearCloser::close()` は既存どおり `validate()` の errors があれば例外を投げるため、ブロックの実装は validate 側の追加だけで済む。

## ToDo との連携

未分類経費の存在は、取引単位ではなく「事業体 × 会計年度」単位で 1 件の Todo として通知する。

- 100 件登録すると Todo が 100 件並ぶのは通知として機能しないため、集約する
- Todo の `key`: `unclassified_expenses`
- 未分類 SubAccount を借方に持つ有効な `JournalEntry` が 1 件以上あれば `pending`、ゼロになったら自動 `completed`
- 更新契機は、取引登録・分類確定・取引無効化のいずれか
- 個別の未分類取引は取引一覧側で「借方 = 未分類費用」フィルタで確認する（Todo からリンクする）

決算前ブロックが本命のガードであり、Todo は「気付き」として補助的に機能する位置づけとする。

## 集計への影響

`未分類費用` は `Account::TYPE_EXPENSE` として扱われるため、既存の損益集計・青色申告決算書計算・元帳に自然に組み込まれる。

- 期中は「未分類費用」として損益に含まれるが、決算前ブロックによりゼロで年度を締める運用が保証される
- 青色申告決算書には固定の科目行はなく、`FiscalYearAccountBreakdownCalculator` の残高がゼロであれば表示されない
- `FiscalYearRollover` は損益科目として当期所得に含めて元入金に組み替えるため、翌期の期首仕訳に「未分類費用」は残らない

## スコープ外

- 未分類な**収益**（売上側）の登録は本ドキュメントの対象外とする。売上は支払元・入金方法が明確な場面が多く、必要性が確認できた段階で別途設計する
- 借方が固定資産の未分類（減価償却の入口）も対象外とする。固定資産は `FixedAsset` 側で管理される
- 「未分類のまま予定取引を作る」ユースケースは対象外。定期取引テンプレートは科目確定後に作る前提とする
