# Transaction Registration

このドキュメントは、取引登録の入口を `TransactionRegistrar` に統一する方針を整理したものです。

## 目的

- 取引登録時の金額解釈を 1 か所に集約する
- `Transaction` と `JournalEntry` の直接生成によるルール逸脱を防ぐ
- 免税事業者と課税事業者の両方で、同じ保存データから異なる集計を行えるようにする

## 入口

取引登録のアプリケーション上の入口は `App\Services\TransactionRegistrar` とする。

原則として、以下は `TransactionRegistrar` を経由して行う。

- 通常の取引登録
- 予定取引の本登録
- 期首残高登録からの取引作成
- 固定資産登録に伴う取得仕訳の作成
- 今後追加される売上・経費入力 UI からの仕訳登録

`Transaction::create()` や `JournalEntry::create()` を直接呼ぶ実装は、内部ユーティリティやテストを除き増やさない。

## Registrar の責務

`TransactionRegistrar` は複数モデルをまたぐユースケース層として扱う。

- 会計年度の存在確認
- 事業体所属の整合確認
- 取引データと仕訳データのバリデーション
- 借方・貸方の整合確認
- 税情報を含む金額の正規化
- 取引先の解決と正規化
- `Transaction` と `JournalEntry` の永続化
- DB トランザクション境界の管理

## 取引先の扱い

`TransactionRegistrar` は、取引先の入力を次の 3 パターンで解釈する。

- `counterparty_id` を指定して既存の取引先を紐づける
- `counterparty_name` を指定して取引先マスターを自動作成し、紐づける
- どちらも指定せず、取引先未設定で登録する

補足:

- `counterparty_id` と `counterparty_name` は同時指定しない
- `counterparty_name` は前後の空白を除去してから扱う
- `counterparty_registration_number` は任意入力として扱う
- `counterparty_registration_number` は保存前に正規化し、`1234567890123` なら `T1234567890123` にそろえる
- 取引先未設定の取引も、従来どおり登録できる

## 取引状態変更との責務分離

`TransactionRegistrar` は「新規登録の入口」であり、既存 `Transaction` の状態変更までは持たせない。

- `register()` は `Transaction` と `JournalEntry` を新規作成するユースケースを扱う
- `is_active` / `deactivated_at` / `deactivated_by` / `deactivation_reason` の更新は `Transaction` 自身の明示メソッドで扱う
- `Transaction` の無効化は `Transaction::deactivate()` を正規ルートとする

この方針を採る理由は次の通り。

- 登録サービスに更新・削除責務まで混ぜないため
- `Transaction` 単体の状態遷移をモデルの public API として読めるようにするため
- 今後、無効化時の副作用が増えた場合に `TransactionDeactivator` のような専用サービスへ切り出しやすくするため

したがって、`TransactionRegistrar` 経由でしか `is_active` を変更できない設計にはしない。
一方で、アプリケーションコードから `is_active` を直接 mass assignment する実装は増やさず、無効化は明示メソッド経由に寄せる。

## 保存データの意味

`JournalEntry` に保存する金額は、将来の比較集計に使えるよう、税情報を失わない形にそろえる。

- `net_amount`
  - 税抜本体額
- `tax_amount`
  - その仕訳に対応する消費税相当額
- `gross_amount`
  - 入力値としての総額
- `net_amount + tax_amount`
  - 正規化後の総額であり、`gross_amount` と一致する値

この意味づけを採る場合、貸借一致の判定は総額ベースで行う。

## 正規化の基本方針

入力元の UI やユースケースが税込入力や取引先入力を受け取っても、保存前に `TransactionRegistrar` で内部表現へ正規化する。

想定する流れは以下。

1. 入力値から本体額と税額を決める
2. 借方・貸方それぞれの総額が一致することを確認する
3. `JournalEntry` には本体額と税額を分けて保存する

取引先が入力されている場合は、同じ入口で `counterparty_id` を確定する。
`counterparty_name` だけが渡された場合は、必要に応じて取引先マスターを作成して紐づける。

