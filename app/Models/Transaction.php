<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\FiscalYear;
use App\Models\RecurringTransactionPlan;

class Transaction extends Model
{

    use HasFactory;

    protected $fillable = [
        'fiscal_year_id',
        'date',
        'description',
        'remarks',
        'is_adjusting_entry',
        'is_planned',
        'recurring_transaction_plan_id',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date',
        'is_adjusting_entry' => 'boolean',
        'is_planned' => 'boolean',
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

    /**
     * この取引に属する仕訳明細
     */
    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function recurringTransactionPlan(): BelongsTo
    {
        return $this->belongsTo(RecurringTransactionPlan::class);
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
            if (!$transaction->fiscal_year_id) {
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
}
