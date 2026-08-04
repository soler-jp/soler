<?php

namespace App\Models;

use App\Concerns\AuthorizesBusinessUnitAccess;
use App\Contracts\ResolvesBusinessUnit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Account extends Model implements ResolvesBusinessUnit
{
    use AuthorizesBusinessUnitAccess;
    use HasFactory;

    // 勘定科目の5分類（type）
    public const TYPE_ASSET = 'asset';     // 資産

    public const TYPE_LIABILITY = 'liability'; // 負債

    public const TYPE_EQUITY = 'equity';    // 資本

    public const TYPE_REVENUE = 'revenue';   // 収益

    public const TYPE_EXPENSE = 'expense';   // 費用

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

    protected static function booted(): void
    {
        static::deleting(function (Account $account): void {
            $account->subAccounts()->delete();
        });
    }

    public function businessUnit()
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    public function resolveBusinessUnit(): BusinessUnit
    {
        $this->loadMissing('businessUnit');

        return $this->businessUnit;
    }

    public function subAccounts()
    {
        return $this->hasMany(SubAccount::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function journalEntries(): HasManyThrough
    {
        return $this->hasManyThrough(
            JournalEntry::class,
            SubAccount::class,
            'account_id',       // SubAccount が参照しているこの Account の ID
            'sub_account_id',   // JournalEntry が参照している SubAccount の ID
            'id',               // この Account の ID
            'id'                // SubAccount の ID
        );
    }

    public function createSubAccount(array $attributes, User $actor): SubAccount
    {
        $this->authorizeBusinessUnitAccess($this, $actor, 'この勘定科目に補助科目を追加する権限がありません。');

        if (empty($attributes['name'])) {
            throw new \InvalidArgumentException('name は必須です。');
        }

        return $this->subAccounts()->create($attributes);
    }

    public function addCustomSubAccount(
        string $subAccountName,
        User $actor,
        ?string $visibility = null,
        ?string $systemPurpose = null,
        ?int $sortOrder = null,
    ): SubAccount {
        $attributes = ['name' => $subAccountName];

        if ($visibility !== null) {
            $attributes['visibility'] = $visibility;
        }

        if ($systemPurpose !== null) {
            $attributes['system_purpose'] = $systemPurpose;
        }

        if ($sortOrder !== null) {
            $attributes['sort_order'] = $sortOrder;
        }

        return $this->createSubAccount($attributes, $actor);
    }
}
