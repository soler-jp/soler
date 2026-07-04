# Test Journal Review Tool

このドキュメントは、テストコードが登録・検証している仕訳を、人間がレビューしやすい形で表示するツールの使い方と仕様を整理したものです。

## 目的

- テストコード内の仕訳登録は連想配列で書かれており、簿記として正しいかのヒューマンチェックがしづらい
- テストコード自体は生の `TransactionRegistrar` API を使ったままにする（実際に使うコードと違うコードでテストしない）
- レビュー時だけ、仕訳部分を簿記の記法（借方科目 金額 / 貸方科目 金額）で読める形にする

## 方針

- ツールはテストソースを静的解析する読み取り専用のレビュー補助であり、テストの実行・DB・ファイルには一切関与しない
- テストソース全体を出力し、仕訳に関する文だけを置き換える（仕訳とアサーションの文脈を切らない）
- 解決できない式は推測せず、原文のまま表示する

## 使い方

```
vendor/bin/sail php tests/Tools/render-journals.php tests/Feature/FiscalYearRolloverTest.php
vendor/bin/sail php tests/Tools/render-journals.php tests/Feature
```

ファイルまたはディレクトリを複数指定できる。ディレクトリを指定した場合は配下の `*Test.php` を対象とする。仕訳に関する文が1つもないファイルは出力されない。

## 出力例

```php
        $cash = $businessUnit->getSubAccountByName('現金', '現金');

        ▶ 期首残高   | 現金     100,000 /               |

        ▶ 2025-04-10 | 現金      20,000 / 借入金 20,000 | 借入

        ▶ 2025-06-10 | 消耗品費  10,000 / 現金   10,000 | 消耗品購入

        $closedYear->close($user);

        $this->assertSame(2026, $rolloverData['next_year']);
        ✓ 現金 140,000 / 借入金  20,000 | $rolloverData['opening_entries']
        ✓              / 元入金 120,000 | $rolloverData['capital_entry']
```

## 表示仕様

### 置き換え対象

| 目印 | 対象 | 条件 |
| --- | --- | --- |
| `▶` | 仕訳登録 | `register(...)` の呼び出し（引数3つ以上）、`registerOpeningEntry(...)` の呼び出し |
| `✓` | 仕訳明細のアサーション | `assertSame` / `assertEquals` の期待値が `account_name` と `amount` を持つ連想配列、またはそのリスト |

上記以外の行（他のアサーション、セットアップ、コメント、空行）はすべて原文のまま出力する。

### 記法

- `▶` 行は `日付 | 借方科目 金額 / 貸方科目 金額 | 摘要` の並び。日付は固定幅なので借方の開始位置が安定し、可変長の摘要と注記は右端に寄る
- `✓` 行は日付欄なしの `借方科目 金額 / 貸方科目 金額 | 比較対象の式`
- `/` の左が借方、右が貸方（簿記の教科書記法）。借方がない行は `/` から始まる
- 期首残高は日付欄に `期首残高` と表示し、摘要欄は空
- 桁揃えはメソッド単位で、`▶` と `✓` は別グループとして揃える
- 戻り値の代入は `▶ $transaction = 2025-06-15 | ...` のように日付の前に残す
- 複合仕訳は複数行になり、2行目以降は科目と金額のみ表示する

### 値の表示

- `$cash->id` のような補助科目IDは、同メソッド内（および `setUp`）の代入を遡って科目名に解決する。対応パターンは `getSubAccountByName('科目', '補助科目')` と、テスト内ヘルパーの `subAccountByName($unit, '科目')`
- 科目名と補助科目名が異なる場合は `科目名/補助科目名` と表示する
- 解決できない式（factory 由来の変数など）は `$subAccount->id` のように原文のまま表示する
- `gross_amount` は `税込11,000` と表示する
- `date` / `description` / 金額・科目以外の入力（`counterparty_name`、`tax_type`、`business_ratio` など）は摘要の後ろや科目名の横に `# キー: 値` の形で原文どおり注記する。空白を含む文字列（登録番号など）もそのまま見える

## 制約

- 静的解析のため、変数の解決は `getSubAccountByName` / `subAccountByName` の代入パターンのみ対応。ループや factory 経由の複雑な構築は原文表示になる
- `registerTransaction` など他の登録経路は対象外
- 実装は `tests/Tools/JournalSourceRenderer.php`（パーサは PHPUnit の推移的依存である `nikic/php-parser` を利用し、依存追加はしていない）
