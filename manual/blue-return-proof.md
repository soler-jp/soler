# 決算書オーバーレイの校正PDF

`Page1Overlay.php` / `Page2Overlay.php` が返す座標定義が正しいか（欄の取り違え・位置ズレがないか）を目視確認するための artisan コマンドがあります。

## 使い方

```bash
vendor/bin/sail artisan blue-return:proof-all
```

1ページ目は損益計算書の勘定科目（①〜㊺の45欄）ごとに校正PDFを1枚ずつ、1ページ目のヘッダー欄（年分・住所・氏名・電話番号・整理番号・期首期末月日ほか）と2〜4ページ目は全欄をテスト値で埋めた校正PDFを各1枚出力します。

```
storage/app/blue-return/proof-fields/
├── 01_sales_amount.pdf
├── 02_beginning_inventory.pdf
├── ...
├── 45_business_income.pdf
├── page1_header_fields.pdf # 1ページのヘッダー欄（年分・住所・氏名・電話番号・整理番号・期首期末月日ほか）
├── page2_all_fields.pdf # 2ページ（月別売上・専従者給与・地代家賃ほか）の全欄
└── _manifest.txt        # 出力された科目の一覧
```

出力に失敗した科目があると `_failed.txt` に理由つきで記録されます。

## オプション

| オプション | 既定値 | 説明 |
|---|---|---|
| `--template=` | `from2023` | 校正対象のテンプレート版。未対応の版を指定するとエラー終了する |
| `--output-dir=` | `storage/app/blue-return/proof-fields` | 出力先ディレクトリ |
| `--overlay-only` | なし | 背景PNGを描かず、オーバーレイだけを描画する（背景PNGが無い環境用） |

テンプレート版を指定して実行する例:

```bash
vendor/bin/sail artisan blue-return:proof-all --template=from2023
```

## 確認のしかた

各PDFには「背景（公式様式）+ 対象欄のオーバーレイ + 科目ラベル」が描かれます。

テスト値には**欄番号を桁マスいっぱいまで繰り返した数字**を使います。例えば⑧租税公課なら `88,888,888`、⑳給料賃金なら `20,202,020…` です。様式に印字された丸数字と、描画された数字が一致しているかを見るだけで、欄の取り違えや位置ズレを検出できます。

- 数字がマスの枠に収まっているか（はみ出し・偏り）
- 丸数字と描画された数字のパターンが一致しているか（行ズレ）
- テキスト欄は最長想定の文字列（`最長文字列` x 6）が範囲内に収まるか

## 対象欄の一覧

科目キー・ラベル・欄番号の対応は `FieldCatalog::profitAndLossFields()` が持っています。欄を追加・変更した場合はここも更新してください。

## 注意点

- 背景PNG（`resources/blue-return/templates/*/background/`）はリポジトリにコミットされていません。無い環境では `--overlay-only` を使ってください
- 1ページ目（損益計算書・ヘッダー欄）〜4ページ目（貸借対照表）に対応しています。2〜3ページ目の box / text 型の座標は暫定値です（桁マス欄と1ページ目のヘッダー欄は geometry JSON 由来）

## 参考

- `routes/console.php`（`blue-return:proof-all` コマンド）
- `app/Services/BlueReturnPdf/Proof/FieldCatalog.php`
- `app/Services/BlueReturnPdf/Templates/From2023/Page1Overlay.php`
- `app/Services/BlueReturnPdf/Templates/From2023/Page2Overlay.php`
- `manual/blue-return-pdf.md`（PDF生成APIの使い方）
