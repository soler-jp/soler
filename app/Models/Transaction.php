<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'fiscal_year_id',
        'date',
        'description',
        'remarks',
        'is_opening_entry',
        'is_adjusting_entry',
        'is_planned',
        'recurring_transaction_plan_id',
        'counterparty_id',
        'created_by',
        'credit_card_import_batch_id',
        'is_active',
        'deactivated_at',
        'deactivated_by',
        'deactivation_reason',
    ];

    protected $casts = [
        'date' => 'date',
        'is_adjusting_entry' => 'boolean',
        'is_planned' => 'boolean',
        'is_active' => 'boolean',
        'deactivated_at' => 'datetime',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * 会計年度
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    /**
     * 登録者（nullable）
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deactivator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by');
    }

    public function creditCardImportBatch(): BelongsTo
    {
        return $this->belongsTo(CreditCardImportBatch::class, 'credit_card_import_batch_id');
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }

    /**
     * この取引に属する仕訳明細
     */
    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function depreciationEntries(): HasMany
    {
        return $this->hasMany(DepreciationEntry::class);
    }

    public function creditCardStatementLines(): HasMany
    {
        return $this->hasMany(CreditCardStatementLine::class);
    }

    public function recurringTransactionPlan(): BelongsTo
    {
        return $this->belongsTo(RecurringTransactionPlan::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    /**
     * 表示用の伝票番号（例: 2025-0032）
     */
    public function getDisplayNumberAttribute(): string
    {
        $year = $this->fiscalYear->year ?? '----';
        $number = str_pad($this->entry_number ?? 0, 4, '0', STR_PAD_LEFT);

        return "{$year}-{$number}";
    }

    /**
     * モデルイベント：作成時に連番を自動採番
     */
    protected static function booted(): void
    {
        static::creating(function (Transaction $transaction) {
            if (! $transaction->fiscal_year_id) {
                throw new \Exception('fiscal_year_id は必須です');
            }

            // 同一年度内で最大番号＋1を採番（排他制御）
            $max = DB::table('transactions')
                ->where('fiscal_year_id', $transaction->fiscal_year_id)
                ->lockForUpdate()
                ->max('entry_number');

            $transaction->entry_number = ($max ?? 0) + 1;
        });
    }

    public function getTotalAmountAttribute(): int
    {
        return $this->journalEntries->where('type', 'credit')->sum('net_amount');
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
        });
    }

    public function getCreditAccountsLabelAttribute(): string
    {
        return $this->journalEntries
            ->where('type', 'credit')
            ->map(fn ($entry) => $entry->subAccount->account->name.' / '.$entry->subAccount->name)
            ->unique()
            ->implode(', ');
    }
}
