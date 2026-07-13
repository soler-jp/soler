# Blue Return Statement PDF Design

このドキュメントは、青色申告決算書(一般用)の PDF 出力機能(様式へのデータのマージ)の設計を整理したものです。

- 欄番号・欄キー・集計仕様の正本は `blue-return-statement-design.md`。本ドキュメントは帳票出力(PDF 生成)だけを扱う
- 実装手順・レビュー単位は `docs/implementation-plans/blue-return-statement-pdf.md`(本ドキュメントが設計の正本)

## 目的

`FiscalYear` の青色申告決算書集計(`FiscalYear::calculateBlueReturnStatement()`)と決算書入力(`BlueReturnInput`)から、**印刷して税務署に提出できる青色申告決算書(一般用)の PDF** を生成してダウンロードできるようにする。

対応様式は 2 版:

- 「令和五年分以降用」(`from2023`: 1ページ = FA3001 損益計算書、2ページ = FA3026 月別売上等、3ページ = FA3051 減価償却等、4ページ = FA3076 貸借対照表)
- 「令和二年分以降用」(`from2020`: 1ページ = FA3000、2ページ = FA3025、3ページ = FA3050、4ページ = FA3075)

## 前提: 国税庁様式 PDF の調査結果

`https://www.nta.go.jp/taxes/shiraberu/shinkoku/yoshiki/01/shinkokusho/pdf/r05/10.pdf`(国税庁配布の様式 PDF)を調査した結果:

- 8 ページ構成(1〜4 = 提出用、5〜8 = 同一様式の控用)。AcroForm フィールドは存在しない
- ユーザーパスワード空の暗号化がかかっており、権限フラグは **印刷: 許可 / コピー・抽出: 許可 / 内容の変更: 不許可 / 文書アセンブリ: 不許可**
- ラベル文字は文字列として抽出できない(大半はフォントですらなくグリフ輪郭のパスとして描かれている)。ただし**罫線・桁ガイド矩形は座標付きで抽出できる**(詳細は `tools/blue-return-template/README.md`)

## 方式の決定

### 復号しての重ね書きはしない・下地は公式様式のレンダリング画像を使う

国税庁 PDF を**復号して** PDF のままオーバーレイ印字する案は**採用しない**。「内容の変更: 不許可」という技術的措置を回避して改変・再頒布する形になり、著作権法・不正競争防止法上のグレーゾーンに踏み込むため。

代わりに、**公式 PDF の 1〜4 ページをレンダリングした画像(PNG)を下地(全面背景)にし、その上に値を印字する**。この方式が許容できる理由:

