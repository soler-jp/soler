# 固定資産

## 新車の登録をするには

普通車の新車は `DepreciationService::registerNewStandardCar()` で登録します。
このメソッドは、通常は登録対象年度の取得日のみ受け付けます。
登録対象年度より前に取得した資産を入れる場合は、`allowRegistration` を `true` にします。

### 前提

- 事業体が作成済みであること
- 登録対象年度の `FiscalYear` があること
- 通常登録では支払元の補助科目があること
- `fixedAssetData` に取得日・税抜金額・消費税額を渡すこと

ここでいう `FiscalYear` は、その固定資産をこのアプリに登録する年度です。
減価償却の記録はこの年度を上限に、取得日から順に作られます。
取得日が登録対象年度より前なら、通常登録では `InvalidArgumentException` になります。

### code例

```php
use App\Services\DepreciationService;

$fixedAsset = app(DepreciationService::class)->registerNewStandardCar(
    $fiscalYear,
    $paymentSubAccount,
    [
        'name' => 'PRIUS',
        'acquisition_date' => '2025-10-03',
        'taxable_amount' => 3_000_000,
        'tax_amount' => 300_000,
    ],
    [
        'date' => '2025-10-03',
        'description' => 'PRIUSを購入',
    ],
);
```

### 過年度を登録する場合

登録対象年度より前に取得した普通車は、`registerNewStandardCar()` に `allowRegistration: true` を付けて強制登録します。

```php
$pastFixedAsset = app(DepreciationService::class)->registerNewStandardCar(
    $currentFiscalYear,
    $paymentSubAccount,
    [
        'name' => 'PRIUS',
        'acquisition_date' => '2025-10-03',
        'taxable_amount' => 3_000_000,
        'tax_amount' => 300_000,
    ],
    [
        'date' => '2025-10-03',
        'description' => 'PRIUSを購入',
    ],
    true,
);
```

この場合、取得仕訳は作られません。
ただし、存在する `FiscalYear` に対しては、取得年度から登録対象年度まで減価償却の記録が作成されます。
途中の年度が未作成なら、その年度はスキップされます。

### 補足

- `asset_category` は `新車-普通車` になります
- `useful_life` は `72` になります
- 課税事業者でも免税事業者でも、呼び出し方は同じです
- 減価償却の記録も同時に作成されます
- `FixedAsset` は `taxable_amount` と `tax_amount` を持ち、`acquisition_cost` はそこから算出されます
- 戻り値は作成された `FixedAsset` です
