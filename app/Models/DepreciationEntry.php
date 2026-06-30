<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepreciationEntry extends Model
{
    protected $fillable = [
        'fiscal_year_id',
        'fixed_asset_id',
        'months',
        'ordinary_amount',
        'special_amount',
        'total_amount',
        'business_usage_ratio',
        'deductible_amount',
        'transaction_id',
    ];

    protected $casts = [
        'business_usage_ratio' => 'decimal:2',
    ];

    // 対象の会計年度
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    // 対象の固定資産
    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }

    // 記帳された仕訳
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function getAcquisitionYearMonthAttribute(): ?string
    {
        return $this->fixedAsset?->acquisition_date?->format('Y-m');
    }

    public function getDepreciationBaseAmountAttribute(): ?int
    {
        return $this->fixedAsset?->acquisition_cost;
    }

    public function getDepreciationMethodAttribute(): ?string
    {
        return $this->fixedAsset?->depreciation_method;
    }

    public function getUsefulLifeAttribute(): ?int
    {
        $usefulLife = $this->fixedAsset?->useful_life;

        if ($usefulLife === null) {
            return null;
        }

        return intdiv($usefulLife, 12);
    }

    public function getDepreciationRateAttribute(): ?string
    {
        $usefulLife = $this->fixedAsset?->useful_life;

        if ($usefulLife === null || $usefulLife <= 0) {
            return null;
        }

        return number_format(12 / $usefulLife, 3, '.', '');
    }

    public function getEndingUndepreciatedBalanceAttribute(): ?int
    {
        if ($this->fixedAsset === null) {
            return null;
        }

        $depreciatedAmount = $this->fixedAsset->depreciationEntries()
            ->whereHas('fiscalYear', function ($query): void {
                $query->where('year', '<=', $this->fiscalYear->year);
            })
            ->sum('total_amount');

        return max(0, $this->fixedAsset->acquisition_cost - (int) $depreciatedAmount);
    }

    // 未記帳かどうかを判定（便利メソッド）
    public function isUnposted(): bool
    {
        return is_null($this->transaction_id);
    }
}
