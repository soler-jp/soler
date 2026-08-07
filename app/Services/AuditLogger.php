<?php

namespace App\Services;

use App\Auditing\AuditChanges;
use App\Auditing\AuditContext;
use App\Auditing\AuditEvent;
use App\Auditing\AuditTarget;
use App\Auditing\AuditTargetRole;
use App\Auditing\Exceptions\AuditingContractViolation;
use App\Concerns\SkipActorGuard;
use App\Models\AuditLog;
use App\Models\AuditLogTarget;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 監査ログの書き込みを担う唯一のサービス。
 *
 * `record()` は次を実行時に強制する:
 *
 *  - DB トランザクション内で呼ばれること (`DB::transactionLevel() > 0`)
 *  - `AuditContext::within()` スコープ内で呼ばれること
 *  - `$targets` に `subject` が最低 1 件含まれること
 *  - 全 target が同一 `business_unit_id` であること
 *  - `AuditChanges` シリアライズ後 64 KB 以下であること
 *
 * これらは呼び出し側の注意力に依存せず、モデル契約として保証される。
 */
#[SkipActorGuard('監査ログ書き込みは呼び出し元 Service が actor 認可済みである前提で、AuditLogger 自身は認可判定を行わない。認可責務は呼び出し元の業務 Service にある。')]
class AuditLogger
{
    public const CHANGES_MAX_BYTES = 65536;

    public const ACTOR_LABEL_MAX_LENGTH = 100;

    /**
     * 監査ログを記録する。
     *
     * @param  array<int, AuditTarget>  $targets
     * @param  array<string, mixed>  $context
     */
    public function record(
        AuditEvent $event,
        array $targets,
        ?User $actor,
        AuditChanges $changes = new AuditChanges,
        ?string $reason = null,
        array $context = [],
    ): AuditLog {
        if (DB::transactionLevel() === 0) {
            throw new AuditingContractViolation(
                'AuditLogger::record() は DB トランザクション内で呼ばれる必要があります。',
            );
        }

        // スコープ外の呼び出しは早期に拒否する。この時点ではカウントは進めず、
        // 永続化後に registerRecord() で実カウントを進める（P1 対応）。
        AuditContext::assertInScope();

        $this->assertTargetsShape($targets);

        $businessUnitId = $this->resolveBusinessUnitId($targets);

        $changesJson = $this->encodeChanges($changes);

        $recordedAt = now();

        $log = new AuditLog;
        $log->forceFill([
            'business_unit_id' => $businessUnitId,
            'event_type' => $event->value,
            'actor_id' => $actor?->getKey(),
            'actor_label' => $this->normalizeActorLabel($actor?->name),
            'reason' => $this->normalizeReason($reason),
            'payload_version' => $changes->payloadVersion,
            'changes' => $changesJson,
            'context' => $context === [] ? null : $context,
            'recorded_at' => $recordedAt,
        ])->save();

        foreach ($targets as $target) {
            $targetRow = new AuditLogTarget;
            $targetRow->forceFill([
                'audit_log_id' => $log->getKey(),
                'business_unit_id' => $businessUnitId,
                'role' => $target->role->value,
                'auditable_type' => $target->model->getMorphClass(),
                'auditable_id' => (string) $target->model->getKey(),
                'recorded_at' => $recordedAt,
            ])->save();
        }

        // 実際に永続化が成功した後にのみカウントを進める。
        // 呼び出し元が record() の例外を握り潰しても、within() の
        // 「最低 1 件 record された」保証はここで初めて達成される。
        AuditContext::registerRecord();

        return $log;
    }

    /**
     * @param  array<int, AuditTarget>  $targets
     */
    private function assertTargetsShape(array $targets): void
    {
        if ($targets === []) {
            throw new InvalidArgumentException('AuditLogger::record() には最低 1 件の AuditTarget が必要です。');
        }

        foreach ($targets as $target) {
            if (! $target instanceof AuditTarget) {
                throw new InvalidArgumentException(
                    'AuditLogger::record() の $targets は AuditTarget インスタンスの配列である必要があります。',
                );
            }
        }

        $hasSubject = false;
        foreach ($targets as $target) {
            if ($target->role === AuditTargetRole::Subject) {
                $hasSubject = true;
                break;
            }
        }

        if (! $hasSubject) {
            throw new InvalidArgumentException(
                'AuditLogger::record() の $targets には最低 1 件の subject role が必要です。',
            );
        }
    }

    /**
     * @param  array<int, AuditTarget>  $targets
     */
    private function resolveBusinessUnitId(array $targets): int
    {
        $businessUnitIds = [];
        foreach ($targets as $target) {
            $businessUnitIds[] = $target->model->resolveBusinessUnit()->getKey();
        }
        $unique = array_values(array_unique($businessUnitIds));

        if (count($unique) !== 1) {
            throw new InvalidArgumentException(
                'AuditLogger::record() の $targets は同一 BusinessUnit に属している必要があります。',
            );
        }

        return $unique[0];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function encodeChanges(AuditChanges $changes): ?array
    {
        if ($changes->isEmpty()) {
            return null;
        }

        $array = $changes->toArray();

        $json = json_encode(
            $array,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        if (strlen($json) > self::CHANGES_MAX_BYTES) {
            throw new AuditingContractViolation(sprintf(
                'AuditChanges のシリアライズ結果が %d バイトを超えました (%d バイト)。',
                self::CHANGES_MAX_BYTES,
                strlen($json),
            ));
        }

        return $array;
    }

    private function normalizeActorLabel(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $trimmed = trim($name);
        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed) > self::ACTOR_LABEL_MAX_LENGTH) {
            $trimmed = mb_substr($trimmed, 0, self::ACTOR_LABEL_MAX_LENGTH - 1).'…';
        }

        return $trimmed;
    }

    private function normalizeReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        $trimmed = trim($reason);

        return $trimmed === '' ? null : $trimmed;
    }
}
