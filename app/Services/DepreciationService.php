<?php

namespace App\Services;

use App\Concerns\AuthorizesBusinessUnitAccess;
use App\Concerns\SkipActorGuard;
use App\Models\DepreciationEntry;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\SubAccount;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DepreciationService
{
    use AuthorizesBusinessUnitAccess;

    public function __construct(
        private readonly TransactionRegistrar $transactionRegistrar,
        private readonly TransactionRevisor $transactionRevisor,
    ) {}

    private const NEW_STANDARD_CAR_PRESET = [
        'asset_category' => FixedAsset::ASSET_CATEGORY_NEW_STANDARD_CAR,
        'useful_life' => 72,
        'depreciation_method' => FixedAsset::DEPRECIATION_METHOD_STRAIGHT_LINE,
    ];

    private const NEW_LIGHT_CAR_PRESET = [
        'asset_category' => FixedAsset::ASSET_CATEGORY_NEW_LIGHT_CAR,
        'useful_life' => 48,
        'depreciation_method' => FixedAsset::DEPRECIATION_METHOD_STRAIGHT_LINE,
    ];

    private const USED_STANDARD_CAR_PRESET = [
        'asset_category' => FixedAsset::ASSET_CATEGORY_USED_STANDARD_CAR,
        'depreciation_method' => FixedAsset::DEPRECIATION_METHOD_STRAIGHT_LINE,
    ];

    private const USED_LIGHT_CAR_PRESET = [
        'asset_category' => FixedAsset::ASSET_CATEGORY_USED_LIGHT_CAR,
        'depreciation_method' => FixedAsset::DEPRECIATION_METHOD_STRAIGHT_LINE,
    ];

    private const STANDARD_CAR_STATUTORY_USEFUL_LIFE_MONTHS = 72;

    private const LIGHT_CAR_STATUTORY_USEFUL_LIFE_MONTHS = 48;

    private const MINIMUM_USED_ASSET_USEFUL_LIFE_MONTHS = 24;

    #[SkipActorGuard('TODO: 固定資産登録エントリは actor を受け取っていない。登録系エントリに actor を追加する対応が別途必要。')]
    public function registerNewStandardCar(
        FiscalYear $fiscalYear,
        SubAccount $paymentSubAccount,
        array $fixedAssetData,
        array $transactionData,
        bool $allowRegistration = false
    ): FixedAsset {
        $assetSubAccount = $this->resolveVehicleAssetSubAccount($fiscalYear);

        return $this->registerFixedAsset(
            $fiscalYear,
            $assetSubAccount,
            $paymentSubAccount,
            array_merge($fixedAssetData, self::NEW_STANDARD_CAR_PRESET),
            $transactionData,
            $allowRegistration,
        );
    }

    #[SkipActorGuard('TODO: 固定資産登録エントリは actor を受け取っていない。登録系エントリに actor を追加する対応が別途必要。')]
    public function registerNewLightCar(
        FiscalYear $fiscalYear,
        SubAccount $paymentSubAccount,
        array $fixedAssetData,
        array $transactionData,
        bool $allowRegistration = false
    ): FixedAsset {
        $assetSubAccount = $this->resolveVehicleAssetSubAccount($fiscalYear);

        return $this->registerFixedAsset(
            $fiscalYear,
            $assetSubAccount,
            $paymentSubAccount,
            array_merge($fixedAssetData, self::NEW_LIGHT_CAR_PRESET),
            $transactionData,
            $allowRegistration,
        );
    }

    #[SkipActorGuard('TODO: 固定資産登録エントリは actor を受け取っていない。登録系エントリに actor を追加する対応が別途必要。')]
    public function registerUsedStandardCar(
        FiscalYear $fiscalYear,
        SubAccount $paymentSubAccount,
        array $fixedAssetData,
        array $transactionData,
        bool $allowRegistration = false
    ): FixedAsset {
        $assetSubAccount = $this->resolveVehicleAssetSubAccount($fiscalYear);

        return $this->registerFixedAsset(
            $fiscalYear,
            $assetSubAccount,
            $paymentSubAccount,
            array_merge(
                $fixedAssetData,
                self::USED_STANDARD_CAR_PRESET,
                [
                    'useful_life' => $this->calculateUsedVehicleUsefulLife(
                        $fixedAssetData,
                        self::STANDARD_CAR_STATUTORY_USEFUL_LIFE_MONTHS,
                    ),
                ],
            ),
            $transactionData,
            $allowRegistration,
        );
    }

    #[SkipActorGuard('TODO: 固定資産登録エントリは actor を受け取っていない。登録系エントリに actor を追加する対応が別途必要。')]
    public function registerUsedLightCar(
        FiscalYear $fiscalYear,
        SubAccount $paymentSubAccount,
        array $fixedAssetData,
        array $transactionData,
        bool $allowRegistration = false
    ): FixedAsset {
        $assetSubAccount = $this->resolveVehicleAssetSubAccount($fiscalYear);

        return $this->registerFixedAsset(
            $fiscalYear,
            $assetSubAccount,
            $paymentSubAccount,
            array_merge(
                $fixedAssetData,
                self::USED_LIGHT_CAR_PRESET,
                [
                    'useful_life' => $this->calculateUsedVehicleUsefulLife(
                        $fixedAssetData,
                        self::LIGHT_CAR_STATUTORY_USEFUL_LIFE_MONTHS,
                    ),
                ],
            ),
            $transactionData,
            $allowRegistration,
        );
    }

    #[SkipActorGuard('TODO: 固定資産登録エントリは actor を受け取っていない。登録系エントリに actor を追加する対応が別途必要。')]
    public function registerFixedAsset(
        FiscalYear $fiscalYear,
        SubAccount $assetSubAccount,
        SubAccount $paymentSubAccount,
        array $fixedAssetData,
        array $transactionData,
        bool $allowRegistration = false
    ): FixedAsset {
        $acquisitionDate = $fixedAssetData['acquisition_date'];

        if (
            ! $allowRegistration
            && Carbon::parse($acquisitionDate)->lt(Carbon::parse($fiscalYear->start_date))
        ) {
            throw new \InvalidArgumentException('過去に取得した固定資産は allowRegistration を true にしないと登録できません。');
        }

        return DB::transaction(function () use (
            $fiscalYear,
            $assetSubAccount,
            $paymentSubAccount,
            $fixedAssetData,
            $transactionData
        ) {
            $acquisitionDate = $fixedAssetData['acquisition_date'];
            $businessUnit = $fiscalYear->businessUnit;

            // 2. 金額計算
            $taxableAmount = $fixedAssetData['taxable_amount'];
            $taxAmount = $fixedAssetData['tax_amount'] ?? 0;

            // 3. 固定資産登録
            $asset = FixedAsset::create([
                'business_unit_id' => $businessUnit->id,
                'account_id' => $assetSubAccount->account_id,
                'name' => $fixedAssetData['name'],
                'asset_category' => $fixedAssetData['asset_category'],
                'acquisition_date' => $acquisitionDate,
                'first_registration_date' => $fixedAssetData['first_registration_date'] ?? null,
                'taxable_amount' => $taxableAmount,
                'tax_amount' => $taxAmount,
                'useful_life' => $fixedAssetData['useful_life'],
                'depreciation_method' => $fixedAssetData['depreciation_method'],
            ]);

            // 4. 取得仕訳の登録（取得日が今年度内の場合のみ）
            if (
                Carbon::parse($acquisitionDate)->betweenIncluded(
                    $fiscalYear->start_date,
                    $fiscalYear->end_date
                )
            ) {
                $transaction = Transaction::create([
                    'fiscal_year_id' => $fiscalYear->id,
                    'date' => $transactionData['date'],
                    'description' => $transactionData['description'],
                ]);

                $transaction->journalEntries()->createMany([
                    [
                        'sub_account_id' => $assetSubAccount->id,
                        'type' => 'debit',
                        'net_amount' => $asset->acquisition_cost,
                    ],
                    [
                        'sub_account_id' => $paymentSubAccount->id,
                        'type' => 'credit',
                        'net_amount' => $asset->acquisition_cost,
                    ],
                ]);
            }

            $this->createDepreciationEntriesUpTo($fiscalYear, $asset, $acquisitionDate, $fixedAssetData);

            return $asset;
        });
    }

    /**
     * 取得年度から $upToFiscalYear までの全年度分の DepreciationEntry を作成する。
     * 過去の年度を遡って登録する場合に、途中年度が DB に存在しないことがあるため、
     * 存在する年度分のみ作成する。
     */
    private function createDepreciationEntriesUpTo(
        FiscalYear $upToFiscalYear,
        FixedAsset $asset,
        string $acquisitionDate,
        array $fixedAssetData
    ): void {
        $businessUsageRatio = (float) ($fixedAssetData['business_usage_ratio'] ?? 1.00);

        $fullSchedule = $this->calculateDepreciationScheduleUntilFullyDepreciated($asset);
        $acquisitionYear = (int) Carbon::parse($acquisitionDate)->format('Y');

        foreach ($fullSchedule as $year => $values) {
            if ($year > $upToFiscalYear->year) {
                break;
            }

            if ($year < $acquisitionYear) {
                continue;
            }

            $fiscalYear = $upToFiscalYear->businessUnit->fiscalYears()
                ->where('year', $year)
                ->first();

            if ($fiscalYear === null) {
                continue;
            }

            DepreciationEntry::updateOrCreate(
                [
                    'fiscal_year_id' => $fiscalYear->id,
                    'fixed_asset_id' => $asset->id,
                ],
                $values + [
                    'business_usage_ratio' => $businessUsageRatio,
                    'deductible_amount' => (int) floor($values['total_amount'] * $businessUsageRatio),
                    'transaction_id' => null,
                ],
            );
        }
    }

    /**
     * 取得年度から耐用年数を使い切るまでの減価償却予定を年別に返す。
     *
     * @return array<int, array{months: int, ordinary_amount: int, special_amount: int, total_amount: int, ending_balance: int}>
     */
    #[SkipActorGuard('read-only な減価償却スケジュール計算。呼び出し側で FixedAsset を actor でガードする前提。')]
    public function calculateDepreciationScheduleUntilFullyDepreciated(
        FixedAsset $asset
    ): array {
        $acquisitionDate = $asset->acquisition_date?->toDateString();

        if ($acquisitionDate === null) {
            return [];
        }

        $acquisitionYear = (int) Carbon::parse($acquisitionDate)->format('Y');
        $remainingAmount = (int) $asset->acquisition_cost;
        $remainingMonths = max(0, (int) $asset->useful_life);
        $depreciationRate = $this->calculateDepreciationRate($asset);
        $annualAmount = $depreciationRate === null
            ? 0
            : (int) round($remainingAmount * $depreciationRate, 0, PHP_ROUND_HALF_UP);

        $schedule = [];

        for ($year = $acquisitionYear; $remainingMonths > 0 && $remainingAmount > 0; $year++) {
            $months = $this->calculateDepreciationMonthsForCalendarYear(
                $year,
                $acquisitionDate,
                $remainingMonths,
            );

            if ($months === 0) {
                continue;
            }

            $ordinaryAmount = (int) round($annualAmount * ($months / 12), 0, PHP_ROUND_HALF_UP);

            if ($ordinaryAmount > $remainingAmount) {
                $ordinaryAmount = $remainingAmount;
            }

            $totalAmount = $ordinaryAmount;
            $remainingAmount -= $ordinaryAmount;
            $remainingMonths -= $months;

            $schedule[$year] = [
                'months' => $months,
                'ordinary_amount' => $ordinaryAmount,
                'special_amount' => 0,
                'total_amount' => $totalAmount,
                'ending_balance' => max(0, $remainingAmount),
            ];
        }

        return $schedule;
    }

    #[SkipActorGuard('read-only な判定。')]
    public function isFullyDepreciated(FixedAsset $asset, FiscalYear $fiscalYear): bool
    {
        return ! array_key_exists($fiscalYear->year, $this->calculateDepreciationScheduleUntilFullyDepreciated($asset));
    }

    #[SkipActorGuard('read-only な判定。')]
    public function isStillDepreciating(FixedAsset $asset, FiscalYear $fiscalYear): bool
    {
        return ! $this->isFullyDepreciated($asset, $fiscalYear);
    }

    #[SkipActorGuard('read-only な残高計算。')]
    public function calculateEndingUndepreciatedBalance(FixedAsset $asset, FiscalYear $fiscalYear): int
    {
        $schedule = $this->calculateDepreciationScheduleUntilFullyDepreciated($asset);

        return (int) ($schedule[$fiscalYear->year]['ending_balance'] ?? 0);
    }

    private function calculateDepreciationRate(FixedAsset $asset): ?float
    {
        $usefulLife = (int) $asset->useful_life;

        if ($usefulLife <= 0) {
            return null;
        }

        return ceil((12 / $usefulLife) * 1000) / 1000;
    }

    private function calculateUsedVehicleUsefulLife(array $fixedAssetData, int $statutoryUsefulLifeMonths): int
    {
        $firstRegistrationDate = $fixedAssetData['first_registration_date'] ?? null;

        if ($firstRegistrationDate === null) {
            throw new \InvalidArgumentException('中古車の登録には first_registration_date が必要です。');
        }

        $acquisitionDate = Carbon::parse($fixedAssetData['acquisition_date']);
        $firstRegistrationDate = Carbon::parse($firstRegistrationDate);

        if ($firstRegistrationDate->gt($acquisitionDate)) {
            throw new \InvalidArgumentException('中古車の first_registration_date は acquisition_date 以前の日付を指定してください。');
        }

        $elapsedMonths = $this->countElapsedMonths($firstRegistrationDate, $acquisitionDate);

        if ($elapsedMonths >= $statutoryUsefulLifeMonths) {
            return max(
                self::MINIMUM_USED_ASSET_USEFUL_LIFE_MONTHS,
                (int) floor(($statutoryUsefulLifeMonths * 0.2) / 12) * 12,
            );
        }

        $usefulLifeYears = (int) floor(
            ($statutoryUsefulLifeMonths - $elapsedMonths + ($elapsedMonths * 0.2)) / 12,
        );

        return max(
            self::MINIMUM_USED_ASSET_USEFUL_LIFE_MONTHS,
            $usefulLifeYears * 12,
        );
    }

    private function countElapsedMonths(Carbon $start, Carbon $end): int
    {
        $months = ($end->year - $start->year) * 12 + ($end->month - $start->month);

        if ($end->day < $start->day) {
            $months--;
        }

        return max(0, $months);
    }

    private function resolveVehicleAssetSubAccount(FiscalYear $fiscalYear): SubAccount
    {
        $subAccount = $fiscalYear->businessUnit->getSubAccountByName('車両運搬具', '車両運搬具');

        if ($subAccount === null) {
            throw new \RuntimeException('車両運搬具の補助科目が見つかりません。');
        }

        return $subAccount;
    }

    private function calculateDepreciationMonthsForFiscalYear(FiscalYear $fiscalYear, string $acquisitionDate): int
    {
        $fiscalStart = Carbon::parse($fiscalYear->start_date)->startOfMonth();
        $fiscalEnd = Carbon::parse($fiscalYear->end_date)->endOfMonth();
        $acquisitionMonth = Carbon::parse($acquisitionDate)->startOfMonth();

        $depreciationStart = $acquisitionMonth->greaterThan($fiscalStart)
            ? $acquisitionMonth
            : $fiscalStart;

        if ($depreciationStart->greaterThan($fiscalEnd)) {
            return 0;
        }

        return $this->countMonthsIncluded($depreciationStart, $fiscalEnd);
    }

    private function calculateDepreciationMonthsForCalendarYear(
        int $year,
        string $acquisitionDate,
        int $remainingMonths
    ): int {
        $fiscalStart = Carbon::create($year, 1, 1)->startOfMonth();
        $fiscalEnd = Carbon::create($year, 12, 31)->endOfMonth();
        $acquisitionMonth = Carbon::parse($acquisitionDate)->startOfMonth();

        $depreciationStart = $acquisitionMonth->greaterThan($fiscalStart)
            ? $acquisitionMonth
            : $fiscalStart;

        if ($depreciationStart->greaterThan($fiscalEnd)) {
            return 0;
        }

        return min($this->countMonthsIncluded($depreciationStart, $fiscalEnd), $remainingMonths);
    }

    /**
     * 開始月から終了月までの月数を両端を含めて数える。
     * Carbon の diffInMonths() は float を返し、端数の暗黙の int 変換で
     * 精度が落ちるため、年・月の整数演算のみで算出する。
     */
    private function countMonthsIncluded(Carbon $start, Carbon $end): int
    {
        return ($end->year - $start->year) * 12 + ($end->month - $start->month) + 1;
    }

    #[SkipActorGuard('システムから呼ばれるバッチ処理。TODO: 呼び出し元で FiscalYear を actor でガードする経路を確認する。')]
    public function prepareEntriesFor(FiscalYear $fiscalYear): void
    {
        $businessUnit = $fiscalYear->businessUnit;

        $fixedAssets = $businessUnit->fixedAssets()
            ->with(['depreciationEntries' => function ($query): void {
                $query->orderBy('fiscal_year_id');
            }])
            ->orderBy('acquisition_date')
            ->orderBy('id')
            ->get();

        foreach ($fixedAssets as $fixedAsset) {
            $schedule = $this->calculateDepreciationScheduleUntilFullyDepreciated($fixedAsset);
            $values = $schedule[$fiscalYear->year] ?? null;

            if ($values === null) {
                continue;
            }

            $businessUsageRatio = $this->resolveBusinessUsageRatio($fixedAsset);

            $fixedAsset->depreciationEntries()->firstOrCreate(
                [
                    'fiscal_year_id' => $fiscalYear->id,
                ],
                $values + [
                    'business_usage_ratio' => $businessUsageRatio,
                    'deductible_amount' => (int) floor($values['total_amount'] * $businessUsageRatio),
                    'transaction_id' => null,
                ],
            );
        }
    }

    /**
     * 過年度取得の固定資産に対して、$fiscalYear の期首仕訳へ
     * 借方: 資産勘定 (期首簿価) を追加する。貸方 (元入金) は total を再計算。
     *
     * 既存の active な期首仕訳 (Bank/Cash/OpeningBalance 由来) があれば行追加で revise し、
     * 無ければ新規作成する。同一資産の二重登録は FixedAsset.initial_opening_transaction_id で拒否。
     */
    public function registerInitialOpeningTransfer(
        FixedAsset $asset,
        FiscalYear $fiscalYear,
        ?User $actor,
    ): Transaction {
        $this->authorizeBusinessUnitAccess($asset, $actor, 'この固定資産の期首振替を作成する権限がありません。');
        $this->authorizeBusinessUnitAccess($fiscalYear, $actor, 'この会計年度に期首振替を作成する権限がありません。');
        assert($actor instanceof User);

        if ($asset->business_unit_id !== $fiscalYear->business_unit_id) {
            throw new \InvalidArgumentException('資産と会計年度の事業体が一致しません。');
        }

        $acquisitionDate = $asset->acquisition_date;

        if ($acquisitionDate === null) {
            throw new \InvalidArgumentException('取得日が未設定の固定資産には期首振替を作成できません。');
        }

        if (! $acquisitionDate->lt(Carbon::parse($fiscalYear->start_date))) {
            throw new \InvalidArgumentException('過年度取得の固定資産にのみ期首振替を作成できます。');
        }

        $openingBalance = $this->calculateOpeningBalanceFor($asset, $fiscalYear);

        if ($openingBalance <= 0) {
            throw new \InvalidArgumentException('期首簿価が 0 以下のため期首振替は作成できません。');
        }

        $assetSubAccount = $this->resolveFixedAssetSubAccount($fiscalYear, $asset);

        return DB::transaction(function () use ($asset, $fiscalYear, $openingBalance, $assetSubAccount, $actor) {
            // Race safety: fixed_assets 行をロックしてから FK を再確認する。
            $lockedAsset = FixedAsset::whereKey($asset->id)->lockForUpdate()->firstOrFail();

            if ($lockedAsset->initial_opening_transaction_id !== null) {
                throw new \InvalidArgumentException('この固定資産の期首振替は既に登録されています。');
            }

            $transaction = $this->syncOpeningEntryForFixedAsset(
                $fiscalYear,
                $assetSubAccount,
                $openingBalance,
                $lockedAsset->name,
                $actor,
            );

            $lockedAsset->forceFill([
                'initial_opening_transaction_id' => $transaction->id,
            ])->save();

            return $transaction;
        });
    }

    /**
     * 期首仕訳へ資産の debit 行を追加し、元入金を差引で再計算する。
     * 既存の active な opening entry があれば TransactionRevisor で行追加、
     * 無ければ TransactionRegistrar で新規作成する。
     *
     * 「元入金」以外の行 (資産・負債・その他) は全て保持し、
     * 元入金だけは (総debit - 総credit) で再計算する:
     *  - 正なら credit 側
     *  - 負なら debit 側 (負債超過ケース)
     *
     * 貸方が「元入金」1本と決まっているケース以外 (rollover 後の資産+負債+元入金など) にも対応する。
     * 元入金以外の credit (例: 借入金) の科目残高を勝手に増やさない。
     *
     * TODO: BankAccountRegistrationService::syncOpeningEntries および
     *       CashOnHandRegistrationService::syncOpeningEntries と同型のコピペ実装。
     *       将来 OpeningBalanceRegistrationService に additive な addDebitLine プリミティブを
     *       追加できたら、Bank/Cash と一緒にそちらへ移行する。
     */
    private function syncOpeningEntryForFixedAsset(
        FiscalYear $fiscalYear,
        SubAccount $assetSubAccount,
        int $amount,
        string $assetName,
        User $actor,
    ): Transaction {
        $capitalSubAccount = $this->resolveOwnerCapitalSubAccount($fiscalYear);

        $existingOpeningEntry = $fiscalYear->transactions()
            ->where('is_opening_entry', true)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if ($existingOpeningEntry === null) {
            return $this->transactionRegistrar->register(
                $fiscalYear,
                [
                    'date' => $fiscalYear->start_date->toDateString(),
                    'description' => '期首残高設定',
                    'is_opening_entry' => true,
                ],
                [
                    [
                        'sub_account_id' => $assetSubAccount->id,
                        'type' => JournalEntry::TYPE_DEBIT,
                        'net_amount' => $amount,
                    ],
                    [
                        'sub_account_id' => $capitalSubAccount->id,
                        'type' => JournalEntry::TYPE_CREDIT,
                        'net_amount' => $amount,
                    ],
                ],
                $actor,
            );
        }

        $existingOpeningEntry->loadMissing('journalEntries');

        // 元入金の行 (debit/credit いずれも) は捨てて後で差引計算し直す。
        // それ以外の行 (資産・負債など) は完全に保持する。
        $rows = $existingOpeningEntry->journalEntries
            ->reject(fn (JournalEntry $entry): bool => $entry->sub_account_id === $capitalSubAccount->id)
            ->map(fn (JournalEntry $entry): array => [
                'sub_account_id' => $entry->sub_account_id,
                'type' => $entry->type,
                'net_amount' => (int) $entry->net_amount,
            ])
            ->values();

        // 資産の debit 行を追加。
        $rows->push([
            'sub_account_id' => $assetSubAccount->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => $amount,
        ]);

        // 元入金を除外した rows で差引計算する。
        $totalDebit = (int) $rows->where('type', JournalEntry::TYPE_DEBIT)->sum('net_amount');
        $totalCredit = (int) $rows->where('type', JournalEntry::TYPE_CREDIT)->sum('net_amount');
        $capital = $totalDebit - $totalCredit;

        if ($capital > 0) {
            $rows->push([
                'sub_account_id' => $capitalSubAccount->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => $capital,
            ]);
        } elseif ($capital < 0) {
            $rows->push([
                'sub_account_id' => $capitalSubAccount->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => -$capital,
            ]);
        }

        return $this->transactionRevisor->revise(
            $existingOpeningEntry,
            $actor,
            [
                'transaction' => [
                    'revision_reason' => sprintf('固定資産「%s」の期首残高を追加', $assetName),
                ],
                'journal_entries' => $rows->all(),
            ],
        );
    }

    #[SkipActorGuard('read-only な期首簿価計算。呼び出し側で FixedAsset を actor でガードする前提。')]
    public function calculateOpeningBalanceFor(FixedAsset $asset, FiscalYear $fiscalYear): int
    {
        $schedule = $this->calculateDepreciationScheduleUntilFullyDepreciated($asset);
        $previousYear = $fiscalYear->year - 1;

        return (int) ($schedule[$previousYear]['ending_balance'] ?? 0);
    }

    public function registerTransactionFor(DepreciationEntry $entry, User $actor): void
    {
        $this->authorizeBusinessUnitAccess($entry, $actor, 'この減価償却明細を記帳する権限がありません。');

        $entry->loadMissing('fiscalYear.businessUnit', 'fixedAsset.account');

        if (! $entry->isUnposted()) {
            throw new \InvalidArgumentException('この減価償却明細は既に記帳済みです。');
        }

        $expenseSubAccount = $this->resolveDepreciationExpenseSubAccount($entry->fiscalYear);
        $assetSubAccount = $this->resolveFixedAssetSubAccount($entry->fiscalYear, $entry->fixedAsset);

        $transaction = $this->transactionRegistrar->register(
            $entry->fiscalYear,
            [
                'date' => $entry->fiscalYear->end_date->toDateString(),
                'description' => sprintf('%d年 減価償却: %s', $entry->fiscalYear->year, $entry->fixedAsset->name),
                'is_adjusting_entry' => true,
                'adjusting_entry_type' => Transaction::ADJUSTING_ENTRY_TYPE_DEPRECIATION,
            ],
            [
                [
                    'sub_account_id' => $expenseSubAccount->id,
                    'type' => JournalEntry::TYPE_DEBIT,
                    'net_amount' => $entry->deductible_amount,
                    'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                    'tax_amount' => 0,
                ],
                [
                    'sub_account_id' => $assetSubAccount->id,
                    'type' => JournalEntry::TYPE_CREDIT,
                    'net_amount' => $entry->deductible_amount,
                    'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                    'tax_amount' => 0,
                ],
            ],
            $actor,
        );

        $entry->forceFill([
            'transaction_id' => $transaction->id,
        ])->save();
    }

    private function resolveBusinessUsageRatio(FixedAsset $asset): float
    {
        $businessUsageRatio = $asset->depreciationEntries()
            ->orderBy('fiscal_year_id')
            ->value('business_usage_ratio');

        if ($businessUsageRatio === null) {
            return 1.00;
        }

        return (float) $businessUsageRatio;
    }

    private function resolveDepreciationExpenseSubAccount(FiscalYear $fiscalYear): SubAccount
    {
        $subAccount = $fiscalYear->businessUnit->getSubAccountByName('減価償却費', '減価償却費');

        if ($subAccount === null) {
            throw new \RuntimeException('減価償却費の補助科目が見つかりません。');
        }

        return $subAccount;
    }

    private function resolveOwnerCapitalSubAccount(FiscalYear $fiscalYear): SubAccount
    {
        $subAccount = $fiscalYear->businessUnit->getSubAccountByName('元入金', '元入金');

        if ($subAccount === null) {
            throw new \RuntimeException('元入金の補助科目が見つかりません。');
        }

        return $subAccount;
    }

    private function resolveFixedAssetSubAccount(FiscalYear $fiscalYear, FixedAsset $asset): SubAccount
    {
        $accountName = $asset->account->name;
        $subAccount = $fiscalYear->businessUnit->getSubAccountByName($accountName, $accountName);

        if ($subAccount === null) {
            throw new \RuntimeException("{$accountName} の補助科目が見つかりません。");
        }

        return $subAccount;
    }
}
