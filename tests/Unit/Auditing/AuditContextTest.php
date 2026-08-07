<?php

namespace Tests\Unit\Auditing;

use App\Auditing\AuditContext;
use App\Auditing\AuditEvent;
use App\Auditing\Exceptions\AuditingContractViolation;
use RuntimeException;

class AuditContextTest extends AuditContextTestBase
{
    public function test_depth_is_zero_outside_any_scope(): void
    {
        $this->assertSame(0, AuditContext::depth());
    }

    public function test_within_pops_the_frame_on_normal_completion(): void
    {
        AuditContext::within(AuditEvent::TransactionCreated, function () {
            $this->assertSame(1, AuditContext::depth());
            AuditContext::registerRecord();
        });

        $this->assertSame(0, AuditContext::depth());
    }

    public function test_within_throws_when_no_record_was_registered(): void
    {
        $this->expectException(AuditingContractViolation::class);
        $this->expectExceptionMessageMatches('/transaction\.created/');
        $this->expectExceptionMessageMatches('/record\(\) 0 件/');

        AuditContext::within(AuditEvent::TransactionCreated, function () {
            // 意図的に record() を呼ばない
        });
    }

    public function test_register_record_outside_a_scope_throws(): void
    {
        $this->expectException(AuditingContractViolation::class);
        $this->expectExceptionMessageMatches('/スコープ内で呼ばれる必要/');

        AuditContext::registerRecord();
    }

    public function test_within_returns_the_closure_result(): void
    {
        $result = AuditContext::within(AuditEvent::TransactionCreated, function () {
            AuditContext::registerRecord();

            return 'ok';
        });

        $this->assertSame('ok', $result);
    }

    public function test_within_pops_the_frame_even_when_the_closure_throws(): void
    {
        try {
            AuditContext::within(AuditEvent::TransactionCreated, function () {
                $this->assertSame(1, AuditContext::depth());
                throw new RuntimeException('boom');
            });
            $this->fail('例外が伝播しませんでした');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(0, AuditContext::depth());
    }

    public function test_nested_scopes_are_independent_for_the_record_requirement(): void
    {
        AuditContext::within(AuditEvent::TransactionRevised, function () {
            AuditContext::registerRecord();

            AuditContext::within(AuditEvent::TransactionCreated, function () {
                AuditContext::registerRecord();
                $this->assertSame(2, AuditContext::depth());
            });

            $this->assertSame(1, AuditContext::depth());
        });

        $this->assertSame(0, AuditContext::depth());
    }

    public function test_inner_scope_missing_record_causes_missing_record_exception(): void
    {
        $this->expectException(AuditingContractViolation::class);

        AuditContext::within(AuditEvent::TransactionRevised, function () {
            AuditContext::registerRecord(); // 外側は満たす

            AuditContext::within(AuditEvent::TransactionCreated, function () {
                // 内側は record を呼ばない → 例外
            });
        });
    }

    public function test_register_record_increments_only_the_innermost_frame(): void
    {
        AuditContext::within(AuditEvent::TransactionRevised, function () {
            // 外側のカウントを内側の register が触ってしまうと、
            // 外側は record 未実行のはずが「満たされた」と誤判定される。
            AuditContext::within(AuditEvent::TransactionCreated, function () {
                AuditContext::registerRecord();
            });

            // 外側は register を呼んでいないので、この時点で pop されると
            // MissingRecord 例外になるはず
            AuditContext::registerRecord();
        });

        $this->assertSame(0, AuditContext::depth());
    }

    public function test_exception_thrown_from_inner_scope_still_pops_all_frames(): void
    {
        try {
            AuditContext::within(AuditEvent::TransactionRevised, function () {
                AuditContext::within(AuditEvent::TransactionCreated, function () {
                    throw new RuntimeException('inner boom');
                });
            });
            $this->fail('例外が伝播しませんでした');
        } catch (RuntimeException $e) {
            $this->assertSame('inner boom', $e->getMessage());
        }

        $this->assertSame(0, AuditContext::depth());
    }
}
