# 固定資産

## 固定資産一覧を取得するには

固定資産の一覧は `BusinessUnit` から取得します。
`allFixedAssets()` で全件、`depreciatingFixedAssets(FiscalYear $fiscalYear)` で指定年度にまだ償却が終わっていない固定資産だけを取得できます。

### code例

```php
$businessUnit = auth()->user()->selectedBusinessUnit;
$fiscalYear = $businessUnit->currentFiscalYear;

$allFixedAssets = $businessUnit->allFixedAssets();
$depreciatingFixedAssets = $businessUnit->depreciatingFixedAssets($fiscalYear);
```

`allFixedAssets()` は `depreciationEntries` もまとめて読み込むため、一覧画面で残額や償却状況を表示しやすくなります。

### 補足

#### `BusinessUnit`

- `allFixedAssets()` は除却済み資産も含みます
- `depreciatingFixedAssets(FiscalYear $fiscalYear)` は、指定した年度に償却が発生する固定資産だけを返します

#### `FixedAsset`

- `remainingDepreciableAmount()` で残りの償却可能額を取得できます

#### `DepreciationService`

- `isFullyDepreciated(FixedAsset $asset, FiscalYear $fiscalYear)` は、その年度に償却が発生しない場合に `true` を返します

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

### 軽自動車の例

軽自動車の新車は `DepreciationService::registerNewLightCar()` で登録します。
耐用年数は 4年 です。

```php
$fixedAsset = app(DepreciationService::class)->registerNewLightCar(
    $fiscalYear,
    $paymentSubAccount,
    [
        'name' => 'N-BOX',
        'acquisition_date' => '2025-10-03',
        'taxable_amount' => 2_000_000,
        'tax_amount' => 200_000,
    ],
    [
        'date' => '2025-10-03',
        'description' => 'N-BOXを購入',
    ],
);
```

### 税込40万円以上の新品パソコンの例

新品パソコンを固定資産として登録する場合は、`DepreciationService::registerFixedAsset()` を使います。
この例では、税込取得価額が 44万円、耐用年数が 4年 です。

```php
$fixedAsset = app(DepreciationService::class)->registerFixedAsset(
    $fiscalYear,
    $assetSubAccount,
    $paymentSubAccount,
    [
        'name' => '業務用ノートPC',
        'asset_category' => 'machinery',
        'acquisition_date' => '2025-10-03',
        'taxable_amount' => 400_000,
        'tax_amount' => 40_000,
        'depreciation_method' => 'straight_line',
        'useful_life' => 48,
    ],
    [
        'date' => '2025-10-03',
        'description' => '業務用ノートPCを購入',
    ],
);
```

### 償却予定スケジュールを取得するには

登録済みの `FixedAsset` から、これからの年度別の償却予定を取得できます。
`DepreciationService::calculateDepreciationScheduleUntilFullyDepreciated()` に `FixedAsset` を渡すと、年度をキーにした配列が返ります。

```php
$assetSubAccount = $businessUnit->subAccounts()
    ->whereHas('account', fn ($query) => $query->where('name', '機械装置'))
    ->firstOrFail();

$paymentSubAccount = $businessUnit->subAccounts()
    ->whereHas('account', fn ($query) => $query->where('name', 'その他の預金'))
    ->firstOrFail();

$fixedAsset = app(DepreciationService::class)->registerFixedAsset(
    $fiscalYear,
    $assetSubAccount,
    $paymentSubAccount,
    [
        'name' => 'サーバー機器',
        'asset_category' => 'machinery',
        'acquisition_date' => '2023-10-01',
        'taxable_amount' => 480_000,
        'tax_amount' => 0,
        'depreciation_method' => 'straight_line',
        'useful_life' => 48,
    ],
    [
        'date' => '2023-10-01',
        'description' => 'サーバー機器を購入',
    ],
    true,
);

$schedule = app(DepreciationService::class)
    ->calculateDepreciationScheduleUntilFullyDepreciated($fixedAsset);
```

