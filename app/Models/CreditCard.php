<?php

namespace App\Models;

use Database\Factories\CreditCardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditCard extends Model
{
    public const OWNERSHIP_TYPE_BUSINESS = 'business';

    public const OWNERSHIP_TYPE_PERSONAL = 'personal';

    public const OWNERSHIP_TYPES = [
        self::OWNERSHIP_TYPE_BUSINESS,
        self::OWNERSHIP_TYPE_PERSONAL,
    ];

    /** @use HasFactory<CreditCardFactory> */
    use HasFactory;

    protected $fillable = [
        'business_unit_id',
        'name',
        'issuer_name',
        'network',
        'last_four',
        'ownership_type',
        'parser_key',
        'liability_sub_account_id',
        'owner_draw_sub_account_id',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function getDisplayLabelAttribute(): string
    {
        $parts = array_filter([
            $this->issuer_name,
            $this->network ? strtoupper((string) $this->network) : null,
            $this->last_four ? '****'.$this->last_four : null,
        ]);

        return implode(' ', $parts);
    }

    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    public function liabilitySubAccount(): BelongsTo
    {
        return $this->belongsTo(SubAccount::class, 'liability_sub_account_id');
    }

    public function ownerDrawSubAccount(): BelongsTo
    {
        return $this->belongsTo(SubAccount::class, 'owner_draw_sub_account_id');
    }

    public function statements(): HasMany
    {
        return $this->hasMany(CreditCardStatement::class);
    }

    public function requiresFullRegistration(): bool
    {
        return $this->ownership_type === self::OWNERSHIP_TYPE_BUSINESS;
    }

    public function allowsSelectiveRegistration(): bool
    {
        return $this->ownership_type === self::OWNERSHIP_TYPE_PERSONAL;
    }

    public function defaultCreditSubAccountId(): ?int
    {
        return match ($this->ownership_type) {
            self::OWNERSHIP_TYPE_BUSINESS => $this->liability_sub_account_id,
            self::OWNERSHIP_TYPE_PERSONAL => $this->owner_draw_sub_account_id,
            default => null,
        };
    }
}
