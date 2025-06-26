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
        'journal_entry_id',
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
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    // 未記帳かどうかを判定（便利メソッド）
    public function isUnposted(): bool
    {
        return is_null($this->journal_entry_id);
    }
}