将来的に税込入力と税抜入力の両方を扱う場合も、入口は変えずに `TransactionRegistrar` 側で解釈を切り替える。

## 入力ポリシー

取引登録の public API は、保存形式ではなく入力形式の違いを吸収するために分ける余地がある。

想定する入力ポリシーは次の通り。

### 免税事業者

- UI では `gross_amount` を入力する
- 通常取引は、既定で見なし 10% として `net_amount` と `tax_amount` に分解する
- 本当に非課税の取引だけは `non_taxable` として扱う
- 生成された `tax_amount` は内部管理値であり、税務申告用の税額ではない

### 課税事業者

- UI では `gross_amount` と `tax_type` を入力する
- `tax_type` から税率を解決し、`net_amount` と `tax_amount` を計算する
- 本当に非課税の取引では `tax_amount = 0` とし、`non_taxable` などの区分を使う

### 共通

- 保存前に `gross_amount / net_amount / tax_amount / tax_type` へ正規化する
- `gross_amount = net_amount + tax_amount` を必須条件とする
- 貸借一致は総額ベースで判定する
- 取引先入力は、取引登録の補助情報として同一のユースケース内で解釈する

## 入力形式と保存形式の分離

`TransactionRegistrar` の責務は、入力値の正規化結果を保存することであり、UI ごとの入力フォーム差分を抱え込みすぎない方が良い。

そのため、実装上は次の分割を優先する。

- 入力形式ごとの正規化処理
  - 免税事業者用
  - 課税事業者用
- 共通の保存処理
  - バリデーション
  - 所属確認
  - 貸借一致確認
  - 永続化

必要であれば public メソッドを分けてもよいが、保存ロジック自体を二重化しない。

## 具体例

実装とテストの意図がぶれないよう、代表的な入力例を固定しておく。

### 1. 免税事業者の売上

- 条件
  - `is_taxable = false`
  - `is_tax_exclusive = false`
- 入力
  - 借方: `現金 / レジ現金`
  - 貸方: `売上高 / 一般売上`
  - 総額: `2,200`
- 保存イメージ
  - 借方: `net_amount = 2200`, `tax_amount = 0`, `tax_type = non_taxable`
  - 貸方: `net_amount = 2000`, `tax_amount = 200`, `tax_type = deemed_taxable_sales_10`
- 意味
  - 免税事業者でも、売上側は見なし 10% で内部按分して保持する
  - この `tax_amount` は申告税額ではなく、内部管理値である

### 2. 免税事業者の経費

- 条件
  - `is_taxable = false`
  - `is_tax_exclusive = false`
- 入力
  - 借方: `通信費`
  - 貸方: `現金 / レジ現金`
  - 総額: `1,100`
- 保存イメージ
  - 借方: `net_amount = 1000`, `tax_amount = 100`, `tax_type = deemed_taxable_purchases_10`
  - 貸方: `net_amount = 1100`, `tax_amount = 0`, `tax_type = non_taxable`
- 意味
  - 経費側だけを見なし 10% で分解し、支払手段側は総額そのままで持つ

### 3. 課税事業者の 10% 売上

- 条件
  - `is_taxable = true`
  - `is_tax_exclusive = false`
- 入力
  - 借方: `現金 / レジ現金`
  - 貸方: `売上高 / 一般売上`
  - 総額: `2,200`
  - 税区分: `taxable_sales_10`
- 保存イメージ
  - 借方: `net_amount = 2200`, `tax_amount = 0`, `tax_type = non_taxable`
  - 貸方: `net_amount = 2000`, `tax_amount = 200`, `tax_type = taxable_sales_10`
- 意味
  - 課税事業者では `gross_amount + tax_type` を受け、税区分に従って分解する

### 4. 課税事業者の 10% 経費

- 条件
  - `is_taxable = true`
  - `is_tax_exclusive = false`
