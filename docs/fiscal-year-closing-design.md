# Fiscal Year Closing Design

このドキュメントは、期末処理（決算締めと翌期繰越）をどのレイヤーで扱うかと、その実行ルールを整理したものです。

操作手順は `manual/year-end-closing.md` を正とし、本ドキュメントはそれを実現する設計を扱う。

## 目的

- `is_closed` フラグに「締める」という明示的な業務操作を与える
- 締め前の検証をチェックリストとして返し、期末処理を ToDo 型のオペレーションにする
- 事業主貸・事業主借・当期所得の元入金組替と翌期期首仕訳の生成を自動化する
- 決算整理仕訳・締め・繰越の責務を分離し、途中失敗から再開できる形にする

## 基本方針

期末処理を 1 つの巨大な API にせず、性質の異なる 3 つの操作に分ける。

1. 決算整理仕訳の計上 — 既存の登録 API を使う（新規 API なし）
2. 締め — `FiscalYearCloser`（検証 + 状態遷移のみ、仕訳を作らない）
3. 翌期繰越 — `FiscalYearRollover`（翌期の期首仕訳を生成する）

分割する理由は次の通り。

- 各ステップが独立して冪等になり、途中失敗時は検証が「残りの ToDo」を示す
- 仕訳の生成（帳簿への記録）と状態遷移（検証 + フラグ）は取消可能性が異なる
- 月次締め（検証のみの軽いオペレーション）と年次締めが同じ形（validate → 状態遷移）で揃う

## 決算整理仕訳

決算整理仕訳をすべて素の `TransactionRegistrar` だけで扱うと、締め前検証で種類を識別しづらくなる。

そのため入口は次のように分ける。

- 棚卸の振替
  - `InventoryClosingService` を入口とする
  - 期末の実地棚卸高を `棚卸資産` 配下の SubAccount ごと（`[sub_account_id => 金額]`）に受け取り、必要な決算整理仕訳を組み立てて `TransactionRegistrar` に委譲する
  - 振替は SubAccount 単位で行い、`棚卸資産` を `商品` / `製品` / `材料` などに分離していても各補助科目の残高が実地棚卸高と一致するようにする。損益科目（`期首商品（棚卸高）` / `期末商品（棚卸高）`）側は集計科目として合算 1 行にまとめる
  - 期末棚卸高は `棚卸資産` 配下の全 SubAccount について、0 を含めて明示入力を必須とする。未入力は validation error とし、`0` が「期末残高なし（売り切り）」を意味する
  - 期首棚卸高は手入力ではなく、その年度の期首時点の `棚卸資産` 帳簿残高を SubAccount ごとに導出する
  - `期首商品（棚卸高）` / `期末商品（棚卸高）` は損益計算に載る科目として扱い、勘定タイプは `expense` とする
  - 期首分の振替も期首日付ではなく、期末分とセットの決算整理仕訳として期末日付で登録する。「期首」は金額の意味（期首時点の在庫額）であり、登録タイミングではない。期中に登録すると損益集計に前年の在庫額が費用として先出しされてしまう（詳細は `manual/year-end-closing.md` の「棚卸の振替」）
- 減価償却
  - 既存の `DepreciationService` を入口とする
  - 新規に計上する償却仕訳には `is_adjusting_entry = true` と `adjusting_entry_type = depreciation` を付与する
- その他の任意の決算整理
  - 当面は `TransactionRegistrar` を入口とする

想定する追加項目は次の通り。

- `transactions.is_adjusting_entry`
  - 決算整理仕訳であることを示すフラグ
  - journal-revision-design.md はこのフラグ付き取引を通常改訂の対象外としている（先行して想定済み）
- `transactions.adjusting_entry_type`
  - 決算整理仕訳の種別を示す nullable な識別子
  - 例: `inventory_closing`, `depreciation`
  - `is_adjusting_entry` は「決算整理仕訳かどうか」、`adjusting_entry_type` は「何の決算整理仕訳か」を表す

