<?php

namespace Tests\Unit\Auditing;

use App\Auditing\AuditContext;
use PHPUnit\Framework\AssertionFailedError;
use ReflectionClass;
use Tests\TestCase;

/**
 * AuditContext の静的 stack はテスト間で残ると次のテストを汚染する。
 * try/finally が漏れるようなバグが混入した場合に検出できるよう、
 * tearDown で必ず depth 0 を検証し、リセットする。
 */
abstract class AuditContextTestBase extends TestCase
{
    protected function tearDown(): void
    {
        try {
            if (AuditContext::depth() !== 0) {
                throw new AssertionFailedError(sprintf(
                    'AuditContext の stack がテスト終了時にクリーンでありません (depth = %d)。'
                    .' within() の try/finally が漏れている可能性があります。',
                    AuditContext::depth(),
                ));
            }
        } finally {
            $this->resetAuditContextStack();
            parent::tearDown();
        }
    }

    /**
     * static stack をリフレクションで空に戻す。
     * テスト失敗経路でも次のテストへ leak しないためのセーフティネット。
     */
    private function resetAuditContextStack(): void
    {
        $reflection = new ReflectionClass(AuditContext::class);
        $property = $reflection->getProperty('stack');
        $property->setValue(null, []);
    }
}
