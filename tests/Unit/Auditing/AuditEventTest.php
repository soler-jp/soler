<?php

namespace Tests\Unit\Auditing;

use App\Auditing\AuditEvent;
use App\Auditing\AuditTargetRole;
use Tests\TestCase;

class AuditEventTest extends TestCase
{
    public function test_audit_event_values_follow_the_two_segment_naming_convention(): void
    {
        foreach (AuditEvent::cases() as $case) {
            $this->assertMatchesRegularExpression(
                '/^[a-z_]+(\.[a-z_]+)+$/',
                $case->value,
                sprintf('%s の値 %s が命名規約 <resource>.<action> に合いません', $case->name, $case->value),
            );
        }
    }

    public function test_audit_event_values_fit_in_the_reserved_column_length(): void
    {
        foreach (AuditEvent::cases() as $case) {
            $this->assertLessThanOrEqual(
                64,
                strlen($case->value),
                sprintf('%s の値 %s が varchar(64) を超えています', $case->name, $case->value),
            );
        }
    }

    public function test_audit_event_values_are_unique(): void
    {
        $values = array_map(fn (AuditEvent $case) => $case->value, AuditEvent::cases());

        $this->assertSame(count($values), count(array_unique($values)));
    }

    public function test_audit_target_role_values_are_within_the_expected_vocabulary(): void
    {
        $expected = ['subject', 'source', 'result', 'affected'];
        $actual = array_map(fn (AuditTargetRole $case) => $case->value, AuditTargetRole::cases());

        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual);
    }
}
