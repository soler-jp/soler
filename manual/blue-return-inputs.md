# 決算書入力

`FiscalYear` には、青色申告決算書のうち帳簿からは導出できない入力を保存するための API があります。

対象は次の 2 種類です。

- `family_employee_salaries`
- `rent_expenses`

## 使い方

`FiscalYear` 経由で保存します。

```php
use App\Models\BlueReturnInput;

$fiscalYear = auth()->user()->selectedBusinessUnit->currentFiscalYear;

$fiscalYear->saveBlueReturnInput(BlueReturnInput::KEY_FAMILY_EMPLOYEE_SALARIES, [
    'rows' => [
        [
            'name' => '国税 太郎',
            'age' => 42,
            'months' => 12,
            'salary' => 1_000_000,
            'bonus' => 200_000,
            'withheld_tax_amount' => 0,
        ],
    ],
]);
```

複数の入力をまとめて保存する場合は `saveBlueReturnInputs()` を使います。

```php
$fiscalYear->saveBlueReturnInputs([
    BlueReturnInput::KEY_FAMILY_EMPLOYEE_SALARIES => [
        'rows' => [
            [
                'name' => '国税 太郎',
                'age' => 42,
                'months' => 12,
                'salary' => 1_000_000,
                'bonus' => 200_000,
                'withheld_tax_amount' => 0,
            ],
        ],
    ],
    BlueReturnInput::KEY_RENT_EXPENSES => [
        'rows' => [
            [
                'address' => '東京都千代田区1-1-1',
                'name' => '株式会社サンプル',
                'rent_amount' => 120_000,
                'deductible_amount' => 90_000,
                'allocation_group_id' => 'group-1',
            ],
        ],
    ],
]);
```

## 保存される内容

保存先は `blue_return_inputs` テーブルです。

- `fiscal_year_id`: 対象の会計年度
- `key`: 内訳種別
- `value`: JSON 形式の入力データ

`value` は 1 種別 1 レコードで保存されます。更新時は同じ `fiscal_year_id + key` のレコードが上書きされます。

## 専従者給与の入力

`family_employee_salaries` は次の形で保存します。

```php
[
    'rows' => [
        [
            'name' => '氏名',
            'age' => 42,
            'months' => 12,
            'salary' => 1_000_000,
            'bonus' => 200_000,
            'withheld_tax_amount' => 0,
        ],
    ],
]
```

検証ルール:

- `salary + bonus` の合計が、損益計算書 ㊳ `family_employee_salaries` と一致すること
- `name` は必須
- `salary` は必須、0 以上の整数
- `bonus` と `withheld_tax_amount` は任意

## 地代家賃の入力

`rent_expenses` は次の形で保存します。

```php
[
    'rows' => [
        [
            'address' => '東京都千代田区1-1-1',
            'name' => '株式会社サンプル',
            'rent_amount' => 120_000,
            'deductible_amount' => 90_000,
            'allocation_group_id' => 'group-1',
        ],
    ],
]
```

検証ルール:

- `address` と `name` は必須
- `rent_amount` と `deductible_amount` は必須、0 以上の整数
- `allocation_group_id` は任意

## 取得する

単一の入力を取得する場合は `blueReturnInput()` を使います。

```php
$familyEmployeeInput = $fiscalYear->blueReturnInput(BlueReturnInput::KEY_FAMILY_EMPLOYEE_SALARIES);
$rentInput = $fiscalYear->blueReturnInput(BlueReturnInput::KEY_RENT_EXPENSES);
```

未保存の場合は `null` です。

## 注意点

- `blue_return_inputs` には、帳簿由来の集計値は保存しません
- 青色申告特別控除額は保存しません
- ㊸ などの帳簿集計は `calculateBlueReturnStatement()` で毎回再計算します

## 参考

- `app/Models/FiscalYear.php`
- `app/Models/BlueReturnInput.php`
- `app/Services/BlueReturnInputRegistrar.php`
- `docs/blue-return-statement-design.md`