この例の返り値は次のとおりです。

```php
[
    2023 => [
        'months' => 3,
        'ordinary_amount' => 30_000,
        'special_amount' => 0,
        'total_amount' => 30_000,
        'ending_balance' => 450_000,
    ],
    2024 => [
        'months' => 12,
        'ordinary_amount' => 120_000,
        'special_amount' => 0,
        'total_amount' => 120_000,
        'ending_balance' => 330_000,
    ],
    2025 => [
        'months' => 12,
        'ordinary_amount' => 120_000,
        'special_amount' => 0,
        'total_amount' => 120_000,
        'ending_balance' => 210_000,
    ],
    2026 => [
        'months' => 12,
        'ordinary_amount' => 120_000,
        'special_amount' => 0,
        'total_amount' => 120_000,
        'ending_balance' => 90_000,
    ],
    2027 => [
        'months' => 9,
        'ordinary_amount' => 90_000,
        'special_amount' => 0,
        'total_amount' => 90_000,
        'ending_balance' => 0,
    ],
]
```

この結果は [`tests/Feature/DepreciationServiceTest.php`](../tests/Feature/DepreciationServiceTest.php) の `減価償却予定は年度ごとの配列として取得できる` で同じ配列を検証しています。

## 減価償却明細を扱うには

`DepreciationEntry` は `FiscalYear` と `FixedAsset` に紐づく減価償却明細です。
`registerFixedAsset()` や `registerNewStandardCar()` などの登録処理の中で、取得年度から登録対象年度までの分がまとめて作成されます。

### 作成方法

`DepreciationEntry` は、固定資産の登録時に自動生成されます。
存在する `FiscalYear` の分だけ保存され、未作成の年度はスキップされます。

```php
DepreciationEntry::updateOrCreate(
    [
        'fiscal_year_id' => $fiscalYear->id,
        'fixed_asset_id' => $asset->id,
    ],
    [
        'months' => 12,
        'ordinary_amount' => 120_000,
        'special_amount' => 0,
        'total_amount' => 120_000,
        'business_usage_ratio' => 1.00,
        'deductible_amount' => 120_000,
        'transaction_id' => null,
    ],
);
```

`business_usage_ratio` は固定資産の使用割合です。
`deductible_amount` は `total_amount × business_usage_ratio` から計算されます。
`FiscalYear` が未作成の年度はスキップされます。

### 修正の考え方

修正するときは、`$fiscalYear` から対象の `DepreciationEntry` を取得して `update()` します。
このアプリでは、取得時の金額を正とし、途中で残高が変わった場合もその時点の `FixedAsset` の値を基準にします。

```php
$entry = $fiscalYear->depreciationEntries()
    ->where('fixed_asset_id', $fixedAsset->id)
    ->firstOrFail();

$entry->update([
    'months' => 12,
    'ordinary_amount' => 120_000,
    'special_amount' => 0,
    'total_amount' => 120_000,
    'business_usage_ratio' => 1.00,
    'deductible_amount' => 120_000,
]);
```

- 修正対象は `DepreciationEntry` です
- 取得価額を変えるなら `FixedAsset` 側を正にします
- 後続年度分の再計算はしません
- 明細の値は、その年度の記録として更新します

### Transaction に変換して保存するには

未記帳の `DepreciationEntry` は、`DepreciationService::registerTransactionFor()` で仕訳に変換できます。
変換後は `transaction_id` が入るので、`DepreciationEntry::isUnposted()` は `false` になります。

```php
$entry = $fiscalYear->depreciationEntries()
    ->where('fixed_asset_id', $fixedAsset->id)
    ->firstOrFail();

app(DepreciationService::class)->registerTransactionFor($entry);
```

この処理では、年度末日付の調整仕訳が作られます。
借方は `減価償却費`、貸方は対象固定資産の補助科目で、どちらも `deductible_amount` を使います。
すでに記帳済みの明細に対して呼ぶと例外になります。
