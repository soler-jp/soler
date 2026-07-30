<?php

namespace App\Models;

use App\Contracts\ResolvesBusinessUnit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CounterpartyQualificationEvent extends Model implements ResolvesBusinessUnit
{
    use HasFactory;

    protected $fillable = [
        'counterparty_id',
        'qualification_status',
        'effective_from',
        'recorded_at',
    ];

    protected $casts = [
        'effective_from' => 'datetime',
        'recorded_at' => 'datetime',
    ];

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }

    public function resolveBusinessUnit(): BusinessUnit
    {
        $this->loadMissing('counterparty.businessUnit');

        return $this->counterparty->businessUnit;
    }
}
