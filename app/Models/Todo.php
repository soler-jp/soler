<?php

namespace App\Models;

use App\Contracts\ResolvesBusinessUnit;
use App\Contracts\TodoHandler;
use Carbon\CarbonInterface;
use Database\Factories\TodoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Todo extends Model implements ResolvesBusinessUnit
{
    /** @use HasFactory<TodoFactory> */
    use HasFactory;

    public const SOURCE_TYPE_MANUAL = 'manual';

    public const SOURCE_TYPE_RECURRING = 'recurring';

    public const SOURCE_TYPE_SYSTEM = 'system';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_LOW = 'low';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_DISMISSED = 'dismissed';

    public const SOURCE_TYPES = [
        self::SOURCE_TYPE_MANUAL,
        self::SOURCE_TYPE_RECURRING,
        self::SOURCE_TYPE_SYSTEM,
    ];

    public const PRIORITIES = [
        self::PRIORITY_HIGH,
        self::PRIORITY_NORMAL,
        self::PRIORITY_LOW,
    ];

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_COMPLETED,
        self::STATUS_DISMISSED,
    ];

    /**
     * @var array<string, array<int, class-string>>
     */
    public static array $allowedSourceModels = [
        self::SOURCE_TYPE_MANUAL => [],
        self::SOURCE_TYPE_RECURRING => [RecurringTransactionPlan::class],
        self::SOURCE_TYPE_SYSTEM => [],
    ];

    /**
     * @var array<string, class-string<TodoHandler>>
     */
    public static array $handlers = [];

    protected $fillable = [
        'business_unit_id',
        'fiscal_year_id',
        'source_type',
        'source_model_type',
        'source_model_id',
        'todo_type',
        'title',
        'body',
        'due_on',
        'priority',
        'status',
        'completed_at',
        'dismissed_at',
    ];

    protected $casts = [
        'due_on' => 'date',
        'completed_at' => 'datetime',
        'dismissed_at' => 'datetime',
    ];

    public function handler(): ?TodoHandler
    {
        if ($this->todo_type === null) {
            return null;
        }

        $handlerClass = static::$handlers[$this->todo_type] ?? null;

        return $handlerClass !== null ? app($handlerClass) : null;
    }

    public function isExecutable(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->handler() !== null;
    }

    public function markCompleted(?CarbonInterface $completedAt = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => $completedAt ?? now(),
        ])->save();
    }

    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'source_model_type', 'source_model_id');
    }

    public function resolveBusinessUnit(): BusinessUnit
    {
        $this->loadMissing('businessUnit');

        return $this->businessUnit;
    }
}