棚卸 2 科目を `expense` にする理由は次の通り。

- `FiscalYearSummaryCalculator` の損益集計に自然に含めるため
- `当期所得` の正本を損益集計サービスに寄せても、棚卸振替が利益計算から漏れないようにするため
- 貸借対照表科目の繰越対象から自然に外し、翌期の期首仕訳へ誤って混入させないため

新規事業体だけでなく既存事業体にも同じ前提を適用する必要がある。

- `BusinessUnit::$defaultAccounts` の定義変更は新規事業体にしか効かない
- 既存 `accounts` レコードの `期首商品（棚卸高）` / `期末商品（棚卸高）` は migration で `expense` へ更新する

決算整理仕訳の修正は、改訂ではなく `deactivate()` + 再登録で扱う。

- 決算整理仕訳は種類が少なく定型的なため、改訂チェーンを持ち込むより単純な作り直しを優先する
- 無効化理由には操作の種別（例: `決算整理仕訳の修正`）を記録する
- 既存の償却仕訳に対する `adjusting_entry_type = depreciation` のバックフィルは初期実装では行わない
- 締め前の償却検証は `DepreciationEntry` を正とするため、既存償却仕訳に種別がなくても検証上の支障はない

## FiscalYearCloser

締めの入口は `App\Services\FiscalYearCloser` とする。

### validate(FiscalYear): array

締め可能かの検証結果をチェックリストとして返す。

```php
[
    'closable' => false,
    'errors' => [
        ['key' => 'planned_transactions_remaining', 'count' => 2],
    ],
    'warnings' => [
        ['key' => 'inventory_transfer_missing'],
    ],
]
```

- `errors` は締めをブロックする項目
- `warnings` は確認を促すだけでブロックしない項目
- `key` は機械可読な識別子とし、表示文言は利用側（UI / CLI）が持つ

初期実装の検証項目は次の通り。

- error: 未処理の予定取引が残っている（`is_planned = true` かつ `is_active = true`）
- error: 償却が発生すべき固定資産について、その年度の `DepreciationEntry` が存在しない、または存在しても `transaction_id` が null のものがある
- error: 未分類費用が残っている（借方 `sub_account_id` が予約 `未分類費用 / 未分類` の有効な `JournalEntry` が 1 件以上ある）。詳細は `unclassified-expense-design.md`
- warning: 棚卸資産の残高があるのに期末棚卸の振替（`adjusting_entry_type = inventory_closing`）がない

`validate()` は read-only を保ち、呼び出し時点で `DepreciationEntry` が未生成でも検出漏れしないようにする。

- `validate()` 自体は `DepreciationService::prepareEntriesFor()` を呼ばない
- `depreciatingFixedAssets($fiscalYear)` を起点に、その年度の `DepreciationEntry` が存在しないものを `depreciation_entries_not_prepared` として返す
- `DepreciationEntry` は存在するが `transaction_id` が null のものを `depreciation_entries_unposted` として返す
- これにより、チェックリスト表示のたびに DB 書き込みが走ることを避ける

月次締めを導入した後は「全月の月次締めが完了している」を error に加える（月次締め自体は別ドキュメントで扱う）。

### close(FiscalYear, User): FiscalYear

- DB トランザクション内で対象年度を `lockForUpdate()` で再取得する
- すでに `is_closed = true` なら例外を投げる
- `validate()` と同じ検証を実行し、`errors` があれば例外を投げる
- `is_closed = true` とし、監査情報を記録する

想定する追加項目は次の通り。

- `fiscal_years.closed_at` — 締めた日時
- `fiscal_years.closed_by` — 締めた操作者

`deactivated_at` / `deactivated_by` と同じ監査カラムの流儀に合わせる。

### 締めの効果

締め済み年度に対しては、次の操作がすべて拒否される。

