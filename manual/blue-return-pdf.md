# 決算書PDFの生成

`FiscalYear` には、青色申告決算書のPDFを生成する API があります。

公式様式のレンダリング画像（背景PNG）の上に、帳簿から算出した金額をオーバーレイ描画する方式です。4ページすべて（損益計算書・月別売上（収入）金額及び仕入金額・専従者給与や地代家賃の内訳・減価償却費の計算・貸借対照表など）のオーバーレイに対応しています。

## 使い方

`FiscalYear` 経由で生成します。戻り値はPDFのバイナリ文字列です。

```php
$fiscalYear = auth()->user()->selectedBusinessUnit->currentFiscalYear;

$pdf = $fiscalYear->generateBlueReturnStatementPdf(650_000, [
    'address' => '東京都千代田区霞が関1-2-3',
    'name_kana' => 'ヤマダ タロウ',
    'name' => '山田 太郎',
    'business_address' => '東京都千代田区丸の内9-8-7',
    'home_phone_number' => '03-1234-5678',
    'business_phone_number' => '090-1234-5678',
    'business_type' => 'ソフトウェア開発業',
    'trade_name' => 'ソレル商店',
    'association_name' => '東京青色申告会',
    'tax_accountant_office_address' => '東京都新宿区西新宿1-2-3',
    'tax_accountant_name' => '税理 士郎',
    'tax_accountant_phone_number' => '03-9876-5432',
    'filing_number' => '12345678',
]);

// ファイルに保存する場合
file_put_contents(storage_path('app/blue-return/statement.pdf'), $pdf);

// HTTPレスポンスとして返す場合
return response($pdf, 200, [
    'Content-Type' => 'application/pdf',
    'Content-Disposition' => 'inline; filename="blue-return-statement.pdf"',
]);
```

第1引数は青色申告特別控除額です。金額の算出は内部で `calculateBlueReturnStatement()` を使うため、事前に決算書入力（`manual/blue-return-inputs.md`）が保存されていればそれも反映されます。

第2引数は住所・氏名などヘッダー欄の帳簿外情報（省略可）です。全キー任意入力で、未指定のキーは空欄で印字されます。上記以外のキーを渡すと `RuntimeException` になります。`name` / `name_kana` は2ページ目の氏名・フリガナ欄に、`filing_number` は2〜4ページ目の整理番号欄にも印字されます。年度（和暦）と損益計算書・貸借対照表の期首・期末月日は `FiscalYear` から自動で印字されるため渡す必要はありません。

## テンプレートの解決

年度に応じて様式テンプレートが選択されます。

- 2023年分（令和五年分）以降: `from2023`
- 2020〜2022年分（令和二〜四年分）: `from2020`
- 2019年分以前: `InvalidArgumentException`（未対応）

## 出力される内容

- 横向きA4相当（842pt x 595pt）の4ページ構成
- 各ページに公式様式の背景PNGを全面配置
- 1ページ目に損益計算書の金額（①〜㊺）と、ヘッダー欄（年分（和暦）・住所・フリガナ・氏名・事業所所在地・電話番号・業種名・屋号・加入団体名・依頼税理士等・整理番号・損益計算書の期首/期末月日）をオーバーレイ描画
- 2ページ目に令和年号・月別売上（収入）金額及び仕入金額・専従者給与の内訳・地代家賃の内訳（`from2023`）・青色申告特別控除前の所得金額・氏名・フリガナ・整理番号をオーバーレイ描画（専従者給与・地代家賃の内訳は決算書入力から反映）
- 3ページ目に売上（収入）金額・仕入金額の明細の「上記以外の計」「計」（`from2023`）・減価償却費の計算・地代家賃の内訳（`from2020`）・整理番号をオーバーレイ描画
- 4ページ目に貸借対照表（期首・期末）・期首/期末月日・整理番号をオーバーレイ描画
- 地代家賃の内訳は様式版で載るページが違うため（`from2023` は2ページ・`from2020` は3ページ）、欄がある版のページにだけ印字される
- フォントは IPAexゴシック（サブセット埋め込み）

## 校正用コマンド

オーバーレイの位置ズレを目視確認するための artisan コマンドがあります。詳細は `manual/blue-return-proof.md` を参照してください。

```bash
vendor/bin/sail artisan blue-return:proof-all --template=from2023
```

## 注意点

- 背景PNG（`resources/blue-return/templates/*/background/`）はリポジトリにコミットされていません。ローカルで `tools/blue-return-template/render_background.py` により生成する必要があります
- 2〜4ページ目の box / text 型の座標は罫線グリッド等から算出した暫定値です（桁マス欄は geometry JSON の抽出値）。ズレは校正用コマンドのプルーフ画像で確認してください
- 専従者給与の内訳合計と損益計算書㊳の一致チェックは決算書入力の保存時（`manual/blue-return-inputs.md`）に行われ、PDF 生成時には再検証されません

## 参考

- `app/Models/FiscalYear.php`
- `app/Services/BlueReturnPdf/BlueReturnStatementPdfGenerator.php`
- `app/Services/BlueReturnPdf/TemplateResolver.php`
- `docs/blue-return-statement-pdf-design.md`