- レンダリング(閲覧・印刷と同じ経路)による画像化は、権限フラグで**明示的に許可された操作(印刷・コピー/抽出)の範囲内**であり、技術的保護を回避しない。復号済みの編集可能 PDF も生成しない
- 国税庁ホームページのコンテンツは「公共データ利用規約(第1.0版)」準拠で、**出典記載を条件に複製・翻案・再配布(商用含む)が明示的に許諾されている**(https://www.nta.go.jp/chuijiko/copy.htm)。適用除外(紋章・ロゴ・キャラクター等)は本様式に含まれない
- 記入済みの様式を印刷して提出するのは様式の本来の用途であり、市販会計ソフト出力の決算書と同様に受理される

利用条件の実装として、**出典(「出典: 国税庁ホームページ」+ URL)と、様式画像に値を印字する加工を行っている旨を、背景画像を置くディレクトリの README に明記**する。

幾何情報(罫線・矩形の座標)も同じく国税庁 PDF から**読み取りのみ**で抽出してよい。ユーザーパスワード空で開いて読むのは通常の閲覧行為であり、保護の回避を伴わない。

### 下地とオーバーレイの分担

- **下地(様式の静的要素すべて)**: 公式 PDF の 1〜4 ページを 300dpi でレンダリングした PNG(`resources/blue-return/templates/<version>/background/page{1..4}.png`)を、生成 PDF の各ページに全面背景として貼る。罫線・見出し・欄番号など静的な要素はすべてこの画像が持つ。背景 PNG・geometry JSON・生成ツール(`tools/blue-return-template/`)はリポジトリにコミットせず(.gitignore 済み)、各環境で原本 PDF から `render_background.py` 等により生成する
- **オーバーレイ(値のみ)**: 金額・文字列の値を、**PHP のオーバーレイ定義配列(欄キー → 座標)** に従って下地の上に印字する。オーバーレイ定義は手書きせず、幾何データと対応表から自動生成する(「座標作成と検証の支援」参照)

背景 PNG と geometry JSON は**同じ公式 PDF から機械的に生成される**ため、オーバーレイ座標と下地のズレが構造的に発生しない。また既存 PDF の取り込みが不要になったため **FPDI は不要**で、依存は **`tecnickcom/tcpdf` の 1 つだけ**(背景は TCPDF の `Image()` で全面に貼る)。

経緯の記録: ①geometry JSON からの様式全自動描画(ラベル文字のグリフ輪郭がパスに混入し、文字と図形を機械的に区別できず断念)→ ②手動作成の form.pdf を下地にする案(作成の作業量が過大で断念)→ ③本方式(公式レンダリング画像の下地)に至った。

## してはいけないこと(ハード制約)

1. **`https://www.nta.go.jp/taxes/shiraberu/shinkoku/yoshiki/01/shinkokusho/pdf/r05/10.pdf` を復号・改変しない**。pypdf 等での復号済み再書き出しも禁止
2. **復号・加工した派生 PDF をリポジトリにコミットしない**
3. 元 PDF の用途は「閲覧」「座標・幾何情報の読み取り抽出」「生成結果との目視比較」のみ
4. 集計ロジックを再実装しない。金額は必ず `FiscalYear::calculateBlueReturnStatement()` の結果を使う
5. 青色申告特別控除額を DB に保存しない(`blue-return-statement-design.md` どおり引数渡し)
6. composer 依存の追加は `tecnickcom/tcpdf` と、フォント同梱(「描画の仕様」)のみ。それ以外の依存追加は事前承認が必要
7. 背景画像ディレクトリの README(出典・加工の旨の記載)を削除・改変しない

## アーキテクチャ

### レイヤ構成

責務を 4 層に分け、混ぜない:

| 層 | 実体 | 責務 | 持ってはいけない知識 |
|---|---|---|---|
| 下地テンプレート | 公式様式のレンダリング画像(`background/page{1..4}.png`・ツールで生成) | 罫線・見出し・欄番号など様式の静的要素すべて | (静的アセット。コードではない) |
| オーバーレイ定義 | PHP 配列(バージョン付き・自動生成) | 欄キー → 値の印字座標 | 金額の整形・集計 |
| 整形層 | `FieldFormatter` | 集計結果・入力値 → 欄キーごとの印字文字列 | TCPDF・座標 |
| 印字・合成 | `OverlayRenderer`(TCPDF) | 背景 PNG の配置と、定義に従った文字の配置 | 集計・表示ルール |

### テンプレートのバージョン管理

決算書の様式は数年おきに改定される(直近は令和2年分・令和5年分)。`blue-return-statement-design.md` の方針どおり、欄キー・集計仕様は年度で分けず、**様式改定は帳票テンプレート層で吸収する**。そのための構造を初版から入れる:

- **テンプレート = 「background PNG(公式様式のレンダリング) + geometry JSON + fields_map + オーバーレイ定義配列」の 1 セット**をバージョン付きで持つ。現在は `from2023`(令和五年分以降用)と `from2020`(令和二年分以降用)の 2 バージョン
- **`TemplateResolver`** が `FiscalYear` の年からテンプレートバージョンを決める。マッピングは「**2023 年(令和 5 年)分以降 → `from2023`**」「**2020〜2022 年分 → `from2020`**」
- テンプレートが存在しない年(2019 年以前)は**明示的な例外で拒否**する。誤った様式でサイレントに出力しない
- 様式間で欄配置が同一のページ(1ページ・4ページは様式番号だけが違い、geometry JSON の突き合わせで配置一致を確認済み)は、`From2020` 側が `From2023` の定義を `require` で共用する。主な差分は 2〜3 ページ: 地代家賃の内訳のページ(`from2023` は 2 ページ・`from2020` は 3 ページ)、減価償却費の計算の行数(7 行 / 11 行)、専従者給与の内訳の行数など
- 新様式が出るまでは、将来の年にも最新バージョンを適用する
- 古い年分のテンプレート(例: 令和 2〜4 年分用)を後から追加する可能性がある。追加時の作業が「バージョンディレクトリ一式の追加 + リゾルバのマッピング 1 行」に収まる構造にすること
- `FieldFormatter`(整形層)と `BlueReturnStatementPdfGenerator` は**バージョン非依存**を保つ。バージョンごとに変わってよいのは座標・ラベル・下地だけ
- `OverlayRenderer` はオーバーレイ定義配列からのデータ駆動で印字し、全バージョンで共有する。定義で表現しきれない改定が将来来た場合のみ、バージョン別実装を許す

### クラス配置(`app/Services/BlueReturnPdf/` 配下に新設)

- `BlueReturnStatementPdfGenerator` — 入口。`FiscalYear` + 控除額を受け、テンプレート解決 → 集計・入力の収集 → 整形 → 下地取り込み+印字を統括し PDF バイナリ(string)を返す(バージョン非依存)
- `TemplateResolver` — `FiscalYear` の年 → テンプレートバージョン。未対応年は例外
- `OverlayRenderer` — 背景 PNG を各ページに全面配置し、オーバーレイ定義配列に従って値を印字する(データ駆動・全バージョン共有)
- `FieldFormatter` — 整形層。純粋な配列変換(`array → array<string, string>`)で、TCPDF に依存しない(バージョン非依存)
- `Templates/From2023/Page1Overlay.php` 〜 `Page4Overlay.php`・`Templates/From2020/Page1Overlay.php` 〜 `Page4Overlay.php` — オーバーレイ定義配列(バージョン付き)。`From2023/Page1Overlay.php` は fields_map からの**自動生成物で手編集禁止**。2〜4 ページと `From2020` は手書きの骨格定義(digit_cells は geometry JSON の抽出値を転記、box / text は罫線グリッドからの算出値で、プルーフ画像での校正を前提とする)。仕様は次項
- `Proof/FieldCatalog.php` — 校正用テストパターン(全欄を最大桁・最長文字列で埋めたダミーデータ)の欄カタログ

欄キーは `blue-return-statement-design.md` の欄一覧(`sales_amount`〜`business_income` 等)と一致させ、**全テンプレートバージョンで共通**とすること。

### オーバーレイ定義配列の仕様

ページごとに 1 ファイル(`Page{N}Overlay.php`)。欄キーごとに、印字する要素を持つ:

```php
return [
    'page' => 1,
    'fields' => [
        // 金額。桁ボックスがある欄は 1 桁ずつ配置。
        // cells には数字マスだけを入れる(桁区切りの細マス(幅約3pt)は含めない)
        'sales_amount' => [
            'amount' => [
                'type' => 'digit_cells',
                'top' => 340.6, 'bottom' => 354.6,
                'cells' => [
                    ['x0' => 265.2, 'x1' => 290.2],
                    ['x0' => 301.3, 'x1' => 312.4],
                    // ... 数字マスの x0/x1、左→右
                ],
            ],
        ],
        // 桁ガイドのない欄(3・4ページの表など)はカンマ付き右詰めの box 型(size は省略可・既定 11pt)
        'balance_asset_cash_ending' => [
            'amount' => ['type' => 'box', 'x0' => 351.0, 'x1' => 470.0, 'y' => 172.0, 'align' => 'R', 'size' => 8.0],
        ],
        // 文字列の値(内訳の氏名・住所、ヘッダー欄など)
        'business_type' => [
            'text' => ['x0' => 494.0, 'x1' => 585.0, 'y' => 210.0, 'align' => 'L', 'size' => 8.0],
        ],
    ],
];
```

- 要素は 2 種類: `amount`(金額) / `text`(文字列の値)。どちらも整形層の出力(欄キー → 印字文字列)を流し込む。静的な文字(見出し・欄番号)は下地側にあるため、オーバーレイには存在しない
- 座標の単位・原点は geometry JSON と同じ(左上原点・y 下向き・pt)
- 可変行(月別・内訳・減価償却明細・貸借対照表の行)は、行ごとに欄キーを分ける(例: `monthly_sales_1`〜`monthly_sales_12`)。生成後の配列は「欄キー → 要素」のフラットな形に揃えること
- **自動生成しているのは `From2023/Page1Overlay.php` のみ**で、こちらは手で編集しない。手作業の入力は対応表(fields_map、後述)に集約し、修正は「対応表を直して再生成」で行う。2〜4 ページと `From2020` の定義は手書きの骨格定義で、座標の修正はプルーフ画像での校正で直接行う

### 金額の右寄せ処理

責務分担: **整形層(`FieldFormatter`)は完成した印字文字列(カンマ区切り・負値は「△」前置。例: `△1,234,567`)を作るだけ**とし、右寄せの実現は `OverlayRenderer` の機械的な処理とする。整形層は欄の型(digit_cells / box)を知らない。

`digit_cells` 型(1ページ全欄・2ページの一部):

1. 印字文字列からカンマを除去する(表示ルールではなく配置のための機械的変換。カンマの代わりは様式側の桁区切りマスが担う)
2. 残った文字(数字と「△」)を、**`cells` の右端のマスから左へ 1 文字ずつ**割り当てる。「△」は自然に最上位桁の 1 つ左のマスに載る
3. 各文字はマスの水平中央・垂直中央(`top`〜`bottom` の中央)に印字する
4. 文字数が `cells` のマス数を超える場合は**例外で拒否**する(金額をサイレントに欠損・切り捨てしない)

`box` 型(3・4ページの表など桁ガイドのない欄):

- TCPDF のセル出力で `[x0, x1]` の幅に右詰め(`align: R`)し、カンマ付き文字列をそのまま印字する

0 と空欄の使い分け(0 を印字するか、何も印字しないか)は整形層の責務で、記載例(`https://www.nta.go.jp/taxes/shiraberu/shinkoku/tebiki/2025/pdf/037.pdf`)の記法に合わせる。`OverlayRenderer` は空文字列を受けたら何も印字しない。

テスト観点: 1 桁 / マスちょうど満杯 / △付き満杯 / 桁あふれ(例外) / 0 / 空欄 / box 型の右詰め、を `OverlayRenderer`・`FieldFormatter` それぞれのユニットテストにする。

### 座標作成と検証の支援(試行錯誤の最小化)

座標の手打ちと目視チェックの反復を減らすため、「座標は書かずに生成する」「目視の前に機械検証で落とす」「目視はプルーフ画像を眺めるだけにする」の 3 段構えにする。

**1. 座標は書かずに生成する(スキャフォールド)**

```
https://www.nta.go.jp/taxes/shiraberu/shinkoku/yoshiki/01/shinkokusho/pdf/r05/10.pdf (読み取りのみ・原本)
        │ extract_geometry.py  (実施済み)        │ render_background.py
        ▼                                        ▼
geometry/page{1..4}.json                background/page{1..4}.png  (下地)
        │ annotate_groups.py が「欄グループ番号付きの画像」を生成
        ▼
fields_map/page{1..4}.json  (欄キー ↔ グループ番号の対応表。★唯一の手作業)
        │ generate_overlay_php.py  (geometry + fields_map → PHP を丸ごと生成)
        ▼
Templates/From2023/Page{1..4}Overlay.php  (自動生成。手編集禁止)
```

geometry JSON と背景 PNG は同じ原本から機械的に生成されるため、座標系(左上原点・pt)が常に一致する。

- `annotate_groups.py`: geometry JSON の欄グループ(digit_cell_groups・表セル)に通し番号を振り、公式様式のレンダリング画像上に番号を描いた画像を出力する
- 手作業は、番号付き画像を見ながら **fields_map(欄キー ↔ グループ番号の対応表)を書くことだけ**(1 ページあたり数十行)。桁ガイドのない欄(box / text 型)の座標指定もこのファイルに書く
- `generate_overlay_php.py`: 数字マスの x0/x1 の転記・桁区切り細マスの除外を自動で行い、`Page{N}Overlay.php` を決定的に生成する。座標の修正は fields_map を直して再生成する(再生成は常に安全)
- **実績**: このフローを完遂しているのは `from2023` の 1 ページ(`fields_map/page1.json` → `From2023/Page1Overlay.php`)のみ。2〜4 ページと `from2020` は fields_map を作らず、geometry JSON の抽出値・罫線グリッドを参照した手書き定義+プルーフ画像での校正で作成した

**2. 目視の前に機械検証で落とす(PHPUnit)**

- 網羅性: 設計書の欄一覧の全キーがオーバーレイ定義に存在すること
- 健全性: 全要素がページ境界内にあること・要素同士が重ならないこと・digit_cells が左→右で昇順であること
- 整合性: digit_cells の各マスが geometry JSON(公式様式)の数字マスと許容差(±0.5pt)で一致すること — 対応表の行ズレ・生成ミスを目視より先に機械検出する

**3. 目視は「プルーフ画像を眺める」だけにする**

- artisan コマンドが**テストパターン**(全欄をマス満杯の最大桁・「△」付き・最長文字列で埋めたダミーデータ)入りの PDF を生成する。1 回の目視で全欄のあふれ・ズレを一括検出できる
- `make_proofs.py`(実装済み)がプルーフ画像を全ページ分まとめて出力する:
  - **Proof A**: 公式様式のレンダリング画像 × オーバーレイのみ PDF(値だけを白紙に印字したもの)の**赤色合成** — 値がどの欄のどのマスに載ったかが一目で分かる
  - **Proof B**: 生成 PDF そのもののレンダリング — 実出力の確認
- 下地が公式様式のレンダリングそのものになったため、下地のズレ検証(Proof C)は不要になった(ツールには実装済みのまま残っている)。ズレが出るとしたら原因は fields_map(対応表)だけなので、修正先が一意に決まる
- (任意・将来)承認済みプルーフ画像との画像回帰テストで、以後の変更による意図しないズレを自動検知する

これらのツールは開発時専用であり、**実行時(PDF 生成)に Python・外部コマンド・元 PDF への依存は一切残さないこと**。実行時に使うのは `Page{1..4}Overlay.php` と `background/page{1..4}.png` だけ。

### 描画の仕様

- ページサイズ: A4 横(842 × 595 pt)。4 ページ構成(提出用相当)。控用の複製は初版ではスコープ外
- **下地背景 PNG の仕様**: 公式 PDF の 1〜4 ページ(提出用)を `render_background.py` で 300dpi でレンダリングした PNG。公式のカラーのまま使う(白黒印刷でも問題ない)。再生成手順は `tools/blue-return-template/README.md` に従う
- オーバーレイは黒で印字する
- 日本語フォントは **IPAexゴシックを埋め込み**で使う(IPA フォントライセンスに基づき、フォントファイルとライセンス文書をリポジトリに同梱する)。TCPDF 非埋め込み CID フォントは閲覧環境依存があるため使わない
- 金額の右寄せは「金額の右寄せ処理」のとおり
- 年・自至の日付は `FiscalYear` から導出し、和暦(令和)で印字する

## データソース仕様

### 集計結果

`FiscalYear::calculateBlueReturnStatement(int $blueReturnDeduction): array` が正本。返り値は 4 ブロック:

- `profit_and_loss` — 損益計算書 ①〜㊺(キーと欄番号の対応は `blue-return-statement-design.md` の欄一覧)
- `monthly_sales_and_purchases` — 月別売上(収入)金額及び仕入金額
- `depreciation_calculation` — 減価償却費の計算
- `balance_sheet` — 貸借対照表(期首・期末)

構造の詳細は `app/Services/BlueReturnStatementCalculator.php` の PHPDoc(array shape)を読むこと。

### 決算書入力(帳簿外情報)

`BlueReturnInput`(`fiscal_year_id` / `key` / `value(JSON)`)。既存キー:

- 専従者給与の内訳(`from2023` は2ページ) — **内訳の給料+賞与の合計が損益計算書 ㊳ と一致しない内訳は、保存時(`BlueReturnInputRegistrar`)に `ValidationException` で拒否する**(PDF 生成時には再検証しない)
- 地代家賃の内訳(`from2023` は2ページ・`from2020` は3ページ)

このほか、様式ヘッダー欄(整理番号・住所・フリガナ・氏名・事業所所在地・業種名・屋号・電話番号・加入団体名・依頼税理士等)は帳簿外情報のため、`BlueReturnInput` には保存せず、**生成時の引数(`generateBlueReturnStatementPdf()` の `$header` 配列)で受ける**。許可キーは `FieldFormatter::PAGE1_HEADER_KEYS` で定義し、未知のキーは例外で拒否する。全項目任意入力とし、未入力は空欄で印字する。整理番号(`filing_number`)・氏名・フリガナは 2 ページ以降の該当欄にも印字する。

### 控除額

青色申告特別控除額は生成時に引数で受ける。保存しない(`blue-return-statement-design.md`「青色申告特別控除額の扱い」参照)。

## ファイル配置

```
tools/blue-return-template/          # 開発ツール一式(リポジトリ未コミット・.gitignore 済み)
  extract_geometry.py        # 幾何抽出ワンショットスクリプト
  verify_overlay.py          # 抽出結果の目視検証ツール
  render_background.py       # 公式PDF → 下地背景PNG の生成
  make_proofs.py             # プルーフ画像の一括生成
  annotate_groups.py         # 欄グループ番号付き画像の生成
  generate_overlay_php.py    # geometry + fields_map → PageNOverlay.php の生成
  requirements.txt
  README.md                  # 実行手順・再生成手順・元PDFの知見
resources/blue-return/
  templates/
    from2023/
      background/page{1..4}.png    # 公式様式のレンダリング画像(下地。未コミット・ローカル生成)
      background/README.md         # 出典(国税庁ホームページ)と加工の旨の記載
      geometry/page{1..4}.json     # 抽出済みの幾何データ(公式様式の座標。未コミット・ローカル生成)
      fields_map/page1.json        # 欄キー↔グループ番号の対応表(1ページのみ。2〜4ページは手書き定義のため無し)
    from2020/
      background/page{1..4}.png    # 同上(未コミット・ローカル生成)
      geometry/page{1..4}.json     # 同上(未コミット・ローカル生成)
resources/pdf/
  fonts/                     # IPAexゴシック + ライセンス文書
app/Services/BlueReturnPdf/
  BlueReturnStatementPdfGenerator.php
  TemplateResolver.php
  OverlayRenderer.php
  FieldFormatter.php
  Proof/
    FieldCatalog.php         # 校正用テストパターンの欄カタログ
  Templates/
    From2023/
      Page1Overlay.php 〜 Page4Overlay.php   # Page1 は自動生成物(手編集禁止)、Page2〜4 は手書きの骨格定義
    From2020/
      Page1Overlay.php 〜 Page4Overlay.php   # Page1・Page4 は From2023 を require で共用、Page2〜3 は手書きの骨格定義
```

校正用の artisan コマンド(`blue-return:proof-all`)は `routes/console.php` に定義している(`manual/blue-return-proof.md` 参照)。

テンプレートバージョンを追加するときは `resources/blue-return/templates/<version>/` と `app/Services/BlueReturnPdf/Templates/<Version>/` を一式追加し、`TemplateResolver` のマッピングに 1 行足す。

## スコープ外(やらないこと)

- 令和元年分(2019 年)以前のテンプレートの追加(令和二年分以降用 `from2020` は追加済み)。**バージョン構造により、追加自体は「テンプレート一式 + リゾルバ 1 行」でできる状態を保つこと**
- 控用ページの複製出力(必要なら将来「控」表記付きで同一 4 ページを追加出力する)
- e-Tax 電子提出(XML)
- 確定スナップショットからの出力(親計画ステップ 8 実装時にこの生成器へ接続する。現状は都度計算のみ)
- 貸倒引当金・任意科目 ㉕〜㉚・給料賃金の内訳・利子割引料/税理士報酬の内訳・製造原価の計算(欄は下地様式にあるが値を印字しない)
- 売上/仕入明細の取引先別内訳(`from2023` の 3 ページでは「上記以外の計」「計」に①・③の合計のみ印字する)
- 「うち軽減税率対象」欄(様式上も記入省略可)

## 参照資料

- `docs/blue-return-statement-design.md` — 欄一覧・集計仕様の正本
- `docs/implementation-plans/blue-return-statement-pdf.md` — 本設計の実装計画(実装順・レビュー単位)
- `docs/implementation-plans/blue-return-statement.md` — 親計画(本設計はそのステップ 9 の展開)
- `https://www.nta.go.jp/taxes/shiraberu/shinkoku/yoshiki/01/shinkokusho/pdf/r05/10.pdf` — 国税庁様式 PDF(閲覧・座標抽出・目視比較専用。改変禁止)
- `https://www.nta.go.jp/taxes/shiraberu/shinkoku/tebiki/2025/pdf/037.pdf` — 青色申告決算書(一般用)の書き方(記載例あり)
- `tools/blue-return-template/README.md` — geometry JSON の構造と元 PDF の知見
- `app/Services/BlueReturnStatementCalculator.php` — 集計結果の array shape(PHPDoc)
- `app/Models/BlueReturnInput.php` / `app/Services/BlueReturnInputRegistrar.php` — 決算書入力の保存