- 新規取引の登録 — `TransactionRegistrar` の既存ガード
- 仕訳の改訂 — `TransactionRevisor` の修正可能条件（journal-revision-design.md）
- 予定取引の確定・取消 — `PlannedTransactionConfirmer` にも同様のガードを置く
- 決算整理仕訳の追加・無効化 — 上記の登録ガードと `deactivate` 側のガードで防ぐ

`Transaction::deactivate()` は現状、所属年度の締め状態を見ていないため、締め済み年度の取引の無効化を拒否するガードを追加する。

### 再オープン（締め解除）

初期実装では提供しない。

- 翌期の期首仕訳が繰越から生成された後に前年を開け直すと、残高の整合が壊れるため
- 締めた年度の誤りは、開いている年度での修正仕訳で対応する（実務の定石に合わせる）
- 将来提供する場合も「翌期の期首仕訳が存在しない場合に限る」を必須条件とする

## 翌年度作成との関係

`close()` は「対象年度を締める」責務だけを持ち、翌年度を自動作成しない。

- 締めと年度作成は別の業務操作であり、常に同時とは限らない
- 翌年度を先に作って日々の入力を始める運用もありうる
- したがって `close = 次年度作成` という副作用は持たせない

翌年度作成に関する業務ルールを設ける場合、その責務は `BusinessUnit::createFiscalYear()` 側に置く。

- 例: 年度の飛び番を禁止する
- 例: 未締め年度が残る間は次年度を作れないようにする
- 例: 同一年の重複作成を禁止する

初期実装では `createFiscalYear()` は既存どおり独立した年度作成 API とし、繰越は「翌年度がすでに存在すること」を前提条件として扱う。

## FiscalYearRollover

翌期繰越の入口は `App\Services\FiscalYearRollover` とする。

### rollover(FiscalYear $closedYear, FiscalYear $nextYear): Transaction

- `$closedYear` が `is_closed = true` でなければ例外を投げる
- `$nextYear` が `$closedYear` の翌年度・同一事業体でなければ例外を投げる
- `$nextYear` にすでに期首仕訳があれば例外を投げる（1 年度 1 伝票の既存ルール）
- 締め済み年度の期末残高から翌期の開始残高を組み立てる
- 期首仕訳の生成は `OpeningEntryRegistrar` に委譲する

### 元入金の組替

翌期の元入金は次の式で算出する。

```
翌期の元入金 = 当期の元入金 + 事業主借 − 事業主貸 + 当期所得
```

- 事業主貸・事業主借は翌期に引き継がず、元入金に吸収して残高ゼロから始める
- 当期所得は収益合計 − 費用合計（青色申告特別控除前）とする

`当期所得` の正本は、繰越処理内で ad-hoc に再計算せず、損益集計サービスの返り値を使う。

- 初期方針では `FiscalYearSummaryCalculator::calculate($fiscalYear)['actual']['profit']` を正とする
- これにより、ダッシュボード等で見えている年度損益と繰越時の `当期所得` が乖離しない
- 将来、決算用に gross / net や税区分を分けた専用集計が必要になった場合は、`FiscalYearSummaryCalculator` か別 calculator に「繰越用の当期所得」を明示的に追加し、`FiscalYearRollover` は常にその API を参照する
- この前提を成立させるため、`期首商品（棚卸高）` / `期末商品（棚卸高）` は `expense` タイプで管理する

### 残高計算への依存

繰越に必要なのは「全勘定科目の期末残高」そのものではなく、次の 2 種類である。

- 貸借対照表科目（資産・負債・純資産）の期末残高
- 損益科目（収益・費用）から算出した `当期所得`

収益・費用の各残高を翌期の期首仕訳へそのまま持ち越してはいけない。

- 損益科目は翌期首でゼロから始める
- その年度の損益は `当期所得` として `元入金` の組替にだけ使う
- 棚卸振替に使う `期首商品（棚卸高）` / `期末商品（棚卸高）` も損益科目としてここに含める

