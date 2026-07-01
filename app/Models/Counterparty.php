<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Counterparty extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_unit_id',
        'name',
        'registration_number',
        'is_qualified_invoice_issuer',
        'notes',
    ];

    protected $casts = [
        'is_qualified_invoice_issuer' => 'boolean',
    ];

    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
