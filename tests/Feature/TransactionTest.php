<?php

namespace Tests\Feature;

use App\Auditing\AuditEvent;
use App\Models\AuditLog;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Validators\TransactionValidator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionTest extends TestCase
{
    use RefreshDatabase;

    // ////////
    // Factory のテスト
    // ////////

    #[Test]
    public function factoryで_transactionをmakeできる()
    {
        $transaction = Transaction::factory()->make();

        $this->assertInstanceOf(Transaction::class, $transaction);
        $this->assertNotNull($transaction->fiscal_year_id);
        $this->assertNotNull($transaction->created_by);
    }

    #[Test]
    public function factoryで_transactionをcreateできる()
    {
        $transaction = Transaction::factory()->create();

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
        ]);
    }

    #[Test]
    public function transactionはactiveフラグをbooleanとして扱える()
    {
        $transaction = Transaction::factory()->create([
            'is_active' => false,
        ]);

        $this->assertFalse($transaction->is_active);
    }

    #[Test]
    public function transactionをdeactivateできる()
    {
        $user = User::factory()->create();
        $transaction = $this->createTransactionForUser($user);

        $transaction->deactivate($user, '誤登録のため無効化');

        $transaction->refresh();

        $this->assertFalse($transaction->is_active);
        $this->assertNotNull($transaction->deactivated_at);
        $this->assertSame($user->id, $transaction->deactivated_by);
        $this->assertSame('誤登録のため無効化', $transaction->deactivation_reason);

        $auditLog = AuditLog::query()->latest('id')->first();

        $this->assertNotNull($auditLog);
        $this->assertSame(AuditEvent::TransactionDeactivated, $auditLog->event_type);
        $this->assertSame($user->id, $auditLog->actor_id);
        $this->assertSame('誤登録のため無効化', $auditLog->reason);
    }

    #[Test]
    public function 既に無効化済みのtransactionを再度deactivateしても記録は上書きされない()
    {
        $firstUser = User::factory()->create();
        $transaction = $this->createTransactionForUser($firstUser);

        $transaction->deactivate($firstUser, '初回の無効化');
        $firstDeactivatedAt = $transaction->fresh()->deactivated_at;

        $transaction->fresh()->deactivate($firstUser, '再無効化');

        $transaction->refresh();

        $this->assertFalse($transaction->is_active);
        $this->assertSame($firstUser->id, $transaction->deactivated_by);
        $this->assertSame('初回の無効化', $transaction->deactivation_reason);
        $this->assertTrue($transaction->deactivated_at?->eq($firstDeactivatedAt));
    }

    #[Test]
    public function 他ユーザーはtransactionをdeactivateできない()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $transaction = $this->createTransactionForUser($user);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('この取引を無効化する権限がありません。');

        $transaction->deactivate($otherUser, '不正な無効化');
    }

    #[Test]
    public function 決算済み会計年度のtransactionは無効化できない()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => '無効化制御事業体',
        ]);
        $fiscalYear = $unit->createFiscalYear(2025, $user);
        $transaction = Transaction::factory()->create([
            'fiscal_year_id' => $fiscalYear->id,
        ]);

        $fiscalYear->update(['is_closed' => true]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('決算済みの会計年度に属する取引は無効化できません。');

        $transaction->deactivate($user, '締め後の無効化');
    }

    #[Test]
    public function transactionのbusiness_ratio_stateは按分なしならnot_allocatedになる()
    {
        $transaction = $this->createTransactionWithJournalEntries([
            [
                'account' => '通信費',
                'sub_account' => '通信費',
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 1000,
            ],
            [
                'account' => '現金',
                'sub_account' => '現金',
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => 1000,
            ],
        ]);

        $this->assertSame(Transaction::BUSINESS_RATIO_STATE_NOT_ALLOCATED, $transaction->business_ratio_state);
        $this->assertNull($transaction->business_ratio);
    }

    #[Test]
    public function transactionのbusiness_ratio_stateは単一割合ならuniformになる()
    {
        $transaction = $this->createTransactionWithJournalEntries([
            [
                'account' => '通信費',
                'sub_account' => '通信費',
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 6000,
                'business_ratio' => 60,
            ],
            [
                'account' => '旅費交通費',
                'sub_account' => '旅費交通費',
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 4000,
            ],
            [
                'account' => '現金',
                'sub_account' => '現金',
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => 10000,
            ],
        ]);

        $this->assertSame(Transaction::BUSINESS_RATIO_STATE_UNIFORM, $transaction->business_ratio_state);
        $this->assertSame(60, $transaction->business_ratio);
    }

    #[Test]
    public function transactionのbusiness_ratio_stateは複数割合が混在するとmixedになる()
    {
        $transaction = $this->createTransactionWithJournalEntries([
            [
                'account' => '通信費',
                'sub_account' => '通信費',
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 6000,
                'business_ratio' => 60,
            ],
            [
                'account' => '旅費交通費',
                'sub_account' => '旅費交通費',
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 4000,
                'business_ratio' => 40,
            ],
            [
                'account' => '現金',
                'sub_account' => '現金',
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => 10000,
            ],
        ]);

        $this->assertSame(Transaction::BUSINESS_RATIO_STATE_MIXED, $transaction->business_ratio_state);
        $this->assertNull($transaction->business_ratio);
    }

    // /////////////////////////

    #[Test]
    public function 正しいデータでバリデーションが通る()
    {
        $fiscalYear = FiscalYear::factory()->create();
        $user = User::factory()->create();

        $data = [
            'fiscal_year_id' => $fiscalYear->id,
            'date' => '2025-06-24',
            'description' => '備品購入',
            'remarks' => 'Amazonでプリンタ購入',
            'tax_type' => 'taxable_purchases_10',
            'is_adjusting_entry' => true,
            'created_by' => $user->id,
        ];

        $validated = TransactionValidator::validate($data);

        $this->assertSame($data['description'], $validated['description']);
    }

    #[Test]
    public function fiscal_year_idが無ければバリデーションエラー()
    {
        $this->expectException(ValidationException::class);

        TransactionValidator::validate([
            'date' => '2025-06-24',
            'description' => 'テスト',
        ]);
    }

    #[Test]
    public function created_byはnullでもバリデーションが通る()
    {
        $fy = FiscalYear::factory()->create();

        $validated = TransactionValidator::validate([
            'fiscal_year_id' => $fy->id,
            'date' => now()->toDateString(),
            'description' => '登録者無しの取引',
            'created_by' => null,
        ]);

        $this->assertArrayHasKey('created_by', $validated);
        $this->assertNull($validated['created_by']);
    }

    #[Test]
    #[Group('mysql')]
    public function entry_numberは年度ごとに連番で採番される()
    {
        $fy = FiscalYear::factory()->create();
        $user = User::factory()->create();

        $t1 = Transaction::create([
            'fiscal_year_id' => $fy->id,
            'date' => '2025-06-24',
            'description' => 'A',
            'created_by' => $user->id,
        ]);

        $t2 = Transaction::create([
            'fiscal_year_id' => $fy->id,
            'date' => '2025-06-25',
            'description' => 'B',
            'created_by' => $user->id,
        ]);

        $this->assertEquals(1, $t1->entry_number);
        $this->assertEquals(2, $t2->entry_number);
    }

    #[Test]
    #[Group('mysql')]
    public function entry_numberは別の年度ではリセットされる()
    {
        $user = User::factory()->create();
        $fy1 = FiscalYear::factory()->create(['year' => 2024]);
        $fy2 = FiscalYear::factory()->create(['year' => 2025]);

        $t1 = Transaction::create([
            'fiscal_year_id' => $fy1->id,
            'date' => '2024-06-24',
            'description' => '前年度',
            'created_by' => $user->id,
        ]);

        $t2 = Transaction::create([
            'fiscal_year_id' => $fy2->id,
            'date' => '2025-06-24',
            'description' => '今年度',
            'created_by' => $user->id,
        ]);

        $this->assertEquals(1, $t1->entry_number);
        $this->assertEquals(1, $t2->entry_number); // ← 年度またがり
    }

    #[Test]
    public function display_numberは年度と連番を組み合わせた形式になる()
    {
        $fy = FiscalYear::factory()->create(['year' => 2025]);
        $user = User::factory()->create();

        $t = Transaction::create([
            'fiscal_year_id' => $fy->id,
            'date' => '2025-06-24',
            'description' => '表示番号テスト',
            'created_by' => $user->id,
        ]);

        $this->assertEquals('2025-0001', $t->display_number);
    }

    #[Test]
    public function journal_tax_type_summaryは新しい税区分ラベルを返す()
    {
        $transaction = $this->createTransactionWithJournalEntries([
            [
                'account' => '消耗品費',
                'sub_account' => '消耗品費',
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 1000,
                'tax_type' => JournalEntry::TAX_TYPE_EXEMPT,
            ],
            [
                'account' => '通信費',
                'sub_account' => '通信費',
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 2000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'account' => '売上高',
                'sub_account' => '売上高',
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => 3000,
                'tax_type' => JournalEntry::TAX_TYPE_ZERO_RATED,
            ],
        ]);

        $this->assertSame('非課税 / 不課税 / 免税', $transaction->journal_tax_type_summary);
    }

    /**
     * @param  array<int, array{
     *     account: string,
     *     sub_account: string,
     *     type: string,
     *     net_amount: int,
     *     tax_type?: string,
     *     business_ratio?: int|null
     * }>  $journalEntries
     */
    private function createTransactionWithJournalEntries(array $journalEntries): Transaction
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'Transaction状態テスト事業体']);
        $fiscalYear = $unit->createFiscalYear(2025, $user)->refresh();

        $transaction = Transaction::create([
            'fiscal_year_id' => $fiscalYear->id,
            'date' => '2025-06-24',
            'description' => 'Transaction状態テスト',
            'created_by' => $user->id,
        ]);

        foreach ($journalEntries as $entry) {
            $subAccount = $unit->getSubAccountByName($entry['account'], $entry['sub_account']);

            $transaction->journalEntries()->create([
                'account_id' => $subAccount->account_id,
                'sub_account_id' => $subAccount->id,
                'type' => $entry['type'],
                'net_amount' => $entry['net_amount'],
                'tax_amount' => 0,
                'tax_type' => $entry['tax_type'] ?? JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                'business_ratio' => $entry['business_ratio'] ?? null,
                'is_effective' => true,
            ]);
        }

        return $transaction->fresh(['journalEntries']);
    }

    private function createTransactionForUser(User $user): Transaction
    {
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'Transaction無効化テスト事業体']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        return Transaction::factory()->create([
            'fiscal_year_id' => $fiscalYear->id,
            'created_by' => $user->id,
        ]);
    }

    #[Test]
    public function is_revisableは通常のactive_transactionでtrueを返す(): void
    {
        $user = User::factory()->create();
        $transaction = $this->createTransactionForUser($user);

        $this->assertTrue($transaction->isRevisable());
        $this->assertNull($transaction->revisionBlockedReason());
    }

    #[Test]
    public function is_revisableはinactiveな_transactionでfalse(): void
    {
        $user = User::factory()->create();
        $transaction = $this->createTransactionForUser($user);

        $transaction->forceFill(['is_active' => false])->save();

        $this->assertFalse($transaction->isRevisable());
        $this->assertSame('無効化済みの取引は修正できません。', $transaction->revisionBlockedReason());
    }

    #[Test]
    public function is_revisableは_is_plannedでfalse(): void
    {
        $user = User::factory()->create();
        $transaction = $this->createTransactionForUser($user);

        $transaction->forceFill(['is_planned' => true])->save();

        $this->assertFalse($transaction->isRevisable());
        $this->assertSame('予定取引はこの修正機能の対象外です。', $transaction->revisionBlockedReason());
    }

    #[Test]
    public function is_revisableは決算整理仕訳でfalse(): void
    {
        $user = User::factory()->create();
        $transaction = $this->createTransactionForUser($user);

        $transaction->forceFill(['is_adjusting_entry' => true])->save();

        $this->assertFalse($transaction->isRevisable());
        $this->assertSame('決算整理仕訳はこの修正機能の対象外です。', $transaction->revisionBlockedReason());
    }

    #[Test]
    public function is_revisableは定期取引由来やカード取込由来でfalse(): void
    {
        // 実データを組み立てなくても、判定はモデルインスタンスの属性で完結するので
        // インメモリ Transaction にフィールドを乗せて直接検証する（DB 保存は不要）。
        $recurring = new Transaction(['recurring_transaction_plan_id' => 1]);
        $recurring->exists = true;
        $recurring->is_active = true;
        $this->assertFalse($recurring->isRevisable());
        $this->assertSame('定期取引計画由来の取引はこの修正機能の対象外です。', $recurring->revisionBlockedReason());

        $cardImport = new Transaction(['credit_card_import_batch_id' => 1]);
        $cardImport->exists = true;
        $cardImport->is_active = true;
        $this->assertFalse($cardImport->isRevisable());
        $this->assertSame('クレジットカード取込由来の取引はこの修正機能の対象外です。', $cardImport->revisionBlockedReason());
    }

    #[Test]
    public function is_revisableは決算済み年度でfalse(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '決算済みテスト']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);
        $transaction = Transaction::factory()->create([
            'fiscal_year_id' => $fiscalYear->id,
            'created_by' => $user->id,
        ]);

        $fiscalYear->update(['is_closed' => true]);

        $this->assertFalse($transaction->fresh()->isRevisable());
        $this->assertSame('決算済みの会計年度に属する取引は修正できません。', $transaction->fresh()->revisionBlockedReason());
    }
}
