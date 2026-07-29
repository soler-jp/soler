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

    protected $fillable = [
        'account_id',
        'name',
    ];

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
