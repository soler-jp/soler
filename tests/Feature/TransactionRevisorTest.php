<?php

namespace Tests\Feature;

use App\Auditing\AuditEvent;
use App\Auditing\AuditTargetRole;
use App\Models\AuditLog;
use App\Models\Counterparty;
use App\Models\JournalEntry;
use App\Models\RecurringTransactionPlan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionRegistrar;
use App\Services\TransactionRevisor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionRevisorTest extends TestCase
{
    use RefreshDatabase;

    protected function createSinglePairTransaction(
        User $user,
        string $unitName = 'single pair 改訂テスト',
        bool $isTaxable = true,
    ): Transaction {
        $unit = $user->createBusinessUnitWithDefaults(['name' => $unitName]);
        $fiscalYear = $unit->createFiscalYear(2025, $user);
        $fiscalYear->forceFill(['is_taxable' => $isTaxable])->save();

        $expense = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();
        $cash = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        return app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-04-01',
            'description' => '既存の単一仕訳',
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
    }

    #[Test]
    public function 通常取引を改訂できる(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '改訂テスト事業体']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $originalExpense = $unit->getAccountByName('通信費')->subAccounts()->firstOrFail();
        $revisedExpense = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();
        $originalCredit = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $revisedCredit = $unit->getAccountByName('事業主借')->subAccounts()->firstOrFail();
        $counterparty = Counterparty::factory()->create([
            'business_unit_id' => $unit->id,
            'name' => '改訂前取引先',
        ]);

        $transaction = app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-04-01',
            'description' => '文房具購入',
            'remarks' => '改訂前備考',
            'counterparty_id' => $counterparty->id,
            'created_by' => $user->id,
        ], [
            [
                'sub_account_id' => $originalExpense->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 1000,
                'tax_amount' => 100,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            ],
            [
                'sub_account_id' => $originalCredit->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => 1100,
                'tax_amount' => 0,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $fiscalYear->businessUnit->user, );

        $revised = app(TransactionRevisor::class)->revise($transaction, $user, [
            'transaction' => [
                'revision_reason' => '金額入力ミスの修正',
            ],
            'journal_entries' => [
                [
                    'sub_account_id' => $revisedExpense->id,
                    'type' => JournalEntry::TYPE_DEBIT,
                    'net_amount' => 2000,
                    'tax_amount' => 200,
                    'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
                ],
                [
                    'sub_account_id' => $revisedCredit->id,
                    'type' => JournalEntry::TYPE_CREDIT,
                    'net_amount' => 2200,
                    'tax_amount' => 0,
                    'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                ],
            ],
        ]);

        $transaction->refresh();
        $revised->refresh();

        $this->assertFalse($transaction->is_active);
        $this->assertSame('修正による改訂', $transaction->deactivation_reason);
        $this->assertSame($user->id, $transaction->deactivated_by);
        $this->assertTrue($revised->is_active);
        $this->assertSame($transaction->id, $revised->revised_from_transaction_id);
        $this->assertSame('金額入力ミスの修正', $revised->revision_reason);
        $this->assertSame($user->id, $revised->created_by);
        $this->assertSame('2025-04-01', $revised->date->toDateString());
        $this->assertSame('文房具購入', $revised->description);
        $this->assertSame('改訂前備考', $revised->remarks);
        $this->assertSame($counterparty->id, $revised->counterparty_id);
        $this->assertTrue($revised->revisedFrom->is($transaction));
        $this->assertTrue($transaction->revision->is($revised));
        $this->assertNotSame($transaction->entry_number, $revised->entry_number);

        $this->assertCount(2, $transaction->journalEntries);
        $this->assertSame($originalExpense->id, $transaction->journalEntries->firstWhere('type', JournalEntry::TYPE_DEBIT)?->sub_account_id);
        $this->assertSame(1000, $transaction->journalEntries->firstWhere('type', JournalEntry::TYPE_DEBIT)?->net_amount);
        $this->assertCount(2, $revised->journalEntries);
        $this->assertSame($revisedExpense->id, $revised->journalEntries->firstWhere('type', JournalEntry::TYPE_DEBIT)?->sub_account_id);
        $this->assertSame(2000, $revised->journalEntries->firstWhere('type', JournalEntry::TYPE_DEBIT)?->net_amount);
        $this->assertSame($revisedCredit->id, $revised->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT)?->sub_account_id);
        $this->assertSame(2200, $revised->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT)?->net_amount);
    }

    #[Test]
    public function single_pair取引をgross指定で改訂できる(): void
    {
        $user = User::factory()->create();
        $transaction = $this->createSinglePairTransaction($user, 'single pair gross');
        $unit = $transaction->fiscalYear->businessUnit;
        $newDebit = $unit->getAccountByName('通信費')->subAccounts()->firstOrFail();
        $newCredit = $unit->getAccountByName('事業主借')->subAccounts()->firstOrFail();

        $revised = app(TransactionRevisor::class)->reviseSinglePair($transaction, $user, [
            'revision_reason' => 'single pair gross 改訂',
            'gross_amount' => 2200,
            'debit_sub_account_id' => $newDebit->id,
            'credit_sub_account_id' => $newCredit->id,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_8,
            'date' => '2025-04-02',
            'description' => 'single pair gross 更新',
        ]);

        $revised->load('journalEntries');

        $this->assertSame('2025-04-02', $revised->date?->toDateString());
        $this->assertSame('single pair gross 更新', $revised->description);
        $this->assertSame($transaction->id, $revised->revised_from_transaction_id);

        $debitEntry = $revised->journalEntries->firstWhere('type', JournalEntry::TYPE_DEBIT);
        $creditEntry = $revised->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT);

        $this->assertSame($newDebit->id, $debitEntry?->sub_account_id);
        $this->assertSame(2037, $debitEntry?->net_amount);
        $this->assertSame(163, $debitEntry?->tax_amount);
        $this->assertSame(JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_8, $debitEntry?->tax_type);

        $this->assertSame($newCredit->id, $creditEntry?->sub_account_id);
        $this->assertSame(2200, $creditEntry?->net_amount);
        $this->assertSame(0, $creditEntry?->tax_amount);
        $this->assertSame(JournalEntry::TAX_TYPE_OUT_OF_SCOPE, $creditEntry?->tax_type);
    }

    #[Test]
    public function single_pair改訂でtransaction_revised監査ログを保存する(): void
    {
        $user = User::factory()->create();
        $transaction = $this->createSinglePairTransaction($user, 'single pair audit');

        $revised = app(TransactionRevisor::class)->reviseSinglePair($transaction, $user, [
            'revision_reason' => 'single pair 監査ログ',
            'gross_amount' => 2200,
            'description' => 'single pair 監査ログ更新',
        ]);

        $this->assertSame(1, AuditLog::query()->where('event_type', AuditEvent::TransactionRevised->value)->count());
        $this->assertSame(0, AuditLog::query()->where('event_type', AuditEvent::TransactionDeactivated->value)->count());

        $auditLog = AuditLog::query()
            ->with('targets')
            ->where('event_type', AuditEvent::TransactionRevised->value)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($user->id, $auditLog->actor_id);
        $this->assertSame('single pair 監査ログ', $auditLog->reason);
        $this->assertSame([null, 'single pair 監査ログ'], $auditLog->changes['subject']['revision_reason'] ?? null);

        $subjectTarget = $auditLog->targets->firstWhere('role', AuditTargetRole::Subject);
        $sourceTarget = $auditLog->targets->firstWhere('role', AuditTargetRole::Source);

        $this->assertNotNull($subjectTarget);
        $this->assertNotNull($sourceTarget);
        $this->assertSame($revised->getMorphClass(), $subjectTarget?->auditable_type);
        $this->assertSame((string) $revised->getKey(), $subjectTarget?->auditable_id);
        $this->assertSame($transaction->getMorphClass(), $sourceTarget?->auditable_type);
        $this->assertSame((string) $transaction->getKey(), $sourceTarget?->auditable_id);
    }

    #[Test]
    public function single_pair取引を既存の取引先id指定で改訂できる(): void
    {
        $user = User::factory()->create();
        $transaction = $this->createSinglePairTransaction($user, 'single pair counterparty id');
        $unit = $transaction->fiscalYear->businessUnit;
        $counterparty = Counterparty::factory()->create([
            'business_unit_id' => $unit->id,
            'name' => '新しい取引先',
        ]);

        $revised = app(TransactionRevisor::class)->reviseSinglePair($transaction, $user, [
            'revision_reason' => '取引先を既存IDで更新',
            'gross_amount' => 2200,
            'counterparty_id' => $counterparty->id,
        ]);

        $this->assertSame($counterparty->id, $revised->counterparty_id);
    }

    #[Test]
    public function single_pair取引を取引先名指定で改訂すると取引先が自動作成される(): void
    {
        $user = User::factory()->create();
        $transaction = $this->createSinglePairTransaction($user, 'single pair counterparty name');
        $unit = $transaction->fiscalYear->businessUnit;

        $revised = app(TransactionRevisor::class)->reviseSinglePair($transaction, $user, [
            'revision_reason' => '取引先を名前で更新',
            'gross_amount' => 2200,
            'counterparty_name' => '新規取引先',
        ]);

        $counterparty = $unit->counterparties()->where('name', '新規取引先')->first();

        $this->assertNotNull($counterparty);
        $this->assertSame($counterparty?->id, $revised->counterparty_id);
    }

    #[Test]
    public function single_pair取引でcounterparty_nameにnullを指定すると取引先を解除できる(): void
    {
        $user = User::factory()->create();
        $transaction = $this->createSinglePairTransaction($user, 'single pair counterparty clear');
        $unit = $transaction->fiscalYear->businessUnit;
        $counterparty = Counterparty::factory()->create([
            'business_unit_id' => $unit->id,
            'name' => '解除対象',
        ]);

        $transaction->forceFill([
            'counterparty_id' => $counterparty->id,
        ])->save();

        $revised = app(TransactionRevisor::class)->reviseSinglePair($transaction->fresh(), $user, [
            'revision_reason' => '取引先解除',
            'gross_amount' => 2200,
            'counterparty_name' => null,
        ]);

        $this->assertNull($revised->counterparty_id);
    }

    #[Test]
    public function single_pair改訂では取引先idと取引先名を同時に指定できない(): void
    {
        $user = User::factory()->create();
        $transaction = $this->createSinglePairTransaction($user, 'single pair counterparty conflict');
        $unit = $transaction->fiscalYear->businessUnit;
        $counterparty = Counterparty::factory()->create([
            'business_unit_id' => $unit->id,
            'name' => '競合取引先',
        ]);

        $this->expectException(ValidationException::class);

        try {
            app(TransactionRevisor::class)->reviseSinglePair($transaction, $user, [
                'revision_reason' => '取引先指定競合',
                'gross_amount' => 2200,
                'counterparty_id' => $counterparty->id,
                'counterparty_name' => '別名',
            ]);
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['取引先IDと取引先名は同時に指定できません。'],
                $exception->errors()['counterparty_name'] ?? []
            );

            throw $exception;
        }
    }

    #[Test]
    public function single_pair取引をnet_tax指定で改訂できる(): void
    {
        $user = User::factory()->create();
        $transaction = $this->createSinglePairTransaction($user, 'single pair net tax');
        $unit = $transaction->fiscalYear->businessUnit;
        $newDebit = $unit->getAccountByName('通信費')->subAccounts()->firstOrFail();

        $revised = app(TransactionRevisor::class)->reviseSinglePair($transaction, $user, [
            'revision_reason' => 'single pair net tax 改訂',
            'net_amount' => 1500,
            'tax_amount' => 150,
            'debit_sub_account_id' => $newDebit->id,
            'description' => 'single pair net tax 更新',
        ]);

        $revised->load('journalEntries');

        $debitEntry = $revised->journalEntries->firstWhere('type', JournalEntry::TYPE_DEBIT);
        $creditEntry = $revised->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT);

        $this->assertSame('single pair net tax 更新', $revised->description);
        $this->assertSame($newDebit->id, $debitEntry?->sub_account_id);
        $this->assertSame(1500, $debitEntry?->net_amount);
        $this->assertSame(150, $debitEntry?->tax_amount);
        $this->assertSame(JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10, $debitEntry?->tax_type);
        $this->assertSame(1650, $creditEntry?->net_amount);
        $this->assertSame(0, $creditEntry?->tax_amount);
    }

    #[Test]
    public function single_pair改訂ではgross指定とnet_tax指定を同時に使えない(): void
    {
        $user = User::factory()->create();
        $transaction = $this->createSinglePairTransaction($user, 'single pair validation');

        $this->expectException(ValidationException::class);

        try {
            app(TransactionRevisor::class)->reviseSinglePair($transaction, $user, [
                'revision_reason' => '不正な指定',
                'gross_amount' => 2200,
                'net_amount' => 2000,
                'tax_amount' => 200,
            ]);
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['税込金額指定と税抜金額/消費税額指定は同時に指定できません。'],
                $exception->errors()['gross_amount'] ?? []
            );

            throw $exception;
        }
    }

    #[Test]
    public function single_pair改訂は借方1行貸方1行以外では利用できない(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'single pair 不可']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);
        $expense = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();
        $communication = $unit->getAccountByName('通信費')->subAccounts()->firstOrFail();
        $cash = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        $transaction = app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-04-01',
            'description' => '複数借方取引',
            'created_by' => $user->id,
        ], [
            [
                'sub_account_id' => $expense->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 500,
                'tax_amount' => 50,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            ],
            [
                'sub_account_id' => $communication->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 500,
                'tax_amount' => 50,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => 1100,
                'tax_amount' => 0,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $user);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('single pair 改訂は借方1行・貸方1行の取引でのみ利用できます。');

        app(TransactionRevisor::class)->reviseSinglePair($transaction, $user, [
            'revision_reason' => 'single pair 対象外',
            'gross_amount' => 2200,
        ]);
    }

    #[Test]
    public function 定期取引計画由来の取引は改訂できない(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('定期取引計画由来の取引はこの修正機能の対象外です。');

        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '定期取引改訂不可']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $debitSubAccount = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();
        $creditSubAccount = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        $plan = RecurringTransactionPlan::create([
            'business_unit_id' => $unit->id,
            'name' => '毎月の消耗品費',
            'interval' => 'monthly',
            'day_of_month' => 1,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $debitSubAccount->id,
            'credit_sub_account_id' => $creditSubAccount->id,
            'amount' => 1000,
            'tax_amount' => 100,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            'is_active' => true,
        ]);

        $transaction = app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-04-01',
            'description' => '定期取引由来',
            'recurring_transaction_plan_id' => $plan->id,
        ], [
            [
                'sub_account_id' => $debitSubAccount->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 1000,
                'tax_amount' => 100,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            ],
            [
                'sub_account_id' => $creditSubAccount->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => 1100,
                'tax_amount' => 0,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $fiscalYear->businessUnit->user, );

        app(TransactionRevisor::class)->revise($transaction, $user, [
            'transaction' => [
                'revision_reason' => '改訂不可確認',
            ],
            'journal_entries' => [
                [
                    'sub_account_id' => $debitSubAccount->id,
                    'type' => JournalEntry::TYPE_DEBIT,
                    'net_amount' => 2000,
                    'tax_amount' => 200,
                    'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
                ],
                [
                    'sub_account_id' => $creditSubAccount->id,
                    'type' => JournalEntry::TYPE_CREDIT,
                    'net_amount' => 2200,
                    'tax_amount' => 0,
                    'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                ],
            ],
        ]);
    }

    #[Test]
    public function すでに改訂済みの取引は再度改訂できない(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('無効化済みの取引は修正できません。');

        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '再改訂防止']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $expense = $unit->getAccountByName('通信費')->subAccounts()->firstOrFail();
        $credit = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        $transaction = app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-04-01',
            'description' => '元取引',
        ], [
            [
                'sub_account_id' => $expense->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 1000,
                'tax_amount' => 100,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            ],
            [
                'sub_account_id' => $credit->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => 1100,
                'tax_amount' => 0,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $fiscalYear->businessUnit->user, );

        $revisor = app(TransactionRevisor::class);

        $revisor->revise($transaction, $user, [
            'transaction' => [
                'revision_reason' => '初回改訂',
            ],
            'journal_entries' => [
                [
                    'sub_account_id' => $expense->id,
                    'type' => JournalEntry::TYPE_DEBIT,
                    'net_amount' => 2000,
                    'tax_amount' => 200,
                    'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
                ],
                [
                    'sub_account_id' => $credit->id,
                    'type' => JournalEntry::TYPE_CREDIT,
                    'net_amount' => 2200,
                    'tax_amount' => 0,
                    'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                ],
            ],
        ]);

        $revisor->revise($transaction->fresh(), $user, [
            'transaction' => [
                'revision_reason' => '再改訂',
            ],
            'journal_entries' => [
                [
                    'sub_account_id' => $expense->id,
                    'type' => JournalEntry::TYPE_DEBIT,
                    'net_amount' => 3000,
                    'tax_amount' => 300,
                    'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
                ],
                [
                    'sub_account_id' => $credit->id,
                    'type' => JournalEntry::TYPE_CREDIT,
                    'net_amount' => 3300,
                    'tax_amount' => 0,
                    'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                ],
            ],
        ]);
    }

    #[Test]
    public function 決算済み年度の取引は改訂できない(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '決算済年度改訂不可']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $expense = $unit->getAccountByName('通信費')->subAccounts()->firstOrFail();
        $credit = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        $transaction = app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-04-01',
            'description' => '決算前取引',
        ], [
            [
                'sub_account_id' => $expense->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 1000,
                'tax_amount' => 100,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            ],
            [
                'sub_account_id' => $credit->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => 1100,
                'tax_amount' => 0,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $fiscalYear->businessUnit->user, );

        $fiscalYear->forceFill([
            'is_closed' => true,
        ])->save();

        try {
            app(TransactionRevisor::class)->revise($transaction->fresh(), $user, [
                'transaction' => [
                    'revision_reason' => '決算後改訂',
                ],
                'journal_entries' => [
                    [
                        'sub_account_id' => $expense->id,
                        'type' => JournalEntry::TYPE_DEBIT,
                        'net_amount' => 2000,
                        'tax_amount' => 200,
                        'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
                    ],
                    [
                        'sub_account_id' => $credit->id,
                        'type' => JournalEntry::TYPE_CREDIT,
                        'net_amount' => 2200,
                        'tax_amount' => 0,
                        'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                    ],
                ],
            ]);

            $this->fail('ValidationException が送出されませんでした。');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['決算済みの会計年度に属する取引は修正できません。'],
                $exception->errors()['transaction'] ?? []
            );
        }
    }

    #[Test]
    public function 他ユーザーは取引を修正できない(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '改訂認可テスト']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $debit = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();
        $credit = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        $transaction = app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-04-01',
            'description' => '認可テスト取引',
            'created_by' => $user->id,
        ], [
            ['sub_account_id' => $debit->id, 'type' => JournalEntry::TYPE_DEBIT, 'net_amount' => 1000],
            ['sub_account_id' => $credit->id, 'type' => JournalEntry::TYPE_CREDIT, 'net_amount' => 1000],
        ], $fiscalYear->businessUnit->user);

        $this->expectException(AuthorizationException::class);

        app(TransactionRevisor::class)->revise($transaction, $otherUser, [
            'transaction' => ['revision_reason' => '不正な修正'],
            'journal_entries' => [
                ['sub_account_id' => $debit->id, 'type' => JournalEntry::TYPE_DEBIT, 'net_amount' => 2000],
                ['sub_account_id' => $credit->id, 'type' => JournalEntry::TYPE_CREDIT, 'net_amount' => 2000],
            ],
        ]);
    }
}