- 入力
  - 借方: `通信費`
  - 貸方: `現金 / レジ現金`
  - 総額: `1,100`
  - 税区分: `taxable_purchases_10`
- 保存イメージ
  - 借方: `net_amount = 1000`, `tax_amount = 100`, `tax_type = taxable_purchases_10`
  - 貸方: `net_amount = 1100`, `tax_amount = 0`, `tax_type = non_taxable`
- 意味
  - 課税仕入・課税経費の代表ケースとして使う

### 5. 課税事業者の非課税経費

- 条件
  - `is_taxable = true`
  - `is_tax_exclusive = false`
- 入力
  - 借方: `地代家賃` など
  - 貸方: `現金 / レジ現金`
  - 総額: `1,000`
  - 税区分: `non_taxable`
- 保存イメージ
  - 借方: `net_amount = 1000`, `tax_amount = 0`, `tax_type = non_taxable`
  - 貸方: `net_amount = 1000`, `tax_amount = 0`, `tax_type = non_taxable`
- 意味
  - 本当に非課税の取引では、総額をそのまま本体額として保存する

### 6. 課税事業者の 8% 経費

- 条件
  - `is_taxable = true`
  - `is_tax_exclusive = false`
- 入力
  - 借方: `雑費`
  - 貸方: `現金 / レジ現金`
  - 総額: `1,080`
  - 税区分: `taxable_purchases_8`
- 保存イメージ
  - 借方: `net_amount = 1000`, `tax_amount = 80`, `tax_type = taxable_purchases_8`
  - 貸方: `net_amount = 1080`, `tax_amount = 0`, `tax_type = non_taxable`
- 意味
  - 軽減税率の仕入・経費も、10% ケースと同じ 2 行モデルで扱う

### 7. 課税事業者の混在レシート

- 条件
  - `is_taxable = true`
  - `is_tax_exclusive = false`
- 入力
  - 借方1: `仕入金額` `2,160` `taxable_purchases_8`
  - 借方2: `仕入金額` `5,500` `taxable_purchases_10`
  - 貸方: `現金 / レジ現金` `7,660` `non_taxable`
- 保存イメージ
  - 借方1: `net_amount = 2000`, `tax_amount = 160`
  - 借方2: `net_amount = 5000`, `tax_amount = 500`
  - 貸方: `net_amount = 7660`, `tax_amount = 0`
- 意味
  - 1 枚のレシートに 8% と 10% が混在する場合は、税率単位で借方明細を分割する
  - 貸方は支払総額 1 本でよい

## モデルとの責務分担

モデルは小さなドメインルールを担当し、ユースケース全体の進行は `TransactionRegistrar` が持つ。

- `FiscalYear`
  - 年度ごとの集計ポリシー
  - 課税/免税、税込/税抜の判定
- `JournalEntry`
  - 総額や税の有無など、1 明細単位の金額解釈
- `TransactionRegistrar`
  - 入力から保存までの一連の処理

## 実装前に固定したいテスト

### Registrar

- `TransactionRegistrar` 経由で登録した仕訳が貸借一致して保存される
- 税額を含む仕訳でも総額ベースで貸借一致を判定できる
- 免税事業者でも `tax_amount` を保持したまま登録できる
- 免税事業者の `gross_amount` 入力が、見なし 10% で `net_amount` と `tax_amount` に分解される
- 課税事業者の `gross_amount + tax_type` 入力が、正しい `net_amount` と `tax_amount` に分解される
- 事業体に属さない補助科目は登録できない

### モデル

- `JournalEntry` が本体額、税額、総額を一貫して扱える
- `JournalEntry` が `gross_amount` と正規化後の総額を一貫して扱える
- `FiscalYear` が課税/免税と税込/税抜の集計ポリシーを返せる

## スコープ外

このドキュメントは登録の入口と保存ルールを対象とする。

以下は別途設計する。

- 消費税申告書の具体的な計算ロジック
- 課税売上割合や簡易課税などの制度対応
- UI 上での税込入力・税抜入力の切り替え詳細
