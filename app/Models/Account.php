<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\BusinessUnit;
use App\Models\SubAccount;
use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Account extends Model
{
    use HasFactory;

    // 勘定科目の5分類（type）
    public const TYPE_ASSET     = 'asset';     // 資産
    public const TYPE_LIABILITY = 'liability'; // 負債
    public const TYPE_EQUITY    = 'equity';    // 資本
    public const TYPE_REVENUE   = 'revenue';   // 収益
    public const TYPE_EXPENSE   = 'expense';   // 費用

    public const TYPES = [
        self::TYPE_ASSET,
        self::TYPE_LIABILITY,
        self::TYPE_EQUITY,
        self::TYPE_REVENUE,
        self::TYPE_EXPENSE,
    ];

    protected $fillable = [
        'business_unit_id',
        'name',
        'type',
    ];

    public function businessUnit()
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    public function subAccounts()
    {
        return $this->hasMany(SubAccount::class)
            ->orderBy('id');
    }

    public function journalEntries(): HasManyThrough
    {
        return $this->hasManyThrough(
            \App\Models\JournalEntry::class,
            \App\Models\SubAccount::class,
            'account_id',       // SubAccount が参照しているこの Account の ID
            'sub_account_id',   // JournalEntry が参照している SubAccount の ID
            'id',               // この Account の ID
            'id'                // SubAccount の ID
        );
    }

    public function createSubAccount(array $attributes): SubAccount
    {
        if (empty($attributes['name'])) {
            throw new \InvalidArgumentException('name は必須です。');
        }

        return $this->subAccounts()->create($attributes);
    }
}
