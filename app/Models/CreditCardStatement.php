<?php

namespace App\Models;

use Database\Factories\CreditCardStatementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditCardStatement extends Model
{
    public const STATUS_EMPTY = 'empty';

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_REVIEWING = 'reviewing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [
        self::STATUS_EMPTY,
        self::STATUS_IMPORTED,
        self::STATUS_REVIEWING,
        self::STATUS_COMPLETED,
    ];

    /** @use HasFactory<CreditCardStatementFactory> */
    use HasFactory;

    protected $fillable = [
        'credit_card_id',
        'statement_year',
        'statement_month',
        'period_start_on',
        'period_end_on',
        'billed_on',
        'paid_on',
        'total_amount',
        'line_count',
        'imported_at',
    ];

    protected $casts = [
        'statement_year' => 'integer',
        'statement_month' => 'integer',
        'period_start_on' => 'date',
        'period_end_on' => 'date',
        'billed_on' => 'date',
        'paid_on' => 'date',
        'total_amount' => 'integer',
        'line_count' => 'integer',
        'imported_at' => 'datetime',
    ];

    public function creditCard(): BelongsTo
    {
        return $this->belongsTo(CreditCard::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CreditCardStatementLine::class);
    }

    public function activeLines(): HasMany
    {
        return $this->lines()->where('is_active', true);
    }

    public function importBatches(): HasMany
    {
        return $this->hasMany(CreditCardImportBatch::class);
    }

    public function activeImportBatches(): HasMany
    {
        return $this->importBatches()->where('is_active', true);
    }

    public function isCompleted(): bool
    {
        return $this->computed_status === self::STATUS_COMPLETED;
    }

    public function unresolvedLines(): HasMany
    {
        return $this->activeLines()->whereIn('status', [
            CreditCardStatementLine::STATUS_UNREVIEWED,
        ]);
    }

    public function getComputedStatusAttribute(): string
    {
        $lineCount = $this->relationLoaded('lines')
            ? $this->lines->where('is_active', true)->count()
            : $this->activeLines()->count();

        if ($lineCount === 0) {
            return self::STATUS_EMPTY;
        }

        $unreviewedCount = $this->relationLoaded('lines')
            ? $this->lines
                ->where('is_active', true)
                ->where('status', CreditCardStatementLine::STATUS_UNREVIEWED)
                ->count()
            : $this->unresolvedLines()->count();

        if ($unreviewedCount === $lineCount) {
            return self::STATUS_IMPORTED;
        }

        if ($unreviewedCount > 0) {
            return self::STATUS_REVIEWING;
        }

        return self::STATUS_COMPLETED;
    }

    public function canBeCompleted(): bool
    {
        return ! $this->unresolvedLines()->exists();
    }
}
