<?php

namespace Tests\Feature\Auditing;

use App\Auditing\AuditChanges;
use App\Auditing\AuditContext;
use App\Auditing\AuditEvent;
use App\Auditing\AuditTarget;
use App\Auditing\AuditTargetRole;
use App\Auditing\Exceptions\AuditingContractViolation;
use App\Models\AuditLog;
use App\Models\AuditLogTarget;
use App\Models\BusinessUnit;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use ReflectionClass;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        try {
            $reflection = new ReflectionClass(AuditContext::class);
            $property = $reflection->getProperty('stack');
            $property->setValue(null, []);
        } finally {
            parent::tearDown();
        }
    }

    public function test_record_persists_audit_log_and_target(): void
    {
        [$businessUnit, $actor] = $this->makeActorAndBusinessUnit();
        $logger = app(AuditLogger::class);

        $log = null;
        DB::transaction(function () use ($logger, $businessUnit, $actor, &$log) {
            AuditContext::within(AuditEvent::TransactionDeactivated, function () use ($logger, $businessUnit, $actor, &$log) {
                $log = $logger->record(
                    event: AuditEvent::TransactionDeactivated,
                    targets: [new AuditTarget(AuditTargetRole::Subject, $businessUnit)],
                    actor: $actor,
                    reason: '手動削除',
                );
            });
        });

        $this->assertInstanceOf(AuditLog::class, $log);
        $this->assertSame(AuditEvent::TransactionDeactivated, $log->event_type);
        $this->assertSame($businessUnit->getKey(), $log->business_unit_id);
        $this->assertSame($actor->getKey(), $log->actor_id);
        $this->assertSame($actor->name, $log->actor_label);
        $this->assertSame('手動削除', $log->reason);
        $this->assertSame(1, $log->payload_version);
        $this->assertNotNull($log->recorded_at);

        $this->assertSame(1, AuditLogTarget::query()->where('audit_log_id', $log->getKey())->count());
        $target = AuditLogTarget::query()->where('audit_log_id', $log->getKey())->firstOrFail();
        $this->assertSame(AuditTargetRole::Subject, $target->role);
        $this->assertSame(BusinessUnit::class, $target->auditable_type);
        $this->assertSame((string) $businessUnit->getKey(), $target->auditable_id);
    }

    public function test_record_rejects_call_outside_db_transaction(): void
    {
        [$businessUnit, $actor] = $this->makeActorAndBusinessUnit();
        $logger = app(AuditLogger::class);

        // RefreshDatabase が張る自動トランザクションを一時解除し、level 0 の状態を作る
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        try {
            $this->assertSame(0, DB::transactionLevel());

            try {
                AuditContext::within(AuditEvent::TransactionDeactivated, function () use ($logger, $businessUnit, $actor) {
                    $logger->record(
                        event: AuditEvent::TransactionDeactivated,
                        targets: [new AuditTarget(AuditTargetRole::Subject, $businessUnit)],
                        actor: $actor,
                    );
                });
                $this->fail('DB トランザクション外の record() が例外にならなかった');
            } catch (AuditingContractViolation $e) {
                $this->assertStringContainsString('DB トランザクション内', $e->getMessage());
            }
        } finally {
            // RefreshDatabase の tearDown 側 rollback が壊れないよう、レベルを復帰させる
            DB::beginTransaction();
        }
    }

    public function test_record_rejects_call_outside_audit_context(): void
    {
        [$businessUnit, $actor] = $this->makeActorAndBusinessUnit();
        $logger = app(AuditLogger::class);

        $this->expectException(AuditingContractViolation::class);
        $this->expectExceptionMessageMatches('/スコープ内で呼ばれる必要/');

        DB::transaction(function () use ($logger, $businessUnit, $actor) {
            $logger->record(
                event: AuditEvent::TransactionDeactivated,
                targets: [new AuditTarget(AuditTargetRole::Subject, $businessUnit)],
                actor: $actor,
            );
        });
    }

    public function test_record_rejects_empty_targets(): void
    {
        [, $actor] = $this->makeActorAndBusinessUnit();
        $logger = app(AuditLogger::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('最低 1 件の AuditTarget が必要');

        DB::transaction(function () use ($logger, $actor) {
            AuditContext::within(AuditEvent::TransactionDeactivated, function () use ($logger, $actor) {
                $logger->record(
                    event: AuditEvent::TransactionDeactivated,
                    targets: [],
                    actor: $actor,
                );
            });
        });
    }

    public function test_record_rejects_targets_without_subject_role(): void
    {
        [$businessUnit, $actor] = $this->makeActorAndBusinessUnit();
        $logger = app(AuditLogger::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('subject role が必要');

        DB::transaction(function () use ($logger, $businessUnit, $actor) {
            AuditContext::within(AuditEvent::TransactionDeactivated, function () use ($logger, $businessUnit, $actor) {
                $logger->record(
                    event: AuditEvent::TransactionDeactivated,
                    targets: [new AuditTarget(AuditTargetRole::Source, $businessUnit)],
                    actor: $actor,
                );
            });
        });
    }

    public function test_record_rejects_targets_from_different_business_units(): void
    {
        $bu1 = BusinessUnit::factory()->create();
        $bu2 = BusinessUnit::factory()->create();
        $actor = $bu1->user;
        $logger = app(AuditLogger::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('同一 BusinessUnit');

        DB::transaction(function () use ($logger, $bu1, $bu2, $actor) {
            AuditContext::within(AuditEvent::TransactionRevised, function () use ($logger, $bu1, $bu2, $actor) {
                $logger->record(
                    event: AuditEvent::TransactionRevised,
                    targets: [
                        new AuditTarget(AuditTargetRole::Subject, $bu1),
                        new AuditTarget(AuditTargetRole::Source, $bu2),
                    ],
                    actor: $actor,
                );
            });
        });
    }

    public function test_record_writes_null_actor_label_when_actor_is_null(): void
    {
        [$businessUnit] = $this->makeActorAndBusinessUnit();
        $logger = app(AuditLogger::class);

        $log = null;
        DB::transaction(function () use ($logger, $businessUnit, &$log) {
            AuditContext::within(AuditEvent::TransactionDeactivated, function () use ($logger, $businessUnit, &$log) {
                $log = $logger->record(
                    event: AuditEvent::TransactionDeactivated,
                    targets: [new AuditTarget(AuditTargetRole::Subject, $businessUnit)],
                    actor: null,
                );
            });
        });

        $this->assertNull($log->actor_id);
        $this->assertNull($log->actor_label);
    }

    public function test_record_truncates_long_actor_label_with_ellipsis(): void
    {
        $businessUnit = BusinessUnit::factory()->create();
        $longName = str_repeat('あ', 150);
        $actor = User::factory()->create(['name' => $longName]);
        $logger = app(AuditLogger::class);

        $log = null;
        DB::transaction(function () use ($logger, $businessUnit, $actor, &$log) {
            AuditContext::within(AuditEvent::TransactionDeactivated, function () use ($logger, $businessUnit, $actor, &$log) {
                $log = $logger->record(
                    event: AuditEvent::TransactionDeactivated,
                    targets: [new AuditTarget(AuditTargetRole::Subject, $businessUnit)],
                    actor: $actor,
                );
            });
        });

        $this->assertSame(AuditLogger::ACTOR_LABEL_MAX_LENGTH, mb_strlen($log->actor_label));
        $this->assertStringEndsWith('…', $log->actor_label);
    }

    public function test_record_normalizes_empty_actor_name_to_null(): void
    {
        $businessUnit = BusinessUnit::factory()->create();
        $actor = User::factory()->create(['name' => '   ']);
        $logger = app(AuditLogger::class);

        $log = null;
        DB::transaction(function () use ($logger, $businessUnit, $actor, &$log) {
            AuditContext::within(AuditEvent::TransactionDeactivated, function () use ($logger, $businessUnit, $actor, &$log) {
                $log = $logger->record(
                    event: AuditEvent::TransactionDeactivated,
                    targets: [new AuditTarget(AuditTargetRole::Subject, $businessUnit)],
                    actor: $actor,
                );
            });
        });

        $this->assertNull($log->actor_label);
        $this->assertSame($actor->getKey(), $log->actor_id);
    }

    public function test_record_normalizes_empty_reason_to_null(): void
    {
        [$businessUnit, $actor] = $this->makeActorAndBusinessUnit();
        $logger = app(AuditLogger::class);

        $log = null;
        DB::transaction(function () use ($logger, $businessUnit, $actor, &$log) {
            AuditContext::within(AuditEvent::TransactionDeactivated, function () use ($logger, $businessUnit, $actor, &$log) {
                $log = $logger->record(
                    event: AuditEvent::TransactionDeactivated,
                    targets: [new AuditTarget(AuditTargetRole::Subject, $businessUnit)],
                    actor: $actor,
                    reason: '   ',
                );
            });
        });

        $this->assertNull($log->reason);
    }

    public function test_record_rejects_changes_larger_than_64kb(): void
    {
        [$businessUnit, $actor] = $this->makeActorAndBusinessUnit();
        $logger = app(AuditLogger::class);

        $huge = str_repeat('a', 70_000);
        $changes = new AuditChanges(subject: ['description' => [null, $huge]]);

        $this->expectException(AuditingContractViolation::class);
        $this->expectExceptionMessageMatches('/バイトを超え/');

        DB::transaction(function () use ($logger, $businessUnit, $actor, $changes) {
            AuditContext::within(AuditEvent::TransactionCreated, function () use ($logger, $businessUnit, $actor, $changes) {
                $logger->record(
                    event: AuditEvent::TransactionCreated,
                    targets: [new AuditTarget(AuditTargetRole::Subject, $businessUnit)],
                    actor: $actor,
                    changes: $changes,
                );
            });
        });
    }

    public function test_record_accepts_changes_at_the_boundary_of_64kb(): void
    {
        [$businessUnit, $actor] = $this->makeActorAndBusinessUnit();
        $logger = app(AuditLogger::class);

        // subject + related のオーバーヘッド分を差し引いて hard cap ちょうどになるよう調整
        // JSON: {"subject":{"description":[null,"..."]},"related":{}} → 固定分 47 bytes
        $overhead = strlen('{"subject":{"description":[null,""]},"related":{}}');
        $payloadSize = AuditLogger::CHANGES_MAX_BYTES - $overhead;
        $payload = str_repeat('a', $payloadSize);

        $changes = new AuditChanges(subject: ['description' => [null, $payload]]);

        DB::transaction(function () use ($logger, $businessUnit, $actor, $changes) {
            AuditContext::within(AuditEvent::TransactionCreated, function () use ($logger, $businessUnit, $actor, $changes) {
                $logger->record(
                    event: AuditEvent::TransactionCreated,
                    targets: [new AuditTarget(AuditTargetRole::Subject, $businessUnit)],
                    actor: $actor,
                    changes: $changes,
                );
            });
        });

        $log = AuditLog::query()->latest('id')->firstOrFail();
        $this->assertNotNull($log->changes);
    }

    public function test_record_writes_empty_changes_as_null(): void
    {
        [$businessUnit, $actor] = $this->makeActorAndBusinessUnit();
        $logger = app(AuditLogger::class);

        $log = null;
        DB::transaction(function () use ($logger, $businessUnit, $actor, &$log) {
            AuditContext::within(AuditEvent::TransactionDeactivated, function () use ($logger, $businessUnit, $actor, &$log) {
                $log = $logger->record(
                    event: AuditEvent::TransactionDeactivated,
                    targets: [new AuditTarget(AuditTargetRole::Subject, $businessUnit)],
                    actor: $actor,
                );
            });
        });

        $this->assertNull($log->changes);
    }

    public function test_record_writes_context_as_null_when_empty(): void
    {
        [$businessUnit, $actor] = $this->makeActorAndBusinessUnit();
        $logger = app(AuditLogger::class);

        $log = null;
        DB::transaction(function () use ($logger, $businessUnit, $actor, &$log) {
            AuditContext::within(AuditEvent::TransactionDeactivated, function () use ($logger, $businessUnit, $actor, &$log) {
                $log = $logger->record(
                    event: AuditEvent::TransactionDeactivated,
                    targets: [new AuditTarget(AuditTargetRole::Subject, $businessUnit)],
                    actor: $actor,
                );
            });
        });

        $this->assertNull($log->context);
    }

    public function test_record_persists_context_when_provided(): void
    {
        [$businessUnit, $actor] = $this->makeActorAndBusinessUnit();
        $logger = app(AuditLogger::class);

        $log = null;
        DB::transaction(function () use ($logger, $businessUnit, $actor, &$log) {
            AuditContext::within(AuditEvent::TransactionDeactivated, function () use ($logger, $businessUnit, $actor, &$log) {
                $log = $logger->record(
                    event: AuditEvent::TransactionDeactivated,
                    targets: [new AuditTarget(AuditTargetRole::Subject, $businessUnit)],
                    actor: $actor,
                    context: ['ip' => '203.0.113.1', 'ua' => 'phpunit'],
                );
            });
        });

        $log->refresh();
        $this->assertSame(['ip' => '203.0.113.1', 'ua' => 'phpunit'], $log->context);
    }

    public function test_record_increments_the_current_audit_context_frame(): void
    {
        [$businessUnit, $actor] = $this->makeActorAndBusinessUnit();
        $logger = app(AuditLogger::class);

        // within() が record 0 件で例外にならないことで、間接的に increment を検証
        DB::transaction(function () use ($logger, $businessUnit, $actor) {
            AuditContext::within(AuditEvent::TransactionDeactivated, function () use ($logger, $businessUnit, $actor) {
                $logger->record(
                    event: AuditEvent::TransactionDeactivated,
                    targets: [new AuditTarget(AuditTargetRole::Subject, $businessUnit)],
                    actor: $actor,
                );
            });
        });

        $this->assertSame(1, AuditLog::query()->count());
    }

    public function test_parent_and_target_share_the_same_recorded_at(): void
    {
        [$businessUnit, $actor] = $this->makeActorAndBusinessUnit();
        $logger = app(AuditLogger::class);

        $log = null;
        DB::transaction(function () use ($logger, $businessUnit, $actor, &$log) {
            AuditContext::within(AuditEvent::TransactionDeactivated, function () use ($logger, $businessUnit, $actor, &$log) {
                $log = $logger->record(
                    event: AuditEvent::TransactionDeactivated,
                    targets: [new AuditTarget(AuditTargetRole::Subject, $businessUnit)],
                    actor: $actor,
                );
            });
        });

        $log->refresh();
        $target = AuditLogTarget::query()->where('audit_log_id', $log->getKey())->firstOrFail();

        $this->assertEquals($log->recorded_at->format('Y-m-d H:i:s.u'), $target->recorded_at->format('Y-m-d H:i:s.u'));
        $this->assertSame($log->business_unit_id, $target->business_unit_id);
    }

    public function test_record_writes_auditable_id_as_string_regardless_of_key_type(): void
    {
        [$businessUnit, $actor] = $this->makeActorAndBusinessUnit();
        $logger = app(AuditLogger::class);

        DB::transaction(function () use ($logger, $businessUnit, $actor) {
            AuditContext::within(AuditEvent::TransactionDeactivated, function () use ($logger, $businessUnit, $actor) {
                $logger->record(
                    event: AuditEvent::TransactionDeactivated,
                    targets: [new AuditTarget(AuditTargetRole::Subject, $businessUnit)],
                    actor: $actor,
                );
            });
        });

        $target = AuditLogTarget::query()->firstOrFail();

        // カラム上は文字列で書き込まれていること
        $this->assertIsString($target->getRawOriginal('auditable_id'));
        $this->assertSame((string) $businessUnit->getKey(), $target->getRawOriginal('auditable_id'));
    }

    public function test_scope_still_fails_when_record_throws_and_caller_swallows_the_exception(): void
    {
        // record() が targets 引数不正で失敗し、呼び出し側がその例外を握り潰しても、
        // AuditContext は 1 件も永続化されていないことを検出して MissingRecord で
        // 失敗しなければならない。カウントを write 後にのみ進める契約の回帰テスト。
        [$bu, $actor] = $this->makeActorAndBusinessUnit();
        $logger = app(AuditLogger::class);

        $this->expectException(AuditingContractViolation::class);
        $this->expectExceptionMessageMatches('/record\(\) 0 件/');

        DB::transaction(function () use ($logger, $actor) {
            AuditContext::within(AuditEvent::TransactionDeactivated, function () use ($logger, $actor) {
                try {
                    $logger->record(
                        event: AuditEvent::TransactionDeactivated,
                        targets: [], // subject 欠で InvalidArgumentException
                        actor: $actor,
                    );
                } catch (InvalidArgumentException) {
                    // 呼び出し側が握り潰したケースを模す
                }
                // ここで within() が閉じるが、実際は 1 件も persist されていないので
                // MissingRecord で失敗するはず
            });
        });

        // 念のため、テーブルも空
        $this->assertSame(0, AuditLog::query()->count());
        $this->assertSame(0, AuditLogTarget::query()->count());
    }

    public function test_auditable_type_uses_morph_alias_when_registered(): void
    {
        // getMorphClass() 経由で書き込むことで、morph map が設定された場合は alias が使われる。
        // これによりクラス名変更に対して既存ログとの整合が保てる。
        $original = Relation::morphMap();
        try {
            Relation::morphMap(['business_unit' => BusinessUnit::class], false);

            [$bu, $actor] = $this->makeActorAndBusinessUnit();

            DB::transaction(function () use ($bu, $actor) {
                AuditContext::within(AuditEvent::TransactionDeactivated, function () use ($bu, $actor) {
                    app(AuditLogger::class)->record(
                        event: AuditEvent::TransactionDeactivated,
                        targets: [new AuditTarget(AuditTargetRole::Subject, $bu)],
                        actor: $actor,
                    );
                });
            });

            $target = AuditLogTarget::query()->firstOrFail();
            $this->assertSame('business_unit', $target->getRawOriginal('auditable_type'));

            // 同じ alias で forAuditable() が引けること
            $logs = AuditLog::query()->forAuditable($bu)->get();
            $this->assertCount(1, $logs);
        } finally {
            Relation::morphMap($original, false);
        }
    }

    public function test_record_writes_all_targets_from_a_compound_operation(): void
    {
        [$businessUnit, $actor] = $this->makeActorAndBusinessUnit();
        // 同じ BU 内の 2 つのリソースを模す
        $another = BusinessUnit::factory()->create(['user_id' => $businessUnit->user_id]);
        // 同じ BU にしたいので resolveBusinessUnit の一致を保つよう $businessUnit の複製を渡す
        $logger = app(AuditLogger::class);

        $log = null;
        DB::transaction(function () use ($logger, $businessUnit, $actor, &$log) {
            AuditContext::within(AuditEvent::TransactionRevised, function () use ($logger, $businessUnit, $actor, &$log) {
                $log = $logger->record(
                    event: AuditEvent::TransactionRevised,
                    targets: [
                        new AuditTarget(AuditTargetRole::Subject, $businessUnit),
                        new AuditTarget(AuditTargetRole::Source, $businessUnit),
                    ],
                    actor: $actor,
                    reason: '改訂',
                );
            });
        });

        $targets = AuditLogTarget::query()->where('audit_log_id', $log->getKey())->orderBy('id')->get();
        $this->assertCount(2, $targets);
        $this->assertSame(AuditTargetRole::Subject, $targets[0]->role);
        $this->assertSame(AuditTargetRole::Source, $targets[1]->role);
    }

    /**
     * @return array{0: BusinessUnit, 1: User}
     */
    private function makeActorAndBusinessUnit(): array
    {
        $bu = BusinessUnit::factory()->create();

        return [$bu, $bu->user];
    }
}
