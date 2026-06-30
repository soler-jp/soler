# セットアップ

`GeneralBusinessInitializer` を使うと、初期の事業体セットアップをまとめて実行できます。
この入口を使うと、`BusinessUnit` の作成、`FiscalYear` の作成、期首仕訳の登録まで一度に進みます。

## 前提

- ログイン済みの `User` があること
- 初期セットアップを実行する権限があること

## 使い方

### code例

```php
use App\Setup\Initializers\GeneralBusinessInitializer;

$initializer = app(GeneralBusinessInitializer::class);

$businessUnit = $initializer->initialize(auth()->user(), [
    'name' => '一般事業所',
    'type' => 'general',
    'year' => 2025,
    'is_taxable' => false,
    'is_tax_exclusive' => false,
    'opening_entries' => [
        [
            'account_name' => '現金',
            'sub_account_name' => 'レジ現金',
            'amount' => 100000,
        ],
    ],
    'revenue_sub_accounts' => [
        ['name' => '一般売上'],
    ],
]);
```

## この処理で作られるもの

- `BusinessUnit`
- 標準勘定科目
- `FiscalYear`
- 期首仕訳
- 必要に応じて追加の売上補助科目
