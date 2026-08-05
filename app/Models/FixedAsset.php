<?php

namespace App\Models;

use App\Contracts\ResolvesBusinessUnit;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FixedAsset extends Model implements ResolvesBusinessUnit
{
    use HasFactory;

    public const ASSET_CATEGORY_NEW_STANDARD_CAR = '新車-普通車';

    public const ASSET_CATEGORY_NEW_LIGHT_CAR = '新車-軽自動車';

    public const ASSET_CATEGORY_USED_STANDARD_CAR = '中古車-普通車';

    public const ASSET_CATEGORY_USED_LIGHT_CAR = '中古車-軽自動車';

    public const DEPRECIATION_METHOD_STRAIGHT_LINE = 'straight_line';

    protected $fillable = [
        'business_unit_id',
        'account_id',
        'name',
        'asset_category',
        'acquisition_date',
        'first_registration_date',
        'taxable_amount',
        'tax_amount',
        'useful_life',
        'depreciation_method',
        'initial_opening_transaction_id',
        'is_disposed',
        'disposed_at',
        'disposal_amount',
        'disposal_account_id',
        'disposal_gain_loss_account_id',
    ];

    protected $casts = [
        'acquisition_date' => 'date',
        'first_registration_date' => 'date',
        'disposed_at' => 'date',
        'taxable_amount' => 'integer',
        'tax_amount' => 'integer',
        'useful_life' => 'integer',
        'disposal_amount' => 'integer',
        'is_disposed' => 'boolean',
    ];

    public function isNewStandardCar(): bool
    {
        return $this->asset_category === self::ASSET_CATEGORY_NEW_STANDARD_CAR;
    }

    public function isNewLightCar(): bool
    {
        return $this->asset_category === self::ASSET_CATEGORY_NEW_LIGHT_CAR;
    }

    public function isUsedStandardCar(): bool
    {
        return $this->asset_category === self::ASSET_CATEGORY_USED_STANDARD_CAR;
    }

    public function isUsedLightCar(): bool
    {
        return $this->asset_category === self::ASSET_CATEGORY_USED_LIGHT_CAR;
    }

    public function acquisitionCost(): Attribute
    {
        return Attribute::get(
            fn () => $this->taxable_amount + $this->tax_amount,
        );
    }

    // 所属する事業体
    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    public function resolveBusinessUnit(): BusinessUnit
    {
        $this->loadMissing('businessUnit');

        return $this->businessUnit;
    }

    // 資産計上された勘定科目（例: 器具備品）
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    // 売却時の振込先（例: 普通預金）
    public function disposalAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'disposal_account_id');
    }

    // 売却損益の処理先（例: 雑収入, 雑損失）
    public function disposalGainLossAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'disposal_gain_loss_account_id');
    }

    // 各年度の減価償却記録
    public function depreciationEntries(): HasMany
    {
        return $this->hasMany(DepreciationEntry::class);
    }

    /**
     * この資産の期首残高を反映した期首仕訳。
     * 固定資産専用の仕訳とは限らず、他の開始残高行と同じ期首仕訳を共有することがある。
     * TransactionRevisor で修正されると、この FK は元の（deactivated な）Transaction を指したままになる。
     * 現行の active な仕訳を取りたい場合は activeInitialOpeningTransaction() を使う。
     */
    public function initialOpeningTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'initial_opening_transaction_id');
    }

    /**
     * initial_opening_transaction から revision chain を辿って、現行の active な期首振替を返す。
     * 修正されていなければ initialOpeningTransaction と同じ。すべて無効化されていれば null。
     */
    public function activeInitialOpeningTransaction(): ?Transaction
    {
        $transaction = $this->initialOpeningTransaction;

        while ($transaction !== null && ! $transaction->is_active) {
            $transaction = $transaction->revision;
        }

        return $transaction;
    }

    public function needsInitialOpeningTransfer(FiscalYear $fiscalYear): bool
    {
        if ($this->acquisition_date === null) {
            return false;
        }

        if (! $this->acquisition_date->lt($fiscalYear->start_date)) {
            return false;
        }

        return $this->initial_opening_transaction_id === null;
    }
}
