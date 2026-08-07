<?php

namespace App\Models;

use App\Auditing\AuditEvent;
use App\Contracts\ResolvesBusinessUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AuditLog extends Model implements ResolvesBusinessUnit
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'event_type' => AuditEvent::class,
        'payload_version' => 'integer',
        'changes' => 'array',
        'context' => 'array',
        'recorded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (AuditLog $log): void {
            throw new RuntimeException('AuditLog は追記専用です。update() は許可されていません。');
        });

        static::deleting(function (AuditLog $log): void {
            throw new RuntimeException('AuditLog は追記専用です。delete() は許可されていません。');
        });
    }

    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(AuditLogTarget::class);
    }

    public function resolveBusinessUnit(): BusinessUnit
    {
        $this->loadMissing('businessUnit');

        return $this->businessUnit;
    }

    public function scopeForBusinessUnit(Builder $query, BusinessUnit $businessUnit): Builder
    {
        return $query->where('business_unit_id', $businessUnit->getKey());
    }

    /**
     * 特定リソースの監査ログを新しい順に取得する。
     *
     * 同一リソースに複数 role の target が付くイベント (例: 同じ Transaction を
     * subject と affected の両方に載せるケース) では、単純 join だと監査ログが
     * 重複行として返るため、whereExists のサブクエリで audit_log_id 集合を
     * 絞り込む。並び替えは冗長列 audit_logs.recorded_at で行い、
     * 子テーブルのインデックスは exists 判定側で利用される。
     */
    public function scopeForAuditable(Builder $query, Model&ResolvesBusinessUnit $auditable): Builder
    {
        $businessUnitId = $auditable->resolveBusinessUnit()->getKey();
        $auditableType = $auditable->getMorphClass();
        $auditableId = (string) $auditable->getKey();

        return $query
            ->whereExists(function ($sub) use ($businessUnitId, $auditableType, $auditableId) {
                $sub->select(DB::raw(1))
                    ->from('audit_log_targets')
                    ->whereColumn('audit_log_targets.audit_log_id', 'audit_logs.id')
                    ->where('audit_log_targets.business_unit_id', $businessUnitId)
                    ->where('audit_log_targets.auditable_type', $auditableType)
                    ->where('audit_log_targets.auditable_id', $auditableId);
            })
            ->orderByDesc('audit_logs.recorded_at')
            ->orderByDesc('audit_logs.id');
    }
}
