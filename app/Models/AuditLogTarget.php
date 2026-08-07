<?php

namespace App\Models;

use App\Auditing\AuditTargetRole;
use App\Contracts\ResolvesBusinessUnit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

class AuditLogTarget extends Model implements ResolvesBusinessUnit
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'role' => AuditTargetRole::class,
        'recorded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (AuditLogTarget $target): void {
            throw new RuntimeException('AuditLogTarget は追記専用です。update() は許可されていません。');
        });

        static::deleting(function (AuditLogTarget $target): void {
            throw new RuntimeException('AuditLogTarget は追記専用です。delete() は許可されていません。');
        });
    }

    public function auditLog(): BelongsTo
    {
        return $this->belongsTo(AuditLog::class);
    }

    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function resolveBusinessUnit(): BusinessUnit
    {
        $this->loadMissing('businessUnit');

        return $this->businessUnit;
    }
}
