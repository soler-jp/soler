<?php

namespace Tests\Feature\Auditing;

use App\Auditing\AuditEvent;
use App\Auditing\AuditTargetRole;
use App\Models\AuditLog;
use App\Models\AuditLogTarget;
use App\Models\BusinessUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AuditLogModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_is_append_only_and_rejects_update(): void
    {
        $log = $this->createLog();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AuditLog は追記専用です');

        $log->reason = '書き換え';
        $log->save();
    }

    public function test_audit_log_is_append_only_and_rejects_delete(): void
    {
        $log = $this->createLog();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AuditLog は追記専用です');

        $log->delete();
    }

    public function test_audit_log_target_is_append_only_and_rejects_update(): void
    {
        $target = $this->createTarget();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AuditLogTarget は追記専用です');

        $target->role = AuditTargetRole::Source->value;
        $target->save();
    }

    public function test_audit_log_target_is_append_only_and_rejects_delete(): void
    {
        $target = $this->createTarget();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AuditLogTarget は追記専用です');

        $target->delete();
    }

    public function test_audit_log_casts_event_type_to_enum(): void
    {
        $log = $this->createLog();

        $log->refresh();

        $this->assertSame(AuditEvent::TransactionDeactivated, $log->event_type);
    }

    public function test_audit_log_target_casts_role_to_enum(): void
    {
        $target = $this->createTarget();

        $target->refresh();

        $this->assertSame(AuditTargetRole::Subject, $target->role);
    }

    public function test_audit_log_resolves_business_unit(): void
    {
        $log = $this->createLog();

        $this->assertInstanceOf(BusinessUnit::class, $log->resolveBusinessUnit());
        $this->assertSame($log->business_unit_id, $log->resolveBusinessUnit()->getKey());
    }

    public function test_audit_log_target_resolves_business_unit(): void
    {
        $target = $this->createTarget();

        $this->assertInstanceOf(BusinessUnit::class, $target->resolveBusinessUnit());
        $this->assertSame($target->business_unit_id, $target->resolveBusinessUnit()->getKey());
    }

    public function test_audit_log_does_not_have_created_at_or_updated_at_columns(): void
    {
        $log = $this->createLog();

        $this->assertFalse($log->usesTimestamps());
        $this->assertArrayNotHasKey('created_at', $log->getAttributes());
        $this->assertArrayNotHasKey('updated_at', $log->getAttributes());
    }

    private function createLog(): AuditLog
    {
        $businessUnit = BusinessUnit::factory()->create();

        $log = new AuditLog;
        $log->forceFill([
            'business_unit_id' => $businessUnit->getKey(),
            'event_type' => AuditEvent::TransactionDeactivated->value,
            'actor_id' => null,
            'actor_label' => null,
            'reason' => null,
            'payload_version' => 1,
            'changes' => null,
            'context' => null,
            'recorded_at' => now(),
        ])->save();

        return $log->fresh();
    }

    private function createTarget(): AuditLogTarget
    {
        $log = $this->createLog();

        $target = new AuditLogTarget;
        $target->forceFill([
            'audit_log_id' => $log->getKey(),
            'business_unit_id' => $log->business_unit_id,
            'role' => AuditTargetRole::Subject->value,
            'auditable_type' => BusinessUnit::class,
            'auditable_id' => (string) $log->business_unit_id,
            'recorded_at' => $log->recorded_at,
        ])->save();

        return $target->fresh();
    }
}
