<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FixedAsset extends Model
{
    use HasFactory;

    public const DEPRECIATION_METHOD_STRAIGHT_LINE = 'straight_line';

    protected $fillable = [
        'business_unit_id',
        'account_id',
        'name',
        'asset_category',
        'acquisition_date',
        'taxable_amount',
        'tax_amount',
        'depreciation_base_amount',
        'useful_life',
        'depreciation_method',
        'is_disposed',
        'disposed_at',
        'disposal_amount',
        'disposal_account_id',
        'disposal_gain_loss_account_id',
    ];

    protected $casts = [
        'acquisition_date' => 'date',
        'disposed_at' => 'date',
        'taxable_amount' => 'integer',
        'tax_amount' => 'integer',
        'depreciation_base_amount' => 'integer',
        'useful_life' => 'integer',
        'disposal_amount' => 'integer',
        'is_disposed' => 'boolean',
    ];

    public function isNewStandardCar(): bool
    {
        return $this->asset_category === '新車-普通車';
    }

    public function isNewLightCar(): bool
    {
        return $this->asset_category === '新車-軽自動車';
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
}
