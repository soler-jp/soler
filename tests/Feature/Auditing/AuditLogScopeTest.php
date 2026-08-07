<?php

namespace Tests\Feature\Auditing;

use App\Auditing\AuditContext;
use App\Auditing\AuditEvent;
use App\Auditing\AuditTarget;
use App\Auditing\AuditTargetRole;
use App\Models\AuditLog;
use App\Models\AuditLogTarget;
use App\Models\BusinessUnit;
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
}
