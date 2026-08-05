<?php

namespace App\Models;

use App\Concerns\AuthorizesBusinessUnitAccess;
use App\Contracts\ResolvesBusinessUnit;
use App\Services\DepreciationService;
use App\Services\TransactionRegistrar;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class BusinessUnit extends Model implements ResolvesBusinessUnit
{
    use AuthorizesBusinessUnitAccess;
    use HasFactory;

    public const HOUSEHOLD_ALLOCATION_SUB_ACCOUNT_NAME = '家事按分';

    public const UNCLASSIFIED_EXPENSE_ACCOUNT_NAME = '未分類費用';

    public const UNCLASSIFIED_EXPENSE_SUB_ACCOUNT_NAME = '未分類';

    public const CREDIT_SOURCE_CATEGORY_CASH = 'cash';

    public const CREDIT_SOURCE_CATEGORY_BANK = 'bank';

    public const CREDIT_SOURCE_CATEGORY_CARD = 'card';

    public const CREDIT_SOURCE_CATEGORY_PRIVATE = 'private';

    public const PAYMENT_ACCOUNT_PRESET_PAYMENT = 'payment';

    public const TYPE_GENERAL = 'general';

    public const TYPE_AGRICULTURE = 'agriculture';

    public const TYPE_REAL_ESTATE = 'real_estate';

    public const TYPES = [
        self::TYPE_GENERAL,
        self::TYPE_AGRICULTURE,
        self::TYPE_REAL_ESTATE,
    ];

    public const TYPE_LABELS = [
        self::TYPE_GENERAL => '一般',
        self::TYPE_AGRICULTURE => '農業',
        self::TYPE_REAL_ESTATE => '不動産',
    ];

    public const CREDIT_SOURCE_CATEGORY_LABELS = [
        self::CREDIT_SOURCE_CATEGORY_CASH => '現金',
        self::CREDIT_SOURCE_CATEGORY_BANK => '銀行口座',
        self::CREDIT_SOURCE_CATEGORY_CARD => 'クレジットカード',
        self::CREDIT_SOURCE_CATEGORY_PRIVATE => 'プライベート資金',
    ];

    private const PAYMENT_ACCOUNT_PRESET_CONFIG = [
        self::PAYMENT_ACCOUNT_PRESET_PAYMENT => [
            'names' => ['現金', '普通預金', 'その他の預金', '事業主借', '買掛金'],
            'order_sql' => "CASE name WHEN '現金' THEN 0 WHEN '普通預金' THEN 1 WHEN 'その他の預金' THEN 2 WHEN '事業主借' THEN 3 WHEN '買掛金' THEN 4 ELSE 99 END",
        ],
    ];

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'notes',
        'current_fiscal_year_id',
    ];

    protected static function booted(): void
    {
        static::deleting(function (BusinessUnit $businessUnit): void {
            if ($businessUnit->current_fiscal_year_id !== null) {
                $businessUnit->updateQuietly([
                    'current_fiscal_year_id' => null,
                ]);
            }

            $businessUnit->fixedAssets()->delete();
            $businessUnit->fiscalYears()->each(function (FiscalYear $fiscalYear): void {
                $fiscalYear->delete();
            });
            $businessUnit->accounts->each->delete();
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function resolveBusinessUnit(): BusinessUnit
    {
        return $this;
    }

    public function canAccess(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    public function fiscalYears()
    {
        return $this->hasMany(FiscalYear::class);
    }

    public function accounts()
    {
        return $this->hasMany(Account::class);
    }

    public function subAccounts(): HasManyThrough
    {
        return $this->hasManyThrough(
            SubAccount::class,
            Account::class,
            'business_unit_id', // Foreign key on Account
            'account_id',       // Foreign key on SubAccount
            'id',               // Local key on BusinessUnit
            'id'                // Local key on Account
        );
    }

    public function counterparties(): HasMany
    {
        return $this->hasMany(Counterparty::class);
    }

    public function initialSetupData(): HasOne
    {
        return $this->hasOne(InitialSetupData::class);
    }

    // 固定資産
    public function fixedAssets(): HasMany
    {
        return $this->hasMany(FixedAsset::class);
    }

    public function allFixedAssets(): Collection
    {
        return $this->fixedAssets()
            ->with('depreciationEntries')
            ->orderByDesc('acquisition_date')
            ->orderByDesc('id')
            ->get();
    }

    public function depreciatingFixedAssets(FiscalYear $fiscalYear): Collection
    {
        $depreciationService = app(DepreciationService::class);

        return $this->allFixedAssets()
            ->filter(fn (FixedAsset $fixedAsset): bool => $depreciationService->isStillDepreciating($fixedAsset, $fiscalYear))
            ->values();
    }

    // 初期勘定科目リスト
    // example, caution は https://www.nta.go.jp/taxes/shiraberu/shinkoku/kojin_jigyo/kichou03.pdf より引用
    public static array $defaultAccounts = [
        // 資産（asset）
        ['name' => '現金', 'type' => Account::TYPE_ASSET],
        ['name' => '当座預金', 'type' => Account::TYPE_ASSET],
        ['name' => '定期預金', 'type' => Account::TYPE_ASSET],
        ['name' => 'その他の預金', 'type' => Account::TYPE_ASSET],
        ['name' => '受取手形', 'type' => Account::TYPE_ASSET],
        ['name' => '売掛金', 'type' => Account::TYPE_ASSET],
        ['name' => '有価証券', 'type' => Account::TYPE_ASSET],
        ['name' => '棚卸資産', 'type' => Account::TYPE_ASSET],
        ['name' => '前払金', 'type' => Account::TYPE_ASSET],
        ['name' => '貸付金', 'type' => Account::TYPE_ASSET],
        ['name' => '建物', 'type' => Account::TYPE_ASSET],
        ['name' => '建物附属設備', 'type' => Account::TYPE_ASSET],
        ['name' => '機械装置', 'type' => Account::TYPE_ASSET],
        ['name' => '車両運搬具', 'type' => Account::TYPE_ASSET],
        ['name' => '工具器具備品', 'type' => Account::TYPE_ASSET],
        ['name' => '土地', 'type' => Account::TYPE_ASSET],
        // 負債（liability）
        ['name' => '支払手形', 'type' => Account::TYPE_LIABILITY],
        ['name' => '買掛金', 'type' => Account::TYPE_LIABILITY],
        ['name' => '借入金', 'type' => Account::TYPE_LIABILITY],
        ['name' => '未払金', 'type' => Account::TYPE_LIABILITY],
        ['name' => '前受金', 'type' => Account::TYPE_LIABILITY],
        ['name' => '預り金', 'type' => Account::TYPE_LIABILITY],

        // 資本（equity）
        ['name' => '事業主借', 'type' => Account::TYPE_EQUITY],
        ['name' => '事業主貸', 'type' => Account::TYPE_EQUITY],
        ['name' => '元入金', 'type' => Account::TYPE_EQUITY],

        // 収益（revenue）
        ['name' => '売上高', 'type' => Account::TYPE_REVENUE],
        ['name' => '雑収入', 'type' => Account::TYPE_REVENUE],
        ['name' => '家事消費等', 'type' => Account::TYPE_REVENUE],

        // 費用（expense）
        // 出典: 国税庁「帳簿の記帳のしかた（事業所得者用）」より、example / caution を付与。
        ['name' => '期首商品（棚卸高）', 'type' => Account::TYPE_EXPENSE],
        ['name' => '期末商品（棚卸高）', 'type' => Account::TYPE_EXPENSE],
        ['name' => '仕入金額', 'type' => Account::TYPE_EXPENSE],
        [
            'name' => '租税公課',
            'type' => Account::TYPE_EXPENSE,
            'example' => "税込経理方式による消費税及び地方消費税の納付税額、事業税、固定資産税、自動車税、不動産取得税、登録免許税、印紙税などの税金\n商工会議所、商工会、協同組合、同業者組合、商店会、青色申告会などの会費や組合費",
            'caution' => '所得税及び復興特別所得税、相続税、贈与税、住民税、国民健康保険税、国民年金の保険料、国税の延滞税・加算税・過怠税、地方税の延滞金・加算金、罰金、科料、過料、交通反則金などは必要経費になりません。',
        ],
        [
            'name' => '荷造運賃',
            'type' => Account::TYPE_EXPENSE,
            'example' => '販売商品の包装材料費、荷造りのための費用、運賃',
        ],
        [
            'name' => '水道光熱費',
            'type' => Account::TYPE_EXPENSE,
            'example' => '水道料、電気代、ガス代、プロパンガスや灯油などの購入費',
        ],
        [
            'name' => '旅費交通費',
            'type' => Account::TYPE_EXPENSE,
            'example' => '電車賃、バス代、タクシー代、宿泊代',
        ],
        [
            'name' => '通信費',
            'type' => Account::TYPE_EXPENSE,
            'example' => '電話料、切手代、電報料、インターネット接続料',
        ],
        [
            'name' => '広告宣伝費',
            'type' => Account::TYPE_EXPENSE,
            'example' => "新聞、雑誌、ラジオ、テレビなどの広告費用、チラシ、折込み広告の費用\n広告用名入りライター、カレンダー、手ぬぐいなどの費用\nショーウインドーの陳列装飾のための費用",
        ],
        [
            'name' => '接待交際費',
            'type' => Account::TYPE_EXPENSE,
            'example' => "取引先などを接待する茶菓飲食代\n取引先などを旅行、観劇などに招待する費用\n取引先などに対する中元、歳暮の費用",
        ],
        [
            'name' => '損害保険料',
            'type' => Account::TYPE_EXPENSE,
            'example' => '火災保険料、自動車の損害保険料',
        ],
        [
            'name' => '修繕費',
            'type' => Account::TYPE_EXPENSE,
            'example' => '店舗、自動車、機械、器具備品などの修理代',
            'caution' => '資産の価額を増したり、使用可能期間を延長したりするような支出は、原則として、資本的支出として一の減価償却資産を取得したものとして、減価償却を行います。',
        ],
        [
            'name' => '消耗品費',
            'type' => Account::TYPE_EXPENSE,
            'example' => "帳簿、文房具、用紙、包装紙、ガソリンなどの消耗品購入費\n使用可能期間が1年未満か取得価額が10万円未満の什器備品の購入費",
            'caution' => '取得価額が10万円未満であるかどうかは、税込経理方式又は税抜経理方式に応じ、その適用している方式により算定した金額によります。',
        ],
        [
            'name' => '減価償却費',
            'type' => Account::TYPE_EXPENSE,
            'example' => '建物、機械、船舶、車両、器具備品などの償却費',
            'caution' => '取得価額が10万円以上20万円未満の減価償却資産については、減価償却をしないでその使用した年以後3年間の各年分において、その減価償却資産の全部又は特定の一部を一括し、一括した減価償却資産の取得価額の合計額の3分の1の金額を必要経費にすることができます。',
        ],
        [
            'name' => '福利厚生費',
            'type' => Account::TYPE_EXPENSE,
            'example' => "従業員の慰安、医療、衛生、保健などのために事業主が支出した費用\n事業主が負担すべき従業員の健康保険、厚生年金、雇用保険などの保険料や掛金",
        ],
        [
            'name' => '給料賃金',
            'type' => Account::TYPE_EXPENSE,
            'example' => '給料、賃金、退職金、従業員の食事や被服などの現物給与',
        ],
        [
            'name' => '外注工賃',
            'type' => Account::TYPE_EXPENSE,
            'example' => '修理加工などで外部に注文して支払った場合の加工費など',
            'caution' => '建設業を営んでいる人などの外注費も含まれます。',
        ],
        [
            'name' => '利子割引料',
            'type' => Account::TYPE_EXPENSE,
            'example' => '事業用資金の借入金の利子や受取手形の割引料など',
        ],
        [
            'name' => '地代家賃',
            'type' => Account::TYPE_EXPENSE,
            'example' => '店舗、工場、倉庫等の敷地の地代や店舗、工場、倉庫等を借りている場合の家賃など',
        ],
        [
            'name' => '貸倒金',
            'type' => Account::TYPE_EXPENSE,
            'example' => '売掛金、受取手形、貸付金などの貸倒損失',
        ],
        ['name' => '専従者給与', 'type' => Account::TYPE_EXPENSE],
        [
            'name' => '雑費',
            'type' => Account::TYPE_EXPENSE,
            'example' => '事業上の費用で他の経費に当てはまらない経費',
        ],
        ['name' => self::UNCLASSIFIED_EXPENSE_ACCOUNT_NAME, 'type' => Account::TYPE_EXPENSE],
    ];

    public static array $defaultSubAccounts = [
        '事業主貸' => ['事業主貸', '源泉徴収'],
        self::UNCLASSIFIED_EXPENSE_ACCOUNT_NAME => [self::UNCLASSIFIED_EXPENSE_SUB_ACCOUNT_NAME],
    ];

    /**
     * 既定シードのうち、UI で最初から表示する（standard）SubAccount 名の一覧。
     * ここに含まれない既定 SubAccount は expanded に降格し、ユーザーが自分で追加した SubAccount は既定で standard。
     *
     * @var array<int, string>
     */
    public static array $standardDefaultSubAccounts = [
        '事業主借',
        '荷造運賃',
        '旅費交通費',
        '接待交際費',
        '修繕費',
        '消耗品費',
        '雑費',
    ];

    /**
     * 既定シードのうち、UI で優先的に上位に表示する SubAccount 名の一覧。この配列の先頭ほど上に並ぶ。
     * ここに含まれない SubAccount は SubAccount::SORT_ORDER_DEFAULT（＝ id 順）で末尾に並ぶ。
     *
     * @var array<int, string>
     */
    public static array $prioritizedDefaultSubAccounts = [
        '消耗品費',
        '旅費交通費',
        '接待交際費',
        '荷造運賃',
        '雑費',
        '広告宣伝費',
        '修繕費',
    ];

    /**
     * 既定シードのうち、UI では最初から表示しない（hidden）SubAccount 名の一覧。
     * 内部処理専用の科目に加え、「場所/口座が具体化するまで使わない」初期プレースホルダもここに置く。
     * ユーザーが「表示する」に切り替えたい場合は visibility を standard / expanded に更新する。
     *
     * @var array<int, string>
     */
    public static array $hiddenDefaultSubAccounts = [
        '現金',
        '当座預金',
        '定期預金',
        'その他の預金',
        '仕入金額',
        '期首商品（棚卸高）',
        '期末商品（棚卸高）',
        '貸倒金',
    ];

    /**
     * BusinessUnitを作成し、標準勘定科目も同時に登録する
     */
    public static function createWithDefaultAccounts(array $attributes): self
    {
        return DB::transaction(function () use ($attributes) {
            $businessUnit = self::create(array_merge([
                'type' => self::TYPE_GENERAL,
            ], $attributes));

            foreach (self::$defaultAccounts as $account) {
                $businessUnit->createAccount($account, $businessUnit->user);
            }

            return $businessUnit;
        });
    }

    /**
     * BusinessUnitに紐づくアカウントを作成するヘルパーメソッド
     */
    public function createAccount(array $attributes, User $user): Account
    {
        $this->authorizeBusinessUnitAccess($this, $user, 'この事業体に勘定科目を追加する権限がありません。');

        return \DB::transaction(function () use ($attributes, $user) {
            $account = $this->accounts()->create($attributes);

            $subAccountNames = self::$defaultSubAccounts[$account->name] ?? [$account->name];

            foreach ($subAccountNames as $subAccountName) {
                $account->addCustomSubAccount(
                    $subAccountName,
                    $user,
                    self::resolveDefaultSubAccountVisibility($subAccountName),
                    self::resolveDefaultSubAccountSystemPurpose($account->name, $subAccountName),
                    self::resolveDefaultSubAccountSortOrder($subAccountName),
                    self::resolveDefaultSubAccountUiLabel($account->name, $subAccountName),
                );
            }

            return $account;
        });
    }

    public static function resolveDefaultSubAccountVisibility(string $subAccountName): string
    {
        if (in_array($subAccountName, self::$hiddenDefaultSubAccounts, true)) {
            return SubAccount::VISIBILITY_HIDDEN;
        }

        if (in_array($subAccountName, self::$standardDefaultSubAccounts, true)) {
            return SubAccount::VISIBILITY_STANDARD;
        }

        return SubAccount::VISIBILITY_EXPANDED;
    }

    public static function resolveDefaultSubAccountSystemPurpose(string $accountName, string $subAccountName): ?string
    {
        if (
            $accountName === self::UNCLASSIFIED_EXPENSE_ACCOUNT_NAME
            && $subAccountName === self::UNCLASSIFIED_EXPENSE_SUB_ACCOUNT_NAME
        ) {
            return SubAccount::PURPOSE_UNCLASSIFIED;
        }

        return null;
    }

    public static function resolveDefaultSubAccountSortOrder(string $subAccountName): int
    {
        $index = array_search($subAccountName, self::$prioritizedDefaultSubAccounts, true);

        return $index === false
            ? SubAccount::SORT_ORDER_DEFAULT
            : ($index + 1) * 10;
    }

    /**
     * 既定シードで作られる SubAccount のうち、UI 上で口語ラベルに置き換えたいもの。
     * {account_name => {sub_account_name => ui_label}}
     *
     * @var array<string, array<string, string>>
     */
    public static array $defaultSubAccountUiLabels = [
        '売掛金' => ['売掛金' => '後日入金予定'],
        '事業主貸' => ['事業主貸' => '個人の財布に入金'],
        '事業主借' => ['事業主借' => '個人の財布・個人のクレジットカードで支払い'],
        '買掛金' => ['買掛金' => '後日支払い予定'],
    ];

    public static function resolveDefaultSubAccountUiLabel(string $accountName, string $subAccountName): ?string
    {
        // 未分類は system_purpose を根拠にしたいので、名前ではなく account+sub のペアで捕まえる。
        if (
            $accountName === self::UNCLASSIFIED_EXPENSE_ACCOUNT_NAME
            && $subAccountName === self::UNCLASSIFIED_EXPENSE_SUB_ACCOUNT_NAME
        ) {
            return '後から決める';
        }

        return self::$defaultSubAccountUiLabels[$accountName][$subAccountName] ?? null;
    }

    public function addCustomAccount(
        string $type,
        string $accountName,
        ?string $subAccountName,
        User $user,
    ): Account {
        $this->authorizeBusinessUnitAccess($this, $user, 'この事業体に勘定科目を追加する権限がありません。');

        if ($this->getAccountByName($accountName) !== null) {
            throw new \InvalidArgumentException('同名の勘定科目は既に存在します。');
        }

        return DB::transaction(function () use ($type, $accountName, $subAccountName, $user): Account {
            $account = $this->accounts()->create([
                'name' => $accountName,
                'type' => $type,
            ]);

            $account->addCustomSubAccount($subAccountName ?? $accountName, $user);

            return $account->refresh();
        });
    }

    /**
     * FiscalYearを作成するヘルパーメソッド
     */
    public function createFiscalYear(int $year, User $actor): FiscalYear
    {
        $this->authorizeBusinessUnitAccess($this, $actor, 'この事業体に会計年度を追加する権限がありません。');

        return DB::transaction(function () use ($year, $actor): FiscalYear {
            $hasActive = $this->fiscalYears()->where('is_active', true)->exists();

            $fiscalYear = $this->fiscalYears()->create([
                'year' => $year,
                'start_date' => "$year-01-01",
                'end_date' => "$year-12-31",
                'is_closed' => false,
                'is_active' => ! $hasActive,  // まだなければtrueにする
            ]);

            $this->setCurrentFiscalYearIfNotSet($fiscalYear, $actor);

            app(DepreciationService::class)->prepareEntriesFor($fiscalYear);

            return $fiscalYear;
        });
    }

    public function getAccountByName(string $name): ?Account
    {
        return $this->accounts()->where('name', $name)->first();
    }

    /**
     * @return Collection<int, Account>
     */
    public function paymentAccounts(string $preset): Collection
    {
        $config = self::PAYMENT_ACCOUNT_PRESET_CONFIG[$preset] ?? null;

        if ($config === null) {
            throw new \InvalidArgumentException("Unknown payment account preset [{$preset}].");
        }

        return $this->accounts()
            ->with(['subAccounts' => fn ($query) => $query->where('visibility', '!=', SubAccount::VISIBILITY_HIDDEN)])
            ->whereIn('name', $config['names'])
            ->orderByRaw($config['order_sql'])
            ->get();
    }

    public function getSubAccountByName(string $accountName, string $subAccountName): ?SubAccount
    {
        return $this->accounts()
            ->where('name', $accountName)
            ->first()
            ->subAccounts()
            ->where('name', $subAccountName)
            ->first();
    }

    public function taxPaidAccount(): Account
    {
        return $this->accounts()
            ->where('name', '仮払消費税')
            ->firstOrFail();
    }

    public function taxReceivedAccount(): Account
    {
        return $this->accounts()
            ->where('name', '仮受消費税')
            ->firstOrFail();
    }

    public function recurringTransactionPlans()
    {
        return $this->hasMany(RecurringTransactionPlan::class);
    }

    public function creditCards(): HasMany
    {
        return $this->hasMany(CreditCard::class);
    }

    /**
     * @return Collection<int, array{
     *     key: string,
     *     category: string,
     *     category_label: string,
     *     label: string,
     *     description: string,
     *     sub_account_id: int,
     *     account_id: int,
     *     sort_order: int
     * }>
     */
    public function availableCreditSources(): Collection
    {
        return $this->buildCashCreditSources()
            ->concat($this->buildBankCreditSources())
            ->concat($this->buildCreditCardSources())
            ->concat($this->buildPrivateCreditSources())
            ->sortBy('sort_order')
            ->values();
    }

    public function createRecurringTransactionPlan(array $attributes, User $actor): RecurringTransactionPlan
    {
        $this->authorizeBusinessUnitAccess($this, $actor, 'この事業体に定期取引を追加する権限がありません。');

        $attributes['business_unit_id'] = $this->id;

        $validated = RecurringTransactionPlan::validate($attributes);

        return $this->recurringTransactionPlans()
            ->create($validated)
            ->refresh();
    }

    public function createCreditCard(array $attributes, User $actor): CreditCard
    {
        $this->authorizeBusinessUnitAccess($this, $actor, 'この事業体にクレジットカードを追加する権限がありません。');

        $attributes['business_unit_id'] = $this->id;

        $validated = CreditCard::validate($attributes);

        return $this->creditCards()
            ->create($validated)
            ->refresh();
    }

    public function generatePlannedTransactionsForPlan(
        RecurringTransactionPlan $plan,
        FiscalYear $fiscalYear,
        User $actor
    ): Collection {
        if ($plan->business_unit_id !== $this->id) {
            throw new \InvalidArgumentException('This plan does not belong to this business unit.');
        }

        $this->authorizeBusinessUnitAccess($this, $actor, 'この事業体の予定取引を生成する権限がありません。');

        if ($plan->is_active === false) {
            return collect();
        }

        $transactions = collect();

        foreach ($plan->getPlannedDatesIn($fiscalYear) as $date) {
            $data = $plan->toTransactionData($date);

            if (
                $plan->transactions()
                    ->whereDate('date', $date)
                    ->where('is_planned', true)
                    ->exists()
            ) {
                continue;
            }
            $transaction = app(TransactionRegistrar::class)->register(
                $fiscalYear,
                $data['transaction'],
                $data['entries'],
                $actor,
            );

            $transactions->push($transaction);
        }

        return $transactions;
    }

    public function hasSubAccount(int $subAccountId): bool
    {
        return $this->subAccounts()
            ->whereKey($subAccountId)
            ->exists();
    }

    public function subAccountExistsRule(): Exists
    {
        return Rule::exists('sub_accounts', 'id')
            ->where(fn ($query) => $query->whereIn('account_id', $this->accounts()->select('id')));
    }

    public function currentFiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'current_fiscal_year_id');
    }

    public function activateFiscalYear(FiscalYear $fiscalYear, User $actor): void
    {
        $this->authorizeBusinessUnitAccess($this, $actor, 'この事業体の現在の会計年度を変更する権限がありません。');

        if ($fiscalYear->business_unit_id !== $this->id) {
            throw new \InvalidArgumentException('他の事業体の年度は選択できません。');
        }

        DB::transaction(function () use ($fiscalYear): void {
            $this->fiscalYears()->update(['is_active' => false]);

            $this->fiscalYears()
                ->whereKey($fiscalYear->id)
                ->update(['is_active' => true]);

            $this->update(['current_fiscal_year_id' => $fiscalYear->id]);
        });
    }

    public function setCurrentFiscalYear(FiscalYear $fiscalYear, User $actor): void
    {
        $this->authorizeBusinessUnitAccess($this, $actor, 'この事業体の現在の会計年度を変更する権限がありません。');

        if ($fiscalYear->business_unit_id !== $this->id) {
            throw new \InvalidArgumentException('他の事業体の年度は選択できません。');
        }

        $this->update(['current_fiscal_year_id' => $fiscalYear->id]);
    }

    public function setCurrentFiscalYearIfNotSet(FiscalYear $fiscalYear, User $actor): void
    {
        if (is_null($this->current_fiscal_year_id)) {
            $this->setCurrentFiscalYear($fiscalYear, $actor);
        }
    }

    public function createNextFiscalYearFrom(
        FiscalYear $fiscalYear,
        User $actor,
        ?bool $isTaxable = null,
        ?bool $isTaxExclusive = null,
    ): FiscalYear {
        $this->authorizeBusinessUnitAccess($this, $actor, 'この事業体に会計年度を追加する権限がありません。');

        if ($fiscalYear->business_unit_id !== $this->id) {
            throw new \InvalidArgumentException('他の事業体の年度を基準に翌年度は作成できません。');
        }

        $nextYear = $fiscalYear->year + 1;

        if ($this->fiscalYears()->where('year', $nextYear)->exists()) {
            throw new \InvalidArgumentException('翌年度はすでに作成されています。');
        }

        $createdFiscalYear = $this->createFiscalYear($nextYear, $actor);

        $createdFiscalYear->update([
            'is_taxable' => $isTaxable ?? (bool) $fiscalYear->is_taxable,
            'is_tax_exclusive' => $isTaxExclusive ?? (bool) $fiscalYear->is_tax_exclusive,
        ]);

        return $createdFiscalYear->refresh();
    }

    /**
     * @return Collection<int, array{
     *     key: string,
     *     category: string,
     *     category_label: string,
     *     label: string,
     *     description: string,
     *     sub_account_id: int,
     *     account_id: int,
     *     sort_order: int
     * }>
     */
    private function buildCashCreditSources(): Collection
    {
        $account = $this->getAccountByName('現金');

        if ($account === null) {
            return collect();
        }

        $explicitSubAccounts = $account->subAccounts
            ->filter(fn (SubAccount $subAccount): bool => $subAccount->name !== $account->name);

        $hasUsage = $this->hasAnyActiveJournalEntryForAccount($account);

        if (! $hasUsage && $explicitSubAccounts->isEmpty()) {
            return collect();
        }

        $availableSubAccounts = $hasUsage
            ? $account->subAccounts
            : $explicitSubAccounts;

        return $availableSubAccounts
            ->map(fn (SubAccount $subAccount): array => $this->makeCreditSourceOption(
                key: 'cash-sub-account:'.$subAccount->id,
                category: self::CREDIT_SOURCE_CATEGORY_CASH,
                label: $subAccount->name,
                description: '手元の現金から支払う',
                subAccount: $subAccount,
                sortOrder: 10,
            ))
            ->values();
    }

    /**
     * @return Collection<int, array{
     *     key: string,
     *     category: string,
     *     category_label: string,
     *     label: string,
     *     description: string,
     *     sub_account_id: int,
     *     account_id: int,
     *     sort_order: int
     * }>
     */
    private function buildBankCreditSources(): Collection
    {
        $account = $this->getAccountByName('その他の預金');

        if ($account === null) {
            return collect();
        }

        $explicitSubAccounts = $account->subAccounts
            ->filter(fn (SubAccount $subAccount): bool => $subAccount->name !== 'その他の預金');

        $hasUsage = $this->hasAnyActiveJournalEntryForAccount($account);

        if (! $hasUsage && $explicitSubAccounts->isEmpty()) {
            return collect();
        }

        return $explicitSubAccounts
            ->map(fn (SubAccount $subAccount): array => $this->makeCreditSourceOption(
                key: 'bank-sub-account:'.$subAccount->id,
                category: self::CREDIT_SOURCE_CATEGORY_BANK,
                label: $subAccount->name,
                description: '事業用の銀行口座から支払う',
                subAccount: $subAccount,
                sortOrder: 20,
            ))
            ->values();
    }

    /**
     * @return Collection<int, array{
     *     key: string,
     *     category: string,
     *     category_label: string,
     *     label: string,
     *     description: string,
     *     sub_account_id: int,
     *     account_id: int,
     *     sort_order: int
     * }>
     */
    private function buildCreditCardSources(): Collection
    {
        return $this->creditCards()
            ->with('liabilitySubAccount.account')
            ->where('ownership_type', CreditCard::OWNERSHIP_TYPE_BUSINESS)
            ->where('is_active', true)
            ->get()
            ->filter(fn (CreditCard $creditCard): bool => $creditCard->liabilitySubAccount !== null)
            ->map(function (CreditCard $creditCard): array {
                /** @var SubAccount $subAccount */
                $subAccount = $creditCard->liabilitySubAccount;

                return $this->makeCreditSourceOption(
                    key: 'card:'.$creditCard->id,
                    category: self::CREDIT_SOURCE_CATEGORY_CARD,
                    label: $creditCard->display_label,
                    description: '事業用クレジットカードで支払う',
                    subAccount: $subAccount,
                    sortOrder: 30,
                );
            })
            ->values();
    }

    /**
     * @return Collection<int, array{
     *     key: string,
     *     category: string,
     *     category_label: string,
     *     label: string,
     *     description: string,
     *     sub_account_id: int,
     *     account_id: int,
     *     sort_order: int
     * }>
     */
    private function buildPrivateCreditSources(): Collection
    {
        $account = $this->getAccountByName('事業主借');

        if ($account === null) {
            return collect();
        }

        return $account->subAccounts
            ->map(function (SubAccount $subAccount): array {
                $label = $subAccount->name === '事業主借'
                    ? 'プライベートの財布・クレジットから支払い'
                    : $subAccount->name;

                return $this->makeCreditSourceOption(
                    key: 'private-sub-account:'.$subAccount->id,
                    category: self::CREDIT_SOURCE_CATEGORY_PRIVATE,
                    label: $label,
                    description: '個人のお金で立て替えて支払った場合',
                    subAccount: $subAccount,
                    sortOrder: 40,
                );
            })
            ->values();
    }

    /**
     * @return array{
     *     key: string,
     *     category: string,
     *     category_label: string,
     *     label: string,
     *     description: string,
     *     sub_account_id: int,
     *     account_id: int,
     *     sort_order: int
     * }
     */
    private function makeCreditSourceOption(
        string $key,
        string $category,
        string $label,
        string $description,
        SubAccount $subAccount,
        int $sortOrder,
    ): array {
        return [
            'key' => $key,
            'category' => $category,
            'category_label' => self::CREDIT_SOURCE_CATEGORY_LABELS[$category],
            'label' => $label,
            'description' => $description,
            'sub_account_id' => $subAccount->id,
            'account_id' => $subAccount->account_id,
            'sort_order' => $sortOrder,
        ];
    }

    private function hasAnyActiveJournalEntryForAccount(Account $account): bool
    {
        return $account->journalEntries()
            ->whereHas('transaction', fn ($query) => $query->active())
            ->exists();
    }
}
