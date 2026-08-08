<?php

namespace Tests\Feature;

use App\Models\Counterparty;
use App\Models\JournalEntry;
use App\Models\RecurringTransactionPlan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RecurringIncomeRealizationService;
use App\Services\TransactionRegistrar;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecurringIncomeRealizationServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function aスポーツクラブの1月分予定売上を同月受取で実現できる(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '月次委託収入実現テスト']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $counterparty = Counterparty::factory()->create([
            'business_unit_id' => $unit->id,
            'name' => 'Aスポーツクラブ',
        ]);

        $depositSubAccount = $unit->getSubAccountByName('その他の預金', 'その他の預金');
        $salesSubAccount = $unit->getSubAccountByName('売上高', '売上高');
        $withholdingSubAccount = $unit->getSubAccountByName('事業主貸', RecurringTransactionPlan::WITHHOLDING_SUB_ACCOUNT_NAME);

        $plan = $unit->createRecurringTransactionPlan([
            'name' => 'インストラクター業務委託',
            'interval' => 'monthly',
            'day_of_month' => 25,
            'type' => RecurringTransactionPlan::TYPE_INCOME,
            'counterparty_id' => $counterparty->id,
            'is_withholding' => true,
            'debit_sub_account_id' => $depositSubAccount->id,
            'credit_sub_account_id' => $salesSubAccount->id,
            'amount' => 100000,
            'tax_amount' => 10000,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_SALES_10,
            'withholding_tax_amount' => 10210,
            'withholding_sub_account_id' => $withholdingSubAccount->id,
        ], $user);

        $plannedTransaction = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user)->firstOrFail();

        $realizedTransactions = app(RecurringIncomeRealizationService::class)->realize(
            $plannedTransaction,
            [
                'amount' => 110000,
                'withholding_tax_amount' => 10210,
                'receipt_date' => '2025-01-25',
                'receipt_sub_account_id' => $depositSubAccount->id,
            ],
            $user,
        );

        $this->assertCount(1, $realizedTransactions);

        $realizedTransaction = $realizedTransactions->firstOrFail()->fresh('journalEntries');
        $debitEntries = $realizedTransaction->journalEntries
            ->where('type', JournalEntry::TYPE_DEBIT)
            ->values();
        $creditEntry = $realizedTransaction->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT);

        $this->assertFalse($realizedTransaction->is_planned);
        $this->assertSame($counterparty->id, $realizedTransaction->counterparty_id);
        $this->assertSame('1月分 インストラクター業務委託', $realizedTransaction->description);
        $this->assertNull($realizedTransaction->settled_transaction_id);
        $this->assertCount(2, $debitEntries);
        $this->assertSame(99790, $debitEntries[0]->net_amount);
        $this->assertSame($depositSubAccount->id, $debitEntries[0]->sub_account_id);
        $this->assertSame(10210, $debitEntries[1]->net_amount);
        $this->assertSame($withholdingSubAccount->id, $debitEntries[1]->sub_account_id);
        $this->assertSame(100000, $creditEntry?->net_amount);
        $this->assertSame(10000, $creditEntry?->tax_amount);
    }

    #[Test]
    public function aスポーツクラブの1月分予定売上を翌月入金で売掛金経由に実現できる(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '月次委託収入売掛実現テスト']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $counterparty = Counterparty::factory()->create([
            'business_unit_id' => $unit->id,
            'name' => 'Aスポーツクラブ',
        ]);

        $depositSubAccount = $unit->getSubAccountByName('その他の預金', 'その他の預金');
        $salesSubAccount = $unit->getSubAccountByName('売上高', '売上高');
        $accountsReceivableSubAccount = $unit->getSubAccountByName('売掛金', '売掛金');
        $withholdingSubAccount = $unit->getSubAccountByName('事業主貸', RecurringTransactionPlan::WITHHOLDING_SUB_ACCOUNT_NAME);

        $plan = $unit->createRecurringTransactionPlan([
            'name' => 'インストラクター業務委託',
            'interval' => 'monthly',
            'day_of_month' => 25,
            'type' => RecurringTransactionPlan::TYPE_INCOME,
            'counterparty_id' => $counterparty->id,
            'is_withholding' => true,
            'debit_sub_account_id' => $depositSubAccount->id,
            'credit_sub_account_id' => $salesSubAccount->id,
            'amount' => 100000,
            'tax_amount' => 10000,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_SALES_10,
            'withholding_tax_amount' => 10210,
            'withholding_sub_account_id' => $withholdingSubAccount->id,
        ], $user);

        $plannedTransaction = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user)->firstOrFail();

        $realizedTransactions = app(RecurringIncomeRealizationService::class)->realize(
            $plannedTransaction,
            [
                'amount' => 110000,
                'withholding_tax_amount' => 10210,
                'receipt_date' => '2025-02-10',
                'receipt_sub_account_id' => $depositSubAccount->id,
            ],
            $user,
        );

        $this->assertCount(2, $realizedTransactions);

        /** @var Transaction $salesTransaction */
        $salesTransaction = $realizedTransactions->firstOrFail()->fresh('journalEntries');
        /** @var Transaction $settlementTransaction */
        $settlementTransaction = $realizedTransactions->last()->fresh('journalEntries', 'settledTransaction');

        $salesDebitEntry = $salesTransaction->journalEntries->firstWhere('type', JournalEntry::TYPE_DEBIT);
        $salesCreditEntry = $salesTransaction->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT);
        $settlementDebitEntries = $settlementTransaction->journalEntries
            ->where('type', JournalEntry::TYPE_DEBIT)
            ->values();
        $settlementCreditEntry = $settlementTransaction->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT);

        $this->assertFalse($salesTransaction->is_planned);
        $this->assertSame('2025-01-25', $salesTransaction->date->toDateString());
        $this->assertSame($accountsReceivableSubAccount->id, $salesDebitEntry?->sub_account_id);
        $this->assertSame(110000, $salesDebitEntry?->net_amount);
        $this->assertSame(100000, $salesCreditEntry?->net_amount);
        $this->assertSame(10000, $salesCreditEntry?->tax_amount);

        $this->assertSame('2025-02-10', $settlementTransaction->date->toDateString());
        $this->assertSame($salesTransaction->id, $settlementTransaction->settled_transaction_id);
        $this->assertSame($salesTransaction->id, $settlementTransaction->settledTransaction?->id);
        $this->assertNull($settlementTransaction->recurring_transaction_plan_id);
        $this->assertCount(2, $settlementDebitEntries);
        $this->assertSame(99790, $settlementDebitEntries[0]->net_amount);
        $this->assertSame($depositSubAccount->id, $settlementDebitEntries[0]->sub_account_id);
        $this->assertSame(10210, $settlementDebitEntries[1]->net_amount);
        $this->assertSame($withholdingSubAccount->id, $settlementDebitEntries[1]->sub_account_id);
        $this->assertSame(110000, $settlementCreditEntry?->net_amount);
        $this->assertSame($accountsReceivableSubAccount->id, $settlementCreditEntry?->sub_account_id);
    }

    #[Test]
    public function aスポーツクラブの1月分予定売上を翌月に事業主貸で売掛金経由に実現できる(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '月次委託収入事業主貸実現テスト']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $counterparty = Counterparty::factory()->create([
            'business_unit_id' => $unit->id,
            'name' => 'Aスポーツクラブ',
        ]);

        $ownerDrawSubAccount = $unit->getSubAccountByName('事業主貸', '事業主貸');
        $salesSubAccount = $unit->getSubAccountByName('売上高', '売上高');
        $withholdingSubAccount = $unit->getSubAccountByName('事業主貸', RecurringTransactionPlan::WITHHOLDING_SUB_ACCOUNT_NAME);

        $plan = $unit->createRecurringTransactionPlan([
            'name' => 'インストラクター業務委託',
            'interval' => 'monthly',
            'day_of_month' => 25,
            'type' => RecurringTransactionPlan::TYPE_INCOME,
            'counterparty_id' => $counterparty->id,
            'is_withholding' => true,
            'debit_sub_account_id' => $ownerDrawSubAccount->id,
            'credit_sub_account_id' => $salesSubAccount->id,
            'amount' => 100000,
            'tax_amount' => 10000,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_SALES_10,
            'withholding_tax_amount' => 10210,
            'withholding_sub_account_id' => $withholdingSubAccount->id,
        ], $user);

        $plannedTransaction = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user)->firstOrFail();

        $realizedTransactions = app(RecurringIncomeRealizationService::class)->realize(
            $plannedTransaction,
            [
                'amount' => 110000,
                'withholding_tax_amount' => 10210,
                'receipt_date' => '2025-02-10',
                'receipt_sub_account_id' => $ownerDrawSubAccount->id,
            ],
            $user,
        );

        $this->assertCount(2, $realizedTransactions);

        /** @var Transaction $salesTransaction */
        $salesTransaction = $realizedTransactions->firstOrFail()->fresh('journalEntries');
        /** @var Transaction $settlementTransaction */
        $settlementTransaction = $realizedTransactions->last()->fresh('journalEntries', 'settledTransaction');
        $salesDebitEntry = $salesTransaction->journalEntries->firstWhere('type', JournalEntry::TYPE_DEBIT);
        $salesCreditEntry = $salesTransaction->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT);
        $debitEntries = $settlementTransaction->journalEntries
            ->where('type', JournalEntry::TYPE_DEBIT)
            ->values();
        $creditEntry = $settlementTransaction->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT);

        $this->assertFalse($salesTransaction->is_planned);
        $this->assertSame('2025-01-25', $salesTransaction->date->toDateString());
        $this->assertSame($salesSubAccount->id, $salesCreditEntry?->sub_account_id);
        $this->assertSame(100000, $salesCreditEntry?->net_amount);
        $this->assertSame(10000, $salesCreditEntry?->tax_amount);
        $this->assertSame('2025-02-10', $settlementTransaction->date->toDateString());
        $this->assertSame($salesTransaction->id, $settlementTransaction->settled_transaction_id);
        $this->assertSame($salesTransaction->id, $settlementTransaction->settledTransaction?->id);
        $this->assertSame(110000, $salesDebitEntry?->net_amount);
        $this->assertCount(2, $debitEntries);
        $this->assertSame(99790, $debitEntries[0]->net_amount);
        $this->assertSame($ownerDrawSubAccount->id, $debitEntries[0]->sub_account_id);
        $this->assertSame(10210, $debitEntries[1]->net_amount);
        $this->assertSame($withholdingSubAccount->id, $debitEntries[1]->sub_account_id);
        $this->assertSame(110000, $creditEntry?->net_amount);
        $this->assertSame('売掛金', $creditEntry?->subAccount?->name);
        $this->assertSame(0, $creditEntry?->tax_amount);
    }

    #[Test]
    public function 売掛回収仕訳の登録失敗時は売上確定もロールバックされる(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '月次委託収入ロールバックテスト']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $counterparty = Counterparty::factory()->create([
            'business_unit_id' => $unit->id,
            'name' => 'Aスポーツクラブ',
        ]);

        $depositSubAccount = $unit->getSubAccountByName('その他の預金', 'その他の預金');
        $salesSubAccount = $unit->getSubAccountByName('売上高', '売上高');
        $withholdingSubAccount = $unit->getSubAccountByName('事業主貸', RecurringTransactionPlan::WITHHOLDING_SUB_ACCOUNT_NAME);

        $plan = $unit->createRecurringTransactionPlan([
            'name' => 'インストラクター業務委託',
            'interval' => 'monthly',
            'day_of_month' => 25,
            'type' => RecurringTransactionPlan::TYPE_INCOME,
            'counterparty_id' => $counterparty->id,
            'is_withholding' => true,
            'debit_sub_account_id' => $depositSubAccount->id,
            'credit_sub_account_id' => $salesSubAccount->id,
            'amount' => 100000,
            'tax_amount' => 10000,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_SALES_10,
            'withholding_tax_amount' => 10210,
            'withholding_sub_account_id' => $withholdingSubAccount->id,
        ], $user);

        $plannedTransaction = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user)->firstOrFail();
        $originalEntryIds = $plannedTransaction->journalEntries()->pluck('id')->all();
        $originalTransactionCount = Transaction::query()->count();

        $registrar = Mockery::mock(TransactionRegistrar::class)->makePartial();
        $registrar->shouldReceive('register')
            ->once()
            ->andThrow(new DomainException('回収仕訳登録失敗'));
        app()->instance(TransactionRegistrar::class, $registrar);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('回収仕訳登録失敗');

        try {
            app(RecurringIncomeRealizationService::class)->realize(
                $plannedTransaction,
                [
                    'amount' => 110000,
                    'withholding_tax_amount' => 10210,
                    'receipt_date' => '2025-02-10',
                    'receipt_sub_account_id' => $depositSubAccount->id,
                ],
                $user,
            );
        } finally {
            $plannedTransaction = $plannedTransaction->fresh('journalEntries');

            $this->assertNotNull($plannedTransaction);
            $this->assertTrue($plannedTransaction->is_planned);
            $this->assertSame('2025-01-25', $plannedTransaction->date?->toDateString());
            $this->assertSame($originalEntryIds, $plannedTransaction->journalEntries()->pluck('id')->all());
            $this->assertSame($originalTransactionCount, Transaction::query()->count());
        }
    }

    #[Test]
    public function 未来の受取日は回収仕訳を予定取引として残す(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-08 10:00:00'));

        try {
            $user = User::factory()->create();
            $unit = $user->createBusinessUnitWithDefaults(['name' => '未来入金予定回収テスト']);
            $fiscalYear = $unit->createFiscalYear(2026, $user);

            $counterparty = Counterparty::factory()->create([
                'business_unit_id' => $unit->id,
                'name' => 'Aスポーツクラブ',
            ]);

            $depositSubAccount = $unit->getSubAccountByName('その他の預金', 'その他の預金');
            $salesSubAccount = $unit->getSubAccountByName('売上高', '売上高');
            $accountsReceivableSubAccount = $unit->getSubAccountByName('売掛金', '売掛金');
            $withholdingSubAccount = $unit->getSubAccountByName('事業主貸', RecurringTransactionPlan::WITHHOLDING_SUB_ACCOUNT_NAME);

            $plan = $unit->createRecurringTransactionPlan([
                'name' => 'インストラクター業務委託',
                'interval' => 'monthly',
                'day_of_month' => 25,
                'type' => RecurringTransactionPlan::TYPE_INCOME,
                'counterparty_id' => $counterparty->id,
                'is_withholding' => true,
                'debit_sub_account_id' => $depositSubAccount->id,
                'credit_sub_account_id' => $salesSubAccount->id,
                'amount' => 100000,
                'tax_amount' => 10000,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_SALES_10,
                'withholding_tax_amount' => 10210,
                'withholding_sub_account_id' => $withholdingSubAccount->id,
            ], $user);

            $plannedTransaction = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user)
                ->firstWhere('date', Carbon::parse('2026-07-25'));

            $this->assertNotNull($plannedTransaction);

            $realizedTransactions = app(RecurringIncomeRealizationService::class)->realize(
                $plannedTransaction,
                [
                    'amount' => 110000,
                    'withholding_tax_amount' => 10210,
                    'receipt_date' => '2026-08-25',
                    'receipt_sub_account_id' => $depositSubAccount->id,
                ],
                $user,
            );

            /** @var Transaction $salesTransaction */
            $salesTransaction = $realizedTransactions->firstOrFail()->fresh('journalEntries');
            /** @var Transaction $settlementTransaction */
            $settlementTransaction = $realizedTransactions->last()->fresh('journalEntries', 'settledTransaction');
            $settlementCreditEntry = $settlementTransaction->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT);

            $this->assertFalse($salesTransaction->is_planned);
            $this->assertSame('2026-07-25', $salesTransaction->date->toDateString());
            $this->assertTrue($settlementTransaction->is_planned);
            $this->assertSame('2026-08-25', $settlementTransaction->date->toDateString());
            $this->assertSame($salesTransaction->id, $settlementTransaction->settled_transaction_id);
            $this->assertSame($accountsReceivableSubAccount->id, $settlementCreditEntry?->sub_account_id);
            $this->assertSame(110000, $settlementCreditEntry?->net_amount);
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function 予定日より前の受取日はまだ実現できない(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '月次委託収入実現バリデーション']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $counterparty = Counterparty::factory()->create([
            'business_unit_id' => $unit->id,
            'name' => 'Aスポーツクラブ',
        ]);

        $depositSubAccount = $unit->getSubAccountByName('その他の預金', 'その他の預金');
        $salesSubAccount = $unit->getSubAccountByName('売上高', '売上高');
        $withholdingSubAccount = $unit->getSubAccountByName('事業主貸', RecurringTransactionPlan::WITHHOLDING_SUB_ACCOUNT_NAME);

        $plan = $unit->createRecurringTransactionPlan([
            'name' => 'インストラクター業務委託',
            'interval' => 'monthly',
            'day_of_month' => 25,
            'type' => RecurringTransactionPlan::TYPE_INCOME,
            'counterparty_id' => $counterparty->id,
            'is_withholding' => true,
            'debit_sub_account_id' => $depositSubAccount->id,
            'credit_sub_account_id' => $salesSubAccount->id,
            'amount' => 100000,
            'tax_amount' => 10000,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_SALES_10,
            'withholding_tax_amount' => 10210,
            'withholding_sub_account_id' => $withholdingSubAccount->id,
        ], $user);

        $plannedTransaction = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user)->firstOrFail();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('この実装では、別の月で予定日より前の受取日は実現できません。');

        app(RecurringIncomeRealizationService::class)->realize(
            $plannedTransaction,
            [
                'amount' => 110000,
                'withholding_tax_amount' => 10210,
                'receipt_date' => '2024-12-31',
                'receipt_sub_account_id' => $depositSubAccount->id,
            ],
            $user,
        );
    }

    #[Test]
    public function b株式会社の_h_p保守費用年払いは受取日を売上日として実現できる(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '年払い保守収入実現テスト']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $counterparty = Counterparty::factory()->create([
            'business_unit_id' => $unit->id,
            'name' => 'B株式会社',
        ]);

        $depositSubAccount = $unit->getSubAccountByName('その他の預金', 'その他の預金');
        $salesSubAccount = $unit->getSubAccountByName('売上高', '売上高');

        $plan = $unit->createRecurringTransactionPlan([
            'name' => 'HP保守費用',
            'interval' => 'yearly',
            'month_of_year' => 4,
            'day_of_month' => 30,
            'type' => RecurringTransactionPlan::TYPE_INCOME,
            'counterparty_id' => $counterparty->id,
            'debit_sub_account_id' => $depositSubAccount->id,
            'credit_sub_account_id' => $salesSubAccount->id,
            'amount' => 240000,
            'tax_amount' => 24000,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_SALES_10,
        ], $user);

        $plannedTransaction = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user)->firstOrFail();

        $realizedTransactions = app(RecurringIncomeRealizationService::class)->realize(
            $plannedTransaction,
            [
                'amount' => 264000,
                'withholding_tax_amount' => 0,
                'receipt_date' => '2025-05-31',
                'receipt_sub_account_id' => $depositSubAccount->id,
            ],
            $user,
        );

        $this->assertCount(1, $realizedTransactions);

        /** @var Transaction $realizedTransaction */
        $realizedTransaction = $realizedTransactions->firstOrFail()->fresh('journalEntries');
        $debitEntry = $realizedTransaction->journalEntries->firstWhere('type', JournalEntry::TYPE_DEBIT);
        $creditEntry = $realizedTransaction->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT);

        $this->assertFalse($realizedTransaction->is_planned);
        $this->assertSame('2025-05-31', $realizedTransaction->date->toDateString());
        $this->assertSame($counterparty->id, $realizedTransaction->counterparty_id);
        $this->assertSame('2025年分 HP保守費用', $realizedTransaction->description);
        $this->assertNull($realizedTransaction->settled_transaction_id);
        $this->assertSame(264000, $debitEntry?->net_amount);
        $this->assertSame($depositSubAccount->id, $debitEntry?->sub_account_id);
        $this->assertSame(240000, $creditEntry?->net_amount);
        $this->assertSame(24000, $creditEntry?->tax_amount);
        $this->assertSame($salesSubAccount->id, $creditEntry?->sub_account_id);
    }
}
