# FiscalYear Balance Design

このドキュメントは、`FiscalYear` から資産・負債・資本の残高を返せるようにするための設計メモである。

`docs/fiscal-year-design.md` が損益集計を扱うのに対し、このドキュメントは貸借対照表側の残高集計を扱う。

## 目的

- `FiscalYear` が損益だけでなく残高も返せるようにする
- 資産・負債・資本の期末残高を勘定科目・補助科目単位で取得できるようにする
- 現金や預金の帳簿残高を実残高と突合するための土台を作る
- 期首残高、通常仕訳、無効化済み取引を含む残高の扱いを固定する

## 背景

現状の `App\Services\FiscalYearSummaryCalculator` は、売上・経費の集計には使えるが、資産・負債・資本の残高は返さない。

そのため、次の確認ができない。

- 現金の帳簿残高と実際の手許現金の一致
- 預金の帳簿残高と通帳残高の一致
- 貸借対照表の各勘定の期末残高

記帳の正しさを検証するには、損益集計だけでは足りず、貸借対照表の残高集計が必要になる。

## 既存の入口

現時点の `FiscalYear` は次の API を持つ。

- `calculateSummary(): array`
- `calculateAmountSummary(): array`

このうち `calculateSummary()` は損益の総額ベース集計であり、`calculateAmountSummary()` は売上・経費の `net_amount` / `tax_amount` / `gross_amount` を返す。

この設計では、残高用に新しい入口を追加する。

- `calculateBalanceSummary(): array`

## 設計方針

### 1. 損益集計と残高集計を分ける

損益集計は収益・費用の集計、残高集計は資産・負債・資本の集計と役割が異なる。

そのため、`FiscalYearSummaryCalculator` に全部を詰め込まず、残高用の calculator を分ける。

- `FiscalYearSummaryCalculator`
  - 売上・経費の集計
- `FiscalYearBalanceCalculator`
  - 資産・負債・資本の残高集計

### 2. calculator は帳簿残高だけを返す

`FiscalYearBalanceCalculator` の責務は、帳簿上の残高（`book_balance`）を返すことに限定する。

- 実残高（手許現金・通帳残高など）は会計システムの外にある値であり、calculator は関知しない
- 実残高との差分計算は、実残高を入力として受け取る UI レイヤーの責務とする
- 突合機能そのものは、この残高 API を土台にして UI 側で実現する

### 3. 実績のみを対象にする

残高は実際に記帳された取引を基準にするため、予定取引は含めない。

- 対象
  - `Transaction.is_planned = false`
  - `Transaction.is_active = true`
- 対象外
  - `Transaction.is_planned = true`
  - `Transaction.is_active = false`

予定を含めた残高（資金繰り予測）は「いつ時点の残高か」という基準日の概念が必須になるため、この設計では扱わず、必要になったら別機能として設計する。

実績のみに固定するため、返り値に `actual` / `planned` のラッパーは持たせない。

### 4. 対象範囲は fiscal_year 基準にする

集計対象は `Transaction.fiscal_year_id` で決め、日付の期間条件は使わない。

- `FiscalYearSummaryCalculator` が `whereBelongsTo($fiscalYear)` で絞っているのと同じ基準に揃える
- 損益集計と残高集計で対象範囲がズレる余地をなくす
- 将来、月次残高（基準日指定）を導入する場合は、fiscal_year で絞った上で日付条件を追加する形で拡張する

この基準が成り立つ前提として、「取引日は必ず年度の `start_date`〜`end_date` 内である」という保存時不変条件を `TransactionRegistrar` に追加する（本設計とは独立した先行タスク）。

現状の `TransactionValidator` は取引日が年度内かを検証しておらず、年度外日付の取引が保存されうる。既存の損益集計もすでに fiscal_year 基準であるため、この穴は残高集計固有のものではない。保存時不変条件が入ることで fiscal_year 基準と日付基準が同値になり、日付で絞っている総勘定元帳とも数字が一致する。

### 5. 金額は gross（net + tax）基準にする

残高は `net_amount + COALESCE(tax_amount, 0)` を基準に集計する。

これは `TransactionRegistrar` の貸借一致チェックが税込（`net + tax`）で成立していることに合わせるためである。

net のみで集計すると、税額が片側の仕訳行にだけ載っている取引で、残高が実際の現金の動きと一致しなくなる。

### 6. 残高は勘定科目の正方向で返す

表示や突合で扱いやすくするため、残高は `account.type` に応じた自然な方向で返す。

- `asset`
  - 借方残高を正とする
- `liability`
  - 貸方残高を正とする
- `equity`
  - 貸方残高を正とする

calculator は勘定科目の名前を一切知らない。「現金」「定期預金」といった科目名による特別扱いは行わず、`type` だけで正方向を決める。

正方向へ正規化した結果が負になる場合（現金残高のマイナスなど）は、そのまま負の値で返す。丸めたり 0 に補正したりしない。負の値 = タイプの正方向と逆側の残高、という解釈を全科目で一貫させる。

既定科目の `事業主貸` は type が `equity` だが、実務上は借方残高が通常の科目である。そのため貸方正の正規化ではほぼ常に負の値で返る。これは異常値ではなく期待される状態であり、calculator では補正しない。借方側での表示（絶対値表示や貸借対照表での配置）は UI レイヤーの責務とする。

