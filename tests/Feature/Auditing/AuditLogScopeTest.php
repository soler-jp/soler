<?php

namespace Tests\Feature\Auditing;

use App\Auditing\AuditContext;
use App\Auditing\AuditEvent;
use App\Auditing\AuditTarget;
use App\Auditing\AuditTargetRole;
use App\Models\AuditLog;
use App\Models\AuditLogTarget;
use App\Models\BusinessUnit;
use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditLogScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_for_business_unit_returns_only_logs_of_that_business_unit(): void
    {
        $bu1 = BusinessUnit::factory()->create();
        $bu2 = BusinessUnit::factory()->create();

        $this->log($bu1);
        $this->log($bu1);
        $this->log($bu2);

        $this->assertSame(2, AuditLog::query()->forBusinessUnit($bu1)->count());
        $this->assertSame(1, AuditLog::query()->forBusinessUnit($bu2)->count());
    }

    public function test_for_auditable_returns_logs_for_the_target_in_descending_time_order(): void
    {
        $bu1 = BusinessUnit::factory()->create();
        $bu2 = BusinessUnit::factory()->create();

        $first = $this->log($bu1);
        $second = $this->log($bu1);
        $this->log($bu2);

        $logs = AuditLog::query()->forAuditable($bu1)->get();

        $this->assertCount(2, $logs);
        $this->assertSame($second->getKey(), $logs[0]->getKey());
        $this->assertSame($first->getKey(), $logs[1]->getKey());
    }

    public function test_for_auditable_isolates_between_business_units_even_for_same_id(): void
    {
        // 異なる BU に同じ auditable_id が偶然一致するケース。
        // scope は audit_log_targets.business_unit_id で絞り込むので混ざらない。
        $bu1 = BusinessUnit::factory()->create();
        $bu2 = BusinessUnit::factory()->create();

        $this->log($bu1);
        $this->log($bu2);

        $logs = AuditLog::query()->forAuditable($bu1)->get();

        $this->assertCount(1, $logs);
        $this->assertSame($bu1->getKey(), $logs[0]->business_unit_id);
    }

    public function test_for_auditable_returns_each_log_only_once_even_when_the_same_resource_has_multiple_target_roles(): void
    {
        // 同一リソースを 2 つの role で持つイベント (revised で subject / source が
        // 同じ Transaction を指すケースなど) では、単純 join だとログが重複する。
        // whereExists サブクエリで audit_log_id 単位に絞り込まれていること。
        $bu = BusinessUnit::factory()->create();
        $logger = app(AuditLogger::class);

        DB::transaction(function () use ($logger, $bu) {
            AuditContext::within(AuditEvent::TransactionRevised, function () use ($logger, $bu) {
                $logger->record(
                    event: AuditEvent::TransactionRevised,
                    targets: [
                        new AuditTarget(AuditTargetRole::Subject, $bu),
                        new AuditTarget(AuditTargetRole::Source, $bu),
                        new AuditTarget(AuditTargetRole::Affected, $bu),
                    ],
                    actor: $bu->user,
                );
            });
        });

        // audit_log_targets は 3 行あるが、監査ログとしては 1 件
        $this->assertSame(3, AuditLogTarget::query()->count());
        $this->assertSame(1, AuditLog::query()->count());

        $logs = AuditLog::query()->forAuditable($bu)->get();
        $this->assertCount(1, $logs, 'forAuditable() が重複行を返しています');
    }

    public function test_for_fiscal_year_returns_logs_for_transactions_in_that_fiscal_year_regardless_of_recorded_at(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults(['name' => '監査ログ対象']);
        $currentFiscalYear = $businessUnit->createFiscalYear(2025, $user);
        $otherFiscalYear = $businessUnit->createFiscalYear(2024, $user);

        $currentTransaction = Transaction::factory()->create([
            'fiscal_year_id' => $currentFiscalYear->id,
            'created_by' => $user->id,
        ]);
        $otherTransaction = Transaction::factory()->create([
            'fiscal_year_id' => $otherFiscalYear->id,
            'created_by' => $user->id,
        ]);

        $visible = $this->logForTarget($businessUnit, $currentTransaction, '2026-08-07 12:00:00');
        $this->logForTarget($businessUnit, $otherTransaction, '2026-08-07 12:05:00');
        $this->logForTarget($businessUnit, $currentFiscalYear, '2026-08-07 12:10:00');

        $logs = AuditLog::query()->forFiscalYear($currentFiscalYear)->get();

        $this->assertCount(2, $logs);
        $this->assertTrue($logs->contains(fn (AuditLog $log): bool => $log->is($visible)));
        $this->assertTrue($logs->contains(fn (AuditLog $log): bool => $log->targets->contains(
            fn (AuditLogTarget $target): bool => $target->auditable_type === $currentFiscalYear->getMorphClass()
                && $target->auditable_id === (string) $currentFiscalYear->id
        )));
    }

    private function log(BusinessUnit $bu): AuditLog
    {
        $logger = app(AuditLogger::class);

        $log = null;
        DB::transaction(function () use ($logger, $bu, &$log) {
            AuditContext::within(AuditEvent::TransactionDeactivated, function () use ($logger, $bu, &$log) {
                $log = $logger->record(
                    event: AuditEvent::TransactionDeactivated,
                    targets: [new AuditTarget(AuditTargetRole::Subject, $bu)],
                    actor: $bu->user,
                );
            });
        });

        // sqlite の datetime 精度でも順序が確定するよう分ける
        usleep(2000);

        return $log;
    }

    private function logForTarget(BusinessUnit $businessUnit, BusinessUnit|FiscalYear|Transaction $target, string $recordedAt): AuditLog
    {
        $log = new AuditLog;
        $log->forceFill([
            'business_unit_id' => $businessUnit->id,
            'event_type' => AuditEvent::TransactionDeactivated->value,
            'actor_id' => $businessUnit->user->id,
            'actor_label' => $businessUnit->user->name,
            'reason' => 'scope test',
            'payload_version' => 1,
            'changes' => null,
            'context' => null,
            'recorded_at' => $recordedAt,
        ])->save();

        $auditTarget = new AuditLogTarget;
        $auditTarget->forceFill([
            'audit_log_id' => $log->id,
            'business_unit_id' => $businessUnit->id,
            'role' => AuditTargetRole::Subject->value,
            'auditable_type' => $target->getMorphClass(),
            'auditable_id' => (string) $target->getKey(),
            'recorded_at' => $recordedAt,
        ])->save();

        return $log->fresh('targets');
    }
}