- 期末残高の算出は、残高試算表用の集計サービス（`TrialBalanceCalculator` を想定、別途設計）に依存する
- `FiscalYearRollover` 自身は残高計算を実装せず、集計サービスの結果を入力として使う
- 集計サービス側でも「繰越対象の貸借対照表科目」と「当期所得の計算元になる損益集計」を分けて返せる形が望ましい

### SubAccount 粒度での繰越

貸借対照表科目の繰越は、Account 単位に潰さず SubAccount 粒度で期首仕訳に落とす。

- `InventoryClosingService` は翌年度の期首棚卸高を「期首仕訳の `棚卸資産` 配下の SubAccount 別残高」から導出する。繰越が Account 単位に集約すると `商品` / `製品` / `材料` などの内訳が期首仕訳の時点で消え、翌年度の棚卸振替が単一 SubAccount への集約に退化する
- SubAccount 粒度で繰り越すことで、貸方は `元入金` に組み替えつつ、借方側は SubAccount ごとの資産残高として引き継がれ、年度をまたいだ SubAccount 単位の資産推移を追える
- `FiscalYearBalanceCalculator` は既に SubAccount 単位の残高を返しており、`OpeningEntryRegistrar` も `sub_account_name` 指定で SubAccount 粒度の期首仕訳を作れるため、繰越用の集計サービスも同じ粒度を維持する
- これは棚卸資産に限らず、預金口座別・借入先別など全貸借対照表科目に適用する

実装順序は 残高計算 → `FiscalYearCloser` → `FiscalYearRollover` とする。

### OpeningEntryRegistrar の拡張

現行の `OpeningEntryRegistrar` は初期セットアップ前提のため、次の制限がある。

- 借方に使える勘定科目が `現金` `定期預金` `その他の預金` `車両運搬具` `棚卸資産` に限られる
- 貸方が `元入金` に集約される

繰越では、売掛金・買掛金・借入金・未払金など任意の資産・負債残高を引き継ぐ必要があるため、次の方針で拡張する。

- 繰越由来の登録では、残高のあるすべての資産科目（借方）・負債科目（貸方）を許可する
- `元入金` は引き続き貸借差額を受ける行とする
- セットアップ経由の登録ルール（科目制限）は従来のまま維持し、繰越用の許可は `OpeningEntryRegistrar` の呼び出しモードとして分離する

## 並行操作の防止

`FiscalYearCloser::close()` と取引登録・改訂・確定が競合しうる。

- close 側は `lockForUpdate()` + 再検証で二重締めを防ぐ
- 登録側の `is_closed` ガードは既存のままとし、締めと同時に走った登録はどちらかが先に確定する
- 繰越側は期首仕訳の 1 年度 1 伝票制約（既存）を最終防壁とする

## 対象外

次は本ドキュメントのスコープ外とする。

- 消費税の申告額計算と納税仕訳 — 消費税集計の設計で扱う
- 貸倒引当金などの任意の決算整理 — 手動の決算整理仕訳として登録は可能、専用支援は将来拡張
- 青色申告特別控除の帳簿反映 — 控除は申告書上の計算であり帳簿には載せない
- 固定資産の除却・売却 — 固定資産側の拡張として別途扱う
- 月次締めオペレーションの設計 — 別ドキュメントで扱う

## まとめ

期末処理は「整理仕訳（既存 API + `is_adjusting_entry`）」「締め（`FiscalYearCloser` の validate + close）」「繰越（`FiscalYearRollover` → `OpeningEntryRegistrar`）」の 3 操作に分けて扱う。

- 締めは仕訳を作らない検証 + 状態遷移とし、チェックリストが期末処理の ToDo になる
- 繰越は元入金の組替を自動化し、期首仕訳の手入力と計算ミスをなくす
- 再オープンは初期実装では提供せず、締め済み年度の誤りは当期の修正仕訳で扱う

この分割により、各ステップが冪等になり、CLI 運用でも将来の UI でも同じ入口を使える。
