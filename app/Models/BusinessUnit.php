<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use App\Models\RecurringTransactionPlan;
use App\Models\FiscalYear;
use Illuminate\Support\Collection;
use App\Services\TransactionRegistrar;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class BusinessUnit extends Model
{
    use HasFactory;

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

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'notes',
        'current_fiscal_year_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
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
            \App\Models\SubAccount::class,
            \App\Models\Account::class,
            'business_unit_id', // Foreign key on Account
            'account_id',       // Foreign key on SubAccount
            'id',               // Local key on BusinessUnit
            'id'                // Local key on Account
        );
    }

    // 初期勘定科目リスト
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
        ['name' => '期首商品（棚卸高）', 'type' => Account::TYPE_ASSET],
        ['name' => '期末商品（棚卸高）', 'type' => Account::TYPE_ASSET],

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
        ['name' => '仕入金額', 'type' => Account::TYPE_EXPENSE],
        ['name' => '租税公課', 'type' => Account::TYPE_EXPENSE],
        ['name' => '荷造運賃', 'type' => Account::TYPE_EXPENSE],
        ['name' => '水道光熱費', 'type' => Account::TYPE_EXPENSE],
        ['name' => '旅費交通費', 'type' => Account::TYPE_EXPENSE],
        ['name' => '通信費', 'type' => Account::TYPE_EXPENSE],
        ['name' => '広告宣伝費', 'type' => Account::TYPE_EXPENSE],
        ['name' => '接待交際費', 'type' => Account::TYPE_EXPENSE],
        ['name' => '損害保険料', 'type' => Account::TYPE_EXPENSE],
        ['name' => '修繕費', 'type' => Account::TYPE_EXPENSE],
        ['name' => '消耗品費', 'type' => Account::TYPE_EXPENSE],
        ['name' => '減価償却費', 'type' => Account::TYPE_EXPENSE],
        ['name' => '福利厚生費', 'type' => Account::TYPE_EXPENSE],
        ['name' => '給料賃金', 'type' => Account::TYPE_EXPENSE],
        ['name' => '外注工賃', 'type' => Account::TYPE_EXPENSE],
        ['name' => '利子割引料', 'type' => Account::TYPE_EXPENSE],
        ['name' => '地代家賃', 'type' => Account::TYPE_EXPENSE],
        ['name' => '貸倒金', 'type' => Account::TYPE_EXPENSE],
        ['name' => '雑費', 'type' => Account::TYPE_EXPENSE],
    ];


    public static array $defaultSubAccounts = [
        '事業主貸' => ['事業主貸', '源泉徴収'],
        '現金' => ['レジ現金', 'その他現金'],
        '売上高' => ['一般売上'],
    ];

    /**
     * BusinessUnitを作成し、標準勘定科目も同時に登録する
     *
     * @param array $attributes
     * @return self
     */
    public static function createWithDefaultAccounts(array $attributes): self
    {
        return DB::transaction(function () use ($attributes) {
            $businessUnit = self::create($attributes);

            foreach (self::$defaultAccounts as $account) {
                $businessUnit->createAccount($account);
            }

            return $businessUnit;
        });
    }


    /**
     * BusinessUnitに紐づくアカウントを作成するヘルパーメソッド
     *
     * @param array $attributes
     * @return Account
     */
    public function createAccount(array $attributes): Account
    {
        return \DB::transaction(function () use ($attributes) {
            $account = $this->accounts()->create($attributes);

            if (isset(self::$defaultSubAccounts[$account->name])) {
                foreach (self::$defaultSubAccounts[$account->name] as $subAccountName) {
                    $account->subAccounts()->create([
                        'name' => $subAccountName,
                    ]);
                }
            } else {
                $account->subAccounts()->create([
                    'name' => $account->name,
                ]);
            }

            return $account;
        });
    }

    /**
     * FiscalYearを作成するヘルパーメソッド
     * 
     *  @param int $year
     * @return FiscalYear
     */
    public function createFiscalYear(int $year): FiscalYear
    {

        $hasActive = $this->fiscalYears()->where('is_active', true)->exists();

        $fiscalYear =  $this->fiscalYears()->create([
            'year' => $year,
            'start_date' => "$year-01-01",
            'end_date' => "$year-12-31",
            'is_closed' => false,
            'is_active' => !$hasActive,  // まだなければtrueにする
        ]);

        $this->setCurrentFiscalYearIfNotSet($fiscalYear);

        return $fiscalYear;
    }

    public function getAccountByName(string $name): ?Account
    {
        return $this->accounts()->where('name', $name)->first();
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

    public function createRecurringTransactionPlan(array $attributes): RecurringTransactionPlan
    {
        $attributes['business_unit_id'] = $this->id;

        $validated = RecurringTransactionPlan::validate($attributes);

        return $this->recurringTransactionPlans()
            ->create($validated)
            ->refresh();
    }

    public function generatePlannedTransactionsForPlan(RecurringTransactionPlan $plan, FiscalYear $fiscalYear): Collection
    {
        if ($plan->business_unit_id !== $this->id) {
            throw new \InvalidArgumentException('This plan does not belong to this business unit.');
        }

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
                $data['entries']
            );

            $transactions->push($transaction);
        }

        return $transactions;
    }

    public function currentFiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'current_fiscal_year_id');
    }

    public function setCurrentFiscalYear(FiscalYear $fiscalYear): void
    {
        if ($fiscalYear->business_unit_id !== $this->id) {
            throw new \InvalidArgumentException('他の事業体の年度は選択できません。');
        }

        $this->update(['current_fiscal_year_id' => $fiscalYear->id]);
    }

    public function setCurrentFiscalYearIfNotSet(FiscalYear $fiscalYear): void
    {
        if (is_null($this->current_fiscal_year_id)) {
            $this->setCurrentFiscalYear($fiscalYear);
        }
    }
}
