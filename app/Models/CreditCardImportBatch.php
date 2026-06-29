<?php

namespace App\Models;

use Database\Factories\CreditCardImportBatchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class CreditCardImportBatch extends Model
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PROCESSING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    /** @use HasFactory<CreditCardImportBatchFactory> */
    use HasFactory;

    protected $fillable = [
        'credit_card_statement_id',
        'uploaded_by',
        'source_filename',
        'source_hash',
        'parser_key',
        'status',
        'is_active',
        'row_count',
        'success_count',
        'duplicate_count',
        'error_count',
        'imported_at',
        'deactivated_at',
        'deactivated_by',
        'deactivation_reason',
        'error_summary',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'row_count' => 'integer',
        'success_count' => 'integer',
        'duplicate_count' => 'integer',
        'error_count' => 'integer',
        'imported_at' => 'datetime',
        'deactivated_at' => 'datetime',
    ];

    public function statement(): BelongsTo
    {
        return $this->belongsTo(CreditCardStatement::class, 'credit_card_statement_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function deactivator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CreditCardStatementLine::class, 'credit_card_import_batch_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'credit_card_import_batch_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
        ], true);
    }

    public function deactivate(?User $user = null, ?string $reason = null): void
    {
        if (! $this->is_active) {
            return;
        }

        DB::transaction(function () use ($user, $reason) {
            $this->forceFill([
                'is_active' => false,
                'deactivated_at' => now(),
                'deactivated_by' => $user?->id,
                'deactivation_reason' => $reason,
            ])->save();

            $this->lines()->update([
                'is_active' => false,
            ]);

            $this->transactions()->update([
                'is_active' => false,
                'deactivated_at' => now(),
                'deactivated_by' => $user?->id,
                'deactivation_reason' => $reason,
            ]);
        });
    }
}
