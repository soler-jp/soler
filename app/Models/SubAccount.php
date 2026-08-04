<?php

namespace App\Models;

use App\Contracts\ResolvesBusinessUnit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubAccount extends Model implements ResolvesBusinessUnit
{
    use HasFactory;

    public const PURPOSE_UNCLASSIFIED = 'unclassified';

    public const PURPOSE_HOUSEHOLD_ALLOCATION = 'household_allocation';

    public const PURPOSES = [
        self::PURPOSE_UNCLASSIFIED,
        self::PURPOSE_HOUSEHOLD_ALLOCATION,
    ];

    public const VISIBILITY_STANDARD = 'standard';

    public const VISIBILITY_EXPANDED = 'expanded';

    public const VISIBILITY_HIDDEN = 'hidden';

    public const VISIBILITIES = [
        self::VISIBILITY_STANDARD,
        self::VISIBILITY_EXPANDED,
        self::VISIBILITY_HIDDEN,
    ];

    public const SORT_ORDER_DEFAULT = 1000;

    protected $fillable = [
        'account_id',
        'name',
        'system_purpose',
        'visibility',
        'sort_order',
    ];

    protected $attributes = [
        'visibility' => self::VISIBILITY_STANDARD,
        'sort_order' => self::SORT_ORDER_DEFAULT,
    ];

    protected static function booted(): void
    {
        static::saving(function (SubAccount $subAccount): void {
            if ($subAccount->system_purpose !== null && ! in_array($subAccount->system_purpose, self::PURPOSES, true)) {
                throw new \InvalidArgumentException("SubAccount::system_purpose の値が不正です: {$subAccount->system_purpose}");
            }

            if (! in_array($subAccount->visibility, self::VISIBILITIES, true)) {
                throw new \InvalidArgumentException("SubAccount::visibility の値が不正です: {$subAccount->visibility}");
            }
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function resolveBusinessUnit(): BusinessUnit
    {
        $this->loadMissing('account.businessUnit');

        return $this->account->businessUnit;
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }
}
