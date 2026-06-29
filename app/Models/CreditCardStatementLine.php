<?php

namespace App\Models;

use Database\Factories\CreditCardStatementLineFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditCardStatementLine extends Model
{
    public const STATUS_UNREVIEWED = 'unreviewed';

    public const STATUS_REGISTERED = 'registered';

    public const STATUS_PRIVATE = 'private';

    public const STATUS_DUPLICATE = 'duplicate';

    public const STATUS_IGNORED = 'ignored';

    public const STATUSES = [
        self::STATUS_UNREVIEWED,
        self::STATUS_REGISTERED,
        self::STATUS_PRIVATE,
        self::STATUS_DUPLICATE,
        self::STATUS_IGNORED,
    ];

    /** @use HasFactory<CreditCardStatementLineFactory> */
    use HasFactory;

    protected $fillable = [
        'credit_card_statement_id',
        'credit_card_import_batch_id',
        'line_number',
        'used_on',
        'posted_on',
        'merchant_name',
        'description',
        'amount',
        'fingerprint',
        'status',
        'is_active',
        'memo',
        'raw_payload',
        'transaction_id',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'line_number' => 'integer',
        'used_on' => 'date',
        'posted_on' => 'date',
        'amount' => 'integer',
        'is_active' => 'boolean',
        'raw_payload' => 'json:unicode',
        'reviewed_at' => 'datetime',
    ];

    public function statement(): BelongsTo
    {
        return $this->belongsTo(CreditCardStatement::class, 'credit_card_statement_id');
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(CreditCardImportBatch::class, 'credit_card_import_batch_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    public function isRegistered(): bool
    {
        return $this->status === self::STATUS_REGISTERED;
    }

    public function isResolved(): bool
    {
        return in_array($this->status, [
            self::STATUS_REGISTERED,
            self::STATUS_PRIVATE,
            self::STATUS_DUPLICATE,
            self::STATUS_IGNORED,
        ], true);
    }

    public function isReviewPending(): bool
    {
        return $this->status === self::STATUS_UNREVIEWED;
    }
}
