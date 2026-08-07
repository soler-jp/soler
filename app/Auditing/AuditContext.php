<?php

namespace App\Auditing;

use App\Auditing\Exceptions\AuditingContractViolation;
use Closure;
use Throwable;

/**
 * 監査対象操作のスコープを表す。
 *
 * `within()` で開いたスコープの中で `AuditLogger::record()` が最低 1 回
 * 呼ばれることを実行時に保証する。呼ばれなかった場合はスコープ終了時に
 * `AuditingContractViolation` を投げ、周囲の DB トランザクションも
 * ロールバックさせる（記録漏れをモデル契約として検出する）。
 *
 * 実装は静的な bool フラグではなく stack + try/finally ベースで、
 * コールスタックローカルに保持する（Fiber/coroutine を跨がない前提）。
 */
final class AuditContext
{
    /**
     * @var list<array{event: AuditEvent, count: int}>
     */
    private static array $stack = [];

    /**
     * スコープを開き、内側で `AuditLogger::record()` が最低 1 回呼ばれることを要求する。
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $work
     * @return TReturn
     */
    public static function within(AuditEvent $event, Closure $work): mixed
    {
        self::$stack[] = ['event' => $event, 'count' => 0];

        try {
            $result = $work();
        } catch (Throwable $e) {
            array_pop(self::$stack);
            throw $e;
        }

        $frame = array_pop(self::$stack);

        if ($frame['count'] === 0) {
            throw new AuditingContractViolation(
                sprintf(
                    'AuditContext::within(%s) が record() 0 件で終了しました。',
                    $event->value,
                ),
            );
        }

        return $result;
    }

    /**
     * 現在スコープ内で呼ばれていることだけを検査する（副作用なし）。
     *
     * スコープ外なら `AuditingContractViolation`。永続化前の早期検査に使う。
     */
    public static function assertInScope(): void
    {
        if (count(self::$stack) === 0) {
            throw new AuditingContractViolation(
                'AuditLogger::record() は AuditContext::within() スコープ内で呼ばれる必要があります。',
            );
        }
    }

    /**
     * 現在のスコープに record 呼び出しを 1 件登録する。
     *
     * `within()` の「最低 1 件 record された」保証を担保するため、
     * このカウントは**永続化が完了した後にのみ**進める。
     * 呼び出し元が record() の失敗を握り潰しても、実際には保存されていない
     * イベントがカウントに反映されないようにする。
     *
     * スコープ外で呼ばれた場合は `AuditingContractViolation` を投げる。
     * `AuditLogger::record()` からのみ呼ばれる想定。
     */
    public static function registerRecord(): void
    {
        self::assertInScope();

        $count = count(self::$stack);
        self::$stack[$count - 1]['count']++;
    }

    /**
     * 現在のスコープの深さ（テスト・診断用）。
     */
    public static function depth(): int
    {
        return count(self::$stack);
    }
}
