<?php

namespace Tests\Feature;

use App\Auditing\AuditEvent;
use App\Auditing\AuditTargetRole;
use App\Models\AuditLog;
use App\Models\AuditLogTarget;
use App\Models\BusinessUnit;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionRegistrar;
use App\Services\TransactionRevisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuditLogPageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 現在年度の監査ログ一覧を表示できる(): void
    {
        [$user, $unit, $fiscalYear] = $this->createInitializedUser();
        $transaction = $this->createTransaction($fiscalYear, $user, '監査ログ表示用の取引');

        $this->createAuditLog(
            businessUnit: $unit,
            actor: $user,
            auditable: $transaction,
            recordedAt: '2026-08-07 12:00:00',
            reason: '利用者による削除',
        );

        $response = $this->actingAs($user)->get(route('audit-logs.index'));

        $response->assertOk();
        $response->assertSee(__('audit_logs.title'));
        $response->assertSee('2025年度');
        $response->assertSee('取引を無効化');
        $response->assertSee('日付');
        $response->assertSee('摘要');
        $response->assertSee('金額');
        $response->assertSee('内容');
        $response->assertSee('監査ログ表示用の取引');
        $response->assertSee('0円');
        $response->assertDontSee('理由');
        $response->assertDontSee('イベント');
        $response->assertDontSee('伝票番号');
    }

    #[Test]
    public function 現在年度外と他事業体の監査ログは一覧に含まれない(): void
    {
        [$user, $unit, $fiscalYear] = $this->createInitializedUser();
        [$otherUser, $otherUnit, $otherFiscalYear] = $this->createInitializedUser(name: '別事業体');
        $previousFiscalYear = $unit->createFiscalYear(2024, $user);

        $currentTransaction = $this->createTransaction($fiscalYear, $user, '当年度の対象');
        $previousTransaction = $this->createTransaction($previousFiscalYear, $user, '前年度の対象');
        $otherTransaction = $this->createTransaction($otherFiscalYear, $otherUser, '他事業体の対象');

        $this->createAuditLog(
            businessUnit: $unit,
            actor: $user,
            auditable: $currentTransaction,
            recordedAt: '2026-08-07 09:00:00',
            reason: '当年度のログ',
        );

        $this->createAuditLog(
            businessUnit: $unit,
            actor: $user,
            auditable: $previousTransaction,
            recordedAt: '2026-08-07 09:05:00',
            reason: '前年度のログ',
        );

        $this->createAuditLog(
            businessUnit: $otherUnit,
            actor: $otherUser,
            auditable: $otherTransaction,
            recordedAt: '2026-08-07 10:00:00',
            reason: '他事業体のログ',
        );

        $response = $this->actingAs($user)->get(route('audit-logs.index'));

        $response->assertOk();
        $response->assertSee('取引を無効化');
        $response->assertSee('当年度の対象');
        $response->assertDontSee('前年度の対象');
        $response->assertDontSee('他事業体の対象');
    }

    #[Test]
    public function 改訂イベントはイベント名の代わりにdiffを表示する(): void
    {
        [$user, $unit, $fiscalYear] = $this->createInitializedUser();
        $expense = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();
        $cash = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        $transaction = app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-04-01',
            'description' => '改訂前の摘要',
            'created_by' => $user->id,
        ], [
            [
                'sub_account_id' => $expense->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'gross_amount' => 1100,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'gross_amount' => 1100,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $user);

        app(TransactionRevisor::class)->reviseSinglePair($transaction, $user, [
            'revision_reason' => '監査ログ差分表示',
            'gross_amount' => 2200,
            'description' => '改訂後の摘要',
        ]);

        $response = $this->actingAs($user)->get(route('audit-logs.index'));

        $response->assertOk();
        $response->assertSee('摘要: 改訂前の摘要 -> 改訂後の摘要');
        $response->assertSee('金額: 1,100円 -> 2,200円');
        $response->assertDontSee('借方変更: 消耗品費 1,100円 -> 消耗品費 2,200円');
        $response->assertDontSee('貸方変更: 現金 1,100円 -> 現金 2,200円');
        $response->assertDontSee('取引を改訂');
    }

    /**
     * @return array{0: User, 1: BusinessUnit, 2: FiscalYear}
     */
    private function createInitializedUser(string $name = 'テスト事業体'): array
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => $name]);
        $fiscalYear = $unit->createFiscalYear(2025, $user);
        $unit->activateFiscalYear($fiscalYear, $user);

        return [$user, $unit->fresh(), $fiscalYear->fresh()];
    }

    private function createAuditLog(
        BusinessUnit $businessUnit,
        User $actor,
        Transaction|FiscalYear $auditable,
        string $recordedAt,
        string $reason,
    ): AuditLog {
        $timestamp = Carbon::parse($recordedAt);

        $log = new AuditLog;
        $log->forceFill([
            'business_unit_id' => $businessUnit->id,
            'event_type' => AuditEvent::TransactionDeactivated->value,
            'actor_id' => $actor->id,
            'actor_label' => $actor->name,
            'reason' => $reason,
            'payload_version' => 1,
            'changes' => null,
            'context' => null,
            'recorded_at' => $timestamp,
        ])->save();

        $target = new AuditLogTarget;
        $target->forceFill([
            'audit_log_id' => $log->id,
            'business_unit_id' => $businessUnit->id,
            'role' => AuditTargetRole::Subject->value,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id' => (string) $auditable->id,
            'recorded_at' => $timestamp,
        ])->save();

        return $log;
    }

    private function createTransaction(FiscalYear $fiscalYear, User $user, string $description): Transaction
    {
        return Transaction::factory()->create([
            'fiscal_year_id' => $fiscalYear->id,
            'created_by' => $user->id,
            'description' => $description,
        ]);
    }
}
