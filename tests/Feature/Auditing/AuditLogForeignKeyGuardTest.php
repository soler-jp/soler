<?php

namespace Tests\Feature\Auditing;

use App\Auditing\AuditContext;
use App\Auditing\AuditEvent;
use App\Auditing\AuditTarget;
use App\Auditing\AuditTargetRole;
use App\Exceptions\PhysicalDeletionNotAllowed;
use App\Models\AuditLog;
use App\Models\BusinessUnit;
use App\Models\FiscalYear;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 追記専用契約は 2 段構えで守る。
 *
 *  1. モデル層: `BusinessUnit` / `User` の `deleting` フックがドメイン禁則で
 *     `PhysicalDeletionNotAllowed` を投げる。通常の Eloquent 経路はここで止まる。
 *  2. DB 層: `audit_logs.business_unit_id` / `audit_logs.actor_id` に
 *     `restrictOnDelete()` を張り、raw DB 削除で層 1 を迂回されても
 *     監査ログが残っている限り DB レベルで拒否する (defense-in-depth)。
 *
 * 両層を回帰テストで固定する。
 */
class AuditLogForeignKeyGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // sqlite でも FK 制約を有効にする
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    public function test_business_unit_delete_via_model_is_rejected_by_domain_exception(): void
    {
        // 監査ログの有無に関わらず、BU の物理削除は禁則
        $bu = BusinessUnit::factory()->create();

        $this->expectException(PhysicalDeletionNotAllowed::class);
        $this->expectExceptionMessageMatches('/物理削除は許可されていません/');

        $bu->delete();
    }

    public function test_user_delete_via_model_is_rejected_by_domain_exception(): void
    {
        $user = User::factory()->create();

        $this->expectException(PhysicalDeletionNotAllowed::class);
        $this->expectExceptionMessageMatches('/物理削除は許可されていません/');

        $user->delete();
    }

    public function test_business_unit_raw_delete_is_rejected_by_fk_when_audit_logs_exist(): void
    {
        // モデル層を迂回して raw DELETE を発行した場合の防壁。
        // 監査ログが残っている限り DB レベルで拒否される。
        $bu = BusinessUnit::factory()->create();
        $this->recordOneLog($bu);

        $this->expectException(QueryException::class);

        DB::table('business_units')->where('id', $bu->getKey())->delete();
    }

    public function test_user_raw_delete_is_rejected_by_fk_when_referenced_as_actor(): void
    {
        $bu = BusinessUnit::factory()->create();
        $actor = User::factory()->create();
        $this->recordOneLog($bu, $actor);

        $this->expectException(QueryException::class);

        DB::table('users')->where('id', $actor->getKey())->delete();
    }

    public function test_audit_log_is_not_deleted_when_raw_business_unit_delete_is_rejected(): void
    {
        $bu = BusinessUnit::factory()->create();
        $log = $this->recordOneLog($bu);

        try {
            DB::table('business_units')->where('id', $bu->getKey())->delete();
            $this->fail('BusinessUnit の raw 削除が拒否されませんでした');
        } catch (QueryException) {
            // 期待通り
        }

        $this->assertNotNull(AuditLog::query()->find($log->getKey()));
        $this->assertSame($bu->getKey(), AuditLog::query()->find($log->getKey())->business_unit_id);
    }

    public function test_actor_id_is_not_nullified_when_raw_user_delete_is_rejected(): void
    {
        $bu = BusinessUnit::factory()->create();
        $actor = User::factory()->create();
        $log = $this->recordOneLog($bu, $actor);

        try {
            DB::table('users')->where('id', $actor->getKey())->delete();
            $this->fail('User の raw 削除が拒否されませんでした');
        } catch (QueryException) {
            // 期待通り
        }

        $log->refresh();
        $this->assertSame($actor->getKey(), $log->actor_id);
    }

    public function test_business_unit_deleting_hook_does_not_perform_partial_cascade_before_rejection(): void
    {
        // 旧実装では `deleting` フックが current_fiscal_year_id の更新や関連削除を
        // 先に実行してから FK で失敗するため、削除に失敗しても副作用だけが残った。
        // 新実装 (throw first) では副作用が起きないことを回帰として固定する。
        $user = User::factory()->create();
        $bu = $user->createBusinessUnitWithDefaults(['name' => '副作用テスト事業体']);
        $fiscalYear = $bu->createFiscalYear(2025, $user);

        $bu->update(['current_fiscal_year_id' => $fiscalYear->id]);
        $bu->refresh();
        $fiscalYearId = $fiscalYear->getKey();

        try {
            $bu->delete();
            $this->fail('BU 削除が拒否されませんでした');
        } catch (PhysicalDeletionNotAllowed) {
            // 期待通り
        }

        // 全ての関連データがそのまま残っていること
        $bu->refresh();
        $this->assertSame($fiscalYearId, $bu->current_fiscal_year_id);
        $this->assertNotNull(FiscalYear::query()->find($fiscalYearId));
        $this->assertGreaterThan(0, $bu->accounts()->count());
    }

    private function recordOneLog(BusinessUnit $bu, ?User $actor = null): AuditLog
    {
        $logger = app(AuditLogger::class);
        $log = null;

        DB::transaction(function () use ($logger, $bu, $actor, &$log) {
            AuditContext::within(AuditEvent::TransactionDeactivated, function () use ($logger, $bu, $actor, &$log) {
                $log = $logger->record(
                    event: AuditEvent::TransactionDeactivated,
                    targets: [new AuditTarget(AuditTargetRole::Subject, $bu)],
                    actor: $actor ?? $bu->user,
                );
            });
        });

        return $log;
    }
}
