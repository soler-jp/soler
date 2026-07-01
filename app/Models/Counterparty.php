<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class Counterparty extends Model
{
    use HasFactory;

    public const QUALIFICATION_STATUS_UNKNOWN = 'unknown';

    public const QUALIFICATION_STATUS_QUALIFIED = 'qualified';

    public const QUALIFICATION_STATUS_NON_QUALIFIED = 'non_qualified';

    public const QUALIFICATION_STATUSES = [
        self::QUALIFICATION_STATUS_UNKNOWN,
        self::QUALIFICATION_STATUS_QUALIFIED,
        self::QUALIFICATION_STATUS_NON_QUALIFIED,
    ];

    public const MUTABLE_QUALIFICATION_STATUSES = [
        self::QUALIFICATION_STATUS_QUALIFIED,
        self::QUALIFICATION_STATUS_NON_QUALIFIED,
    ];

    protected $fillable = [
        'business_unit_id',
        'name',
        'registration_number',
        'qualification_status',
        'notes',
    ];

    protected $attributes = [
        'qualification_status' => self::QUALIFICATION_STATUS_UNKNOWN,
    ];

    protected static function booted(): void
    {
        static::created(function (Counterparty $counterparty): void {
            $counterparty->recordQualificationEvent();
        });

        static::updated(function (Counterparty $counterparty): void {
            if ($counterparty->wasChanged('qualification_status')) {
                $counterparty->recordQualificationEvent();
            }
        });
    }

    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    public function qualificationEvents(): HasMany
    {
        return $this->hasMany(CounterpartyQualificationEvent::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function setQualificationStatus(string $qualificationStatus, ?Carbon $effectiveFrom = null): void
    {
        if (! in_array($qualificationStatus, self::MUTABLE_QUALIFICATION_STATUSES, true)) {
            throw new InvalidArgumentException('unknown には変更できません。');
        }

        $this->forceFill([
            'qualification_status' => $qualificationStatus,
        ]);

        if ($effectiveFrom === null) {
            $this->save();

            return;
        }

        $this->saveQuietly();
        $this->recordQualificationEvent($effectiveFrom);
    }

    public function qualificationStatusAt(Carbon $date): string
    {
        $firstKnownEvent = $this->qualificationEvents()
            ->where('qualification_status', '!=', self::QUALIFICATION_STATUS_UNKNOWN)
            ->orderBy('effective_from')
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->first();

        if ($firstKnownEvent === null) {
            return self::QUALIFICATION_STATUS_UNKNOWN;
        }

        $event = $this->qualificationEvents()
            ->where('qualification_status', '!=', self::QUALIFICATION_STATUS_UNKNOWN)
            ->where('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();

        return $event?->qualification_status ?? $firstKnownEvent->qualification_status;
    }

    protected function recordQualificationEvent(?Carbon $effectiveFrom = null): void
    {
        $now = now();

        $this->qualificationEvents()->create([
            'qualification_status' => $this->qualification_status,
            'effective_from' => $effectiveFrom ?? $now,
            'recorded_at' => $now,
        ]);
    }
}
