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

/**
 * 親子の business_unit_id / recorded_at 一致は、AuditLogger 経由の通常書き込みだけでなく
 * factory / seeder / 直接 insert など**あらゆる書き込み経路**で守られるモデル契約である。
 *
 * この契約が崩れると、リソース履歴取得クエリ (audit_log_targets 単独) が親の
 * 実データと乖離した結果を返す。冗長化のコストに見合う整合性を確保するため、
 * 経路を跨いだ整合性テストをここでまとめる。
 */
class AuditLogParentChildConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_write_via_audit_logger_keeps_parent_and_target_in_sync(): void
    {
        $bu = BusinessUnit::factory()->create();
        $actor = $bu->user;
        $logger = app(AuditLogger::class);

        DB::transaction(function () use ($logger, $bu, $actor) {
            AuditContext::within(AuditEvent::TransactionDeactivated, function () use ($logger, $bu, $actor) {
                $logger->record(
                    event: AuditEvent::TransactionDeactivated,
                    targets: [new AuditTarget(AuditTargetRole::Subject, $bu)],
                    actor: $actor,
                );
            });
        });

        $this->assertAllTargetsMatchTheirParents();
    }

    public function test_normal_write_with_multiple_targets_keeps_all_in_sync(): void
    {
        $bu = BusinessUnit::factory()->create();
        $actor = $bu->user;
        $logger = app(AuditLogger::class);

        DB::transaction(function () use ($logger, $bu, $actor) {
            AuditContext::within(AuditEvent::TransactionRevised, function () use ($logger, $bu, $actor) {
                $logger->record(
                    event: AuditEvent::TransactionRevised,
                    targets: [
                        new AuditTarget(AuditTargetRole::Subject, $bu),
                        new AuditTarget(AuditTargetRole::Source, $bu),
                        new AuditTarget(AuditTargetRole::Affected, $bu),
                    ],
                    actor: $actor,
                );
            });
        });

        $this->assertAllTargetsMatchTheirParents();
        $this->assertSame(3, AuditLogTarget::query()->count());
    }

    public function test_multiple_sequential_writes_keep_each_pair_consistent(): void
    {
        $bu = BusinessUnit::factory()->create();
        $actor = $bu->user;
        $logger = app(AuditLogger::class);

        for ($i = 0; $i < 5; $i++) {
            DB::transaction(function () use ($logger, $bu, $actor) {
                AuditContext::within(AuditEvent::TransactionDeactivated, function () use ($logger, $bu, $actor) {
                    $logger->record(
                        event: AuditEvent::TransactionDeactivated,
                        targets: [new AuditTarget(AuditTargetRole::Subject, $bu)],
                        actor: $actor,
                    );
                });
            });
        }

        $this->assertSame(5, AuditLog::query()->count());
        $this->assertAllTargetsMatchTheirParents();
    }

    public function test_direct_insert_that_violates_consistency_is_caught_by_this_test(): void
    {
        // 契約を破る書き込み経路を意図的に作り、テストがそれを検出することを保証する。
        // 実装バグでこの整合性が崩れた場合、このテスト自身が回帰する。
        $bu1 = BusinessUnit::factory()->create();
        $bu2 = BusinessUnit::factory()->create();

        $log = new AuditLog;
        $log->forceFill([
            'business_unit_id' => $bu1->getKey(),
            'event_type' => AuditEvent::TransactionDeactivated->value,
            'payload_version' => 1,
            'recorded_at' => now(),
        ])->save();

        $target = new AuditLogTarget;
        $target->forceFill([
            'audit_log_id' => $log->getKey(),
            'business_unit_id' => $bu2->getKey(), // 意図的に不一致
            'role' => AuditTargetRole::Subject->value,
            'auditable_type' => BusinessUnit::class,
            'auditable_id' => (string) $bu1->getKey(),
            'recorded_at' => $log->recorded_at,
        ])->save();

        $violations = $this->findParentChildMismatches();
        $this->assertNotEmpty(
            $violations,
            '整合性違反を作ったのに検出されませんでした。検証クエリ側のバグの可能性があります。',
        );
    }

    public function test_no_violations_exist_in_a_clean_database(): void
    {
        // 何も書き込まれていない状態でも false positive が出ないこと
        $this->assertAllTargetsMatchTheirParents();
    }

    /**
     * @return list<array{audit_log_id: int, target_id: int, parent_bu: mixed, target_bu: mixed, parent_recorded_at: mixed, target_recorded_at: mixed}>
     */
    private function findParentChildMismatches(): array
    {
        $rows = DB::table('audit_log_targets')
            ->join('audit_logs', 'audit_log_targets.audit_log_id', '=', 'audit_logs.id')
            ->select([
                'audit_log_targets.audit_log_id',
                'audit_log_targets.id as target_id',
                'audit_logs.business_unit_id as parent_bu',
                'audit_log_targets.business_unit_id as target_bu',
                'audit_logs.recorded_at as parent_recorded_at',
                'audit_log_targets.recorded_at as target_recorded_at',
            ])
            ->get()
            ->filter(fn ($row) => $row->parent_bu !== $row->target_bu || $row->parent_recorded_at !== $row->target_recorded_at)
            ->values()
            ->toArray();

        return array_map(fn ($row) => (array) $row, $rows);
    }

    private function assertAllTargetsMatchTheirParents(): void
    {
        $violations = $this->findParentChildMismatches();

        $this->assertSame(
            [],
            $violations,
            sprintf(
                '親子で business_unit_id または recorded_at が一致しないレコードがあります: %s',
                json_encode($violations, JSON_UNESCAPED_UNICODE),
            ),
        );
    }
}