この扱いにより、equity の `total_balance` は「元入金 + 事業主借 − 事業主貸」となり、単純合計のまま正味の資本を表す。科目ごとの正方向メタデータを持たない代わりに、「total は配下の単純合計」という不変条件を全タイプで維持する。

## 返り値の shape

資産・負債・資本の 3 分類を、勘定科目 → 補助科目の階層で返す。

```php
[
    'asset' => [
        'total_balance' => 0,
        'accounts' => [
            [
                'account_id' => 1,
                'account_name' => '現金',
                'balance' => 0,
                'sub_accounts' => [
                    [
                        'sub_account_id' => 11,
                        'sub_account_name' => 'レジ現金',
                        'balance' => 0,
                    ],
                ],
            ],
        ],
    ],
    'liability' => [
        'total_balance' => 0,
        'accounts' => [],
    ],
    'equity' => [
        'total_balance' => 0,
        'accounts' => [],
    ],
]
```

不変条件:

- `account` の `balance` は、その配下の `sub_accounts` の `balance` の合計と一致する
- `total_balance` は、そのタイプ配下の `accounts` の `balance` の合計と一致する

## 集計ルール

### 1. 仕訳の対象範囲

- `Transaction.fiscal_year_id` が対象年度のもの
- `Transaction.is_active = true`
- `Transaction.is_planned = false`

無効化の判定は親 `Transaction` の `is_active` のみで行う。`JournalEntry.is_effective` は現時点で未使用のカラムであり、残高集計では参照しない。

### 2. 期首残高の扱い

期首仕訳は通常の取引として扱い、残高に含める。

これは `OpeningEntryRegistrar` が作る仕訳を特別扱いしすぎないためである。

- `is_opening_entry = true` はメタ情報として保持する
- 期首仕訳は `fiscal_year_id` を持つため、fiscal_year 基準の絞り込みで自然に含まれる

### 3. 借方と貸方の差分で見る

売上・経費のような総額集計ではなく、借方合計と貸方合計の差分から残高を求める。

- 各仕訳行の金額は `net_amount + COALESCE(tax_amount, 0)`
- 借方合計と貸方合計の差分を取り、`account.type` の正方向へ正規化して `balance` とする

## 想定実装

### 1. `FiscalYear` に残高 API を追加する

```php
public function calculateBalanceSummary(): array
{
    return app(FiscalYearBalanceCalculator::class)->calculate($this);
}
```

### 2. 残高計算は専用 calculator で行う

`FiscalYearBalanceCalculator` は次の責務を持つ。

- 資産・負債・資本の 3 タイプについて、勘定科目・補助科目ごとの残高を集計する
- 空データ時は各タイプの `total_balance = 0`、`accounts = []` を返す

集計は補助科目単位で group した集約クエリで行い、PHP 側で 科目 → タイプ に積み上げる。勘定科目ごとにクエリを発行しない。

### 3. 元帳ロジックは再利用しない

`GeneralLedgerService` は表示用の行生成に向いているが、残高集計とは目的が異なるため再利用しない。

対象条件（`is_active` / `is_planned` / fiscal_year 基準）は `FiscalYearSummaryCalculator` と揃える。

### 4. 検算

貸借対照表側の 3 タイプだけでは、期中は「資産 = 負債 + 資本」にならない。差額は当期損益に一致する。

記帳の正しさを検証する手段として、収益・費用も含めた全 5 タイプで借方合計と貸方合計が一致することを確認する検算を calculator に持たせることを検討する（初版では必須としない）。

UI で 3 タイプの合計を並べて表示する場合は、差額が当期損益であることを説明する必要がある。

## 突合の使い方

現金や預金の突合は次の流れを想定する。

1. `calculateBalanceSummary()` で科目別の帳簿残高を取得する
2. UI が突合対象の勘定科目（現金・預金など）を選ぶ
3. 画面や外部取込から実残高を入力する
4. UI が差分を計算して表示する
5. 差分が 0 なら一致とみなす

どの科目を突合対象にするかは UI が決める。calculator は科目の選別に関与しない。

## 今後の論点

### 1. 月次残高（基準日指定）

月次で突合するには「指定日時点の残高」が必要になる。

fiscal_year 基準の絞り込みに日付条件を追加する形で拡張できるが、この設計では扱わない。

### 2. 収益・費用を残高側に含めるか

決算整理や締め処理が入るなら、収益・費用も残高の一部として扱いたくなる可能性がある。

ただし、現時点では損益集計と混ぜずに分けておく方が安全である。検算（想定実装 4）を導入する場合は、その内部でのみ全 5 タイプを扱う。

### 3. 実残高の入力元

実残高をどこから持ってくるかは UI レイヤーの責務である。

候補は次の通り。

- 手入力
- 銀行明細取込
- 月次残高のCSV取込

## 非目標

このドキュメントでは次を扱わない。

- 実残高との差分計算・突合 UI（残高 API を土台に UI レイヤーで実現する）
- 予定取引を含めた残高（資金繰り予測）
- 銀行連携そのものの実装
- 残高の自動照合アルゴリズム
- 決算整理仕訳の自動生成
- 税申告の最終値確定

## まとめ

`FiscalYearSummaryCalculator` が扱っているのは損益であり、残高検証には別の集計が必要である。

`FiscalYearBalanceCalculator` は「fiscal_year 基準・実績のみ・gross 金額で、タイプ別・科目別の帳簿残高を返す」ことだけを責務とする。実残高との突合は、この API を土台にして UI レイヤーで実現する。
