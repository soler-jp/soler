<?php

namespace Tests\Feature;

use App\Models\Counterparty;
use App\Models\JournalEntry;
use App\Models\RecurringTransactionPlan;
use App\Models\User;
use App\Services\TransactionRegistrar;
use App\Services\TransactionRevisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionRevisorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 通常取引を改訂できる(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '改訂テスト事業体']);
        $fiscalYear = $unit->createFiscalYear(2025);

        $originalExpense = $unit->getAccountByName('通信費')->subAccounts()->firstOrFail();
        $revisedExpense = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();
        $originalCredit = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $revisedCredit = $unit->getAccountByName('事業主借')->subAccounts()->firstOrFail();
        $counterparty = Counterparty::factory()->create([
            'business_unit_id' => $unit->id,
            'name' => '改訂前取引先',
        ]);

        $transaction = app(TransactionRegistrar::class)->register(
            $fiscalYear,
            [
                'date' => '2025-04-01',
                'description' => '文房具購入',
                'remarks' => '改訂前備考',
                'counterparty_id' => $counterparty->id,
                'created_by' => $user->id,
            ],
            [
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
                    'tax_type' => JournalEntry::TAX_TYPE_NON_TAXABLE,
                ],
            ],
        );

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
                    'tax_type' => JournalEntry::TAX_TYPE_NON_TAXABLE,
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
    public function 定期取引計画由来の取引は改訂できない(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('定期取引計画由来の取引はこの修正機能の対象外です。');

        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '定期取引改訂不可']);
        $fiscalYear = $unit->createFiscalYear(2025);

        $debitSubAccount = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();
        $creditSubAccount = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        $plan = RecurringTransactionPlan::create([
            'business_unit_id' => $unit->id,
            'name' => '毎月の消耗品費',
            'interval' => 'monthly',
            'day_of_month' => 1,
            'is_income' => false,
            'debit_sub_account_id' => $debitSubAccount->id,
            'credit_sub_account_id' => $creditSubAccount->id,
            'amount' => 1000,
            'tax_amount' => 100,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            'is_active' => true,
        ]);

        $transaction = app(TransactionRegistrar::class)->register(
            $fiscalYear,
            [
                'date' => '2025-04-01',
                'description' => '定期取引由来',
                'recurring_transaction_plan_id' => $plan->id,
            ],
            [
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
                    'tax_type' => JournalEntry::TAX_TYPE_NON_TAXABLE,
                ],
            ],
        );

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
                    'tax_type' => JournalEntry::TAX_TYPE_NON_TAXABLE,
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
        $fiscalYear = $unit->createFiscalYear(2025);

        $expense = $unit->getAccountByName('通信費')->subAccounts()->firstOrFail();
        $credit = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        $transaction = app(TransactionRegistrar::class)->register(
            $fiscalYear,
            [
                'date' => '2025-04-01',
                'description' => '元取引',
            ],
            [
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
                    'tax_type' => JournalEntry::TAX_TYPE_NON_TAXABLE,
                ],
            ],
        );

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
                    'tax_type' => JournalEntry::TAX_TYPE_NON_TAXABLE,
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
                    'tax_type' => JournalEntry::TAX_TYPE_NON_TAXABLE,
                ],
            ],
        ]);
    }

    #[Test]
    public function 決算済み年度の取引は改訂できない(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '決算済年度改訂不可']);
        $fiscalYear = $unit->createFiscalYear(2025);

        $expense = $unit->getAccountByName('通信費')->subAccounts()->firstOrFail();
        $credit = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        $transaction = app(TransactionRegistrar::class)->register(
            $fiscalYear,
            [
                'date' => '2025-04-01',
                'description' => '決算前取引',
            ],
            [
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
                    'tax_type' => JournalEntry::TAX_TYPE_NON_TAXABLE,
                ],
            ],
        );

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
                        'tax_type' => JournalEntry::TAX_TYPE_NON_TAXABLE,
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
}
