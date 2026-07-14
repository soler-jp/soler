<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BusinessUnit;
use App\Models\Counterparty;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FiscalYearAccountTypeTransactionSummaryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 月別集計は月を昇順に並べて合計を返す(): void
    {
        [, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;

        $this->registerRevenue($unit, $fiscalYear, '2025-02-01', 2000, '2月売上');
        $this->registerRevenue($unit, $fiscalYear, '2025-01-01', 1000, '1月売上');

        $months = $fiscalYear->monthlyAccountTypeSummaries(Account::TYPE_REVENUE);

        $this->assertSame([
            [
                'year_month' => '2025-01',
                'label' => '2025年1月',
                'amount' => 1000,
            ],
            [
                'year_month' => '2025-02',
                'label' => '2025年2月',
                'amount' => 2000,
            ],
        ], $months);
    }

    #[Test]
    public function 月別集計は逆仕訳を差し引く(): void
    {
        [, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;

        $this->registerRevenue($unit, $fiscalYear, '2025-01-10', 10000, '通常売上');
        $this->registerRevenue($unit, $fiscalYear, '2025-01-20', 3000, '売上取消', reverse: true);
        $this->registerExpense($unit, $fiscalYear, '2025-02-10', 5000, '通常経費');
        $this->registerExpense($unit, $fiscalYear, '2025-02-20', 1000, '経費返金', reverse: true);

        $revenueMonths = $fiscalYear->monthlyAccountTypeSummaries(Account::TYPE_REVENUE);
        $expenseMonths = $fiscalYear->monthlyAccountTypeSummaries(Account::TYPE_EXPENSE);

        $this->assertSame([
            [
                'year_month' => '2025-01',
                'label' => '2025年1月',
                'amount' => 7000,
            ],
        ], $revenueMonths);
        $this->assertSame([
            [
                'year_month' => '2025-02',
                'label' => '2025年2月',
                'amount' => 4000,
            ],
        ], $expenseMonths);
    }

    #[Test]
    public function 月別集計は予定取引と無効取引を除外する(): void
    {
        [, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;

        $this->registerRevenue($unit, $fiscalYear, '2025-01-10', 1000, '確定売上');
        $this->registerRevenue($unit, $fiscalYear, '2025-01-11', 2000, '予定売上')
            ->forceFill(['is_planned' => true])
            ->save();
        $this->registerRevenue($unit, $fiscalYear, '2025-01-12', 3000, '無効売上')
            ->forceFill(['is_active' => false])
            ->save();

        $months = $fiscalYear->monthlyAccountTypeSummaries(Account::TYPE_REVENUE);

        $this->assertSame(1000, $months[0]['amount']);
        $this->assertCount(1, $fiscalYear->monthlyAccountTypeTransactions(Account::TYPE_REVENUE, '2025-01'));
    }

    #[Test]
    public function 月別明細は日付と登録順で表示値を返す(): void
    {
        [, $unit] = $this->createInitializedUser();
        $unit->currentFiscalYear->forceFill(['is_taxable' => true])->save();
        $unit->refresh();

        $fiscalYear = $unit->currentFiscalYear;
        $counterparty = Counterparty::factory()->create(['business_unit_id' => $unit->id, 'name' => 'A社']);

        $this->registerRevenue(
            $unit,
            $fiscalYear,
            '2025-04-02',
            22000,
            '後の売上',
            counterparty: $counterparty,
            taxType: JournalEntry::TAX_TYPE_TAXABLE_SALES_10,
        );
        $this->registerRevenue(
            $unit,
            $fiscalYear,
            '2025-04-01',
            10800,
            '先の売上',
            taxType: JournalEntry::TAX_TYPE_TAXABLE_SALES_8,
        );

        $transactions = $fiscalYear->monthlyAccountTypeTransactions(Account::TYPE_REVENUE, '2025-04');

        $this->assertSame(['2025-04-01', '2025-04-02'], array_column($transactions, 'date'));
        $this->assertSame(10800, $transactions[0]['amount']);
        $this->assertSame('先の売上', $transactions[0]['description']);
        $this->assertSame('', $transactions[0]['counterparty_name']);
        $this->assertSame('8%', $transactions[0]['tax_type_label']);
        $this->assertSame('border-amber-200 bg-amber-50 text-amber-700', $transactions[0]['tax_type_badge_class']);
        $this->assertSame('A社', $transactions[1]['counterparty_name']);
        $this->assertSame('10%', $transactions[1]['tax_type_label']);
        $this->assertSame('border-rose-200 bg-rose-50 text-rose-700', $transactions[1]['tax_type_badge_class']);
    }

    #[Test]
    public function 月別明細は科目フィルタと按分情報を返す(): void
    {
        [, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;
        $counterparty = Counterparty::factory()->create(['business_unit_id' => $unit->id, 'name' => '文具店']);
        $cash = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        $registrar = new TransactionRegistrar;

        $registrar->register($fiscalYear, [
            'date' => '2025-03-15',
            'description' => '在宅作業用品',
            'counterparty_id' => $counterparty->id,
        ], [
            [
                'sub_account_id' => $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail()->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'gross_amount' => 10000,
                'business_ratio' => 60,
                'tax_type' => JournalEntry::TAX_TYPE_NON_TAXABLE,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'gross_amount' => 10000,
                'tax_type' => JournalEntry::TAX_TYPE_NON_TAXABLE,
            ],
        ]);

        $registrar->register($fiscalYear, [
            'date' => '2025-03-20',
            'description' => '仕入れ',
        ], [
            [
                'sub_account_id' => $unit->getAccountByName('仕入金額')->subAccounts()->firstOrFail()->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 12000,
                'tax_type' => JournalEntry::TAX_TYPE_NON_TAXABLE,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => 12000,
                'tax_type' => JournalEntry::TAX_TYPE_NON_TAXABLE,
            ],
        ]);

        $groups = $fiscalYear->monthlyAccountTypeTransactionGroups(
            Account::TYPE_EXPENSE,
            excludedAccountNames: ['仕入金額'],
        );

        $this->assertCount(1, $groups);
        $this->assertSame('2025-03', $groups[0]['year_month']);
        $this->assertSame(6000, $groups[0]['amount']);
        $this->assertCount(1, $groups[0]['transactions']);

        $transaction = $groups[0]['transactions'][0];

        $this->assertSame('2025-03-15', $transaction['date']);
        $this->assertSame(6000, $transaction['amount']);
        $this->assertSame(10000, $transaction['payment_amount']);
        $this->assertSame('在宅作業用品', $transaction['description']);
        $this->assertSame('支払い10,000円の60％分', $transaction['allocation_note']);
        $this->assertSame('消耗品費', $transaction['debit_label']);
        $this->assertSame('現金', $transaction['credit_label']);
        $this->assertSame('非課税', $transaction['tax_type_label']);
        $this->assertSame('文具店', $transaction['counterparty_name']);
        $this->assertStringNotContainsString('家事按分', $transaction['debit_label']);
    }

    #[Test]
    public function 科目名の指定と除外で仕入れと通常経費を分けられる(): void
    {
        [, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;

        $this->registerExpense($unit, $fiscalYear, '2025-05-10', 4000, '通常経費');
        $this->registerExpense($unit, $fiscalYear, '2025-05-11', 9000, '仕入れ', accountName: '仕入金額');

        $expenseMonths = $fiscalYear->monthlyAccountTypeSummaries(
            Account::TYPE_EXPENSE,
            excludedAccountNames: ['仕入金額'],
        );
        $purchaseMonths = $fiscalYear->monthlyAccountTypeSummaries(
            Account::TYPE_EXPENSE,
            accountNames: ['仕入金額'],
        );

        $this->assertSame(4000, $expenseMonths[0]['amount']);
        $this->assertSame(9000, $purchaseMonths[0]['amount']);
        $this->assertSame('通常経費', $fiscalYear->monthlyAccountTypeTransactions(
            Account::TYPE_EXPENSE,
            '2025-05',
            excludedAccountNames: ['仕入金額'],
        )[0]['description']);
        $this->assertSame('仕入れ', $fiscalYear->monthlyAccountTypeTransactions(
            Account::TYPE_EXPENSE,
            '2025-05',
            accountNames: ['仕入金額'],
        )[0]['description']);
    }

    #[Test]
    public function 合計がゼロの月は集計から除外し明細は符号付き金額を返す(): void
    {
        [, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;

        $this->registerRevenue($unit, $fiscalYear, '2025-06-01', 5000, '売上');
        $this->registerRevenue($unit, $fiscalYear, '2025-06-02', 5000, '売上取消', reverse: true);

        $this->assertSame([], $fiscalYear->monthlyAccountTypeSummaries(Account::TYPE_REVENUE));
        $this->assertSame(
            [5000, -5000],
            array_column($fiscalYear->monthlyAccountTypeTransactions(Account::TYPE_REVENUE, '2025-06'), 'amount'),
        );
    }

    #[Test]
    public function 未対応の勘定タイプは例外にする(): void
    {
        [, $unit] = $this->createInitializedUser();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported account type.');

        $unit->currentFiscalYear->monthlyAccountTypeSummaries(Account::TYPE_ASSET);
    }

    /**
     * @return array{0: User, 1: BusinessUnit}
     */
    private function createInitializedUser(): array
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $unit->createFiscalYear(2025);
        $unit->refresh();

        return [$user, $unit];
    }

    private function registerRevenue(
        BusinessUnit $unit,
        FiscalYear $fiscalYear,
        string $date,
        int $amount,
        string $description,
        ?Counterparty $counterparty = null,
        string $taxType = JournalEntry::TAX_TYPE_NON_TAXABLE,
        bool $reverse = false,
    ): Transaction {
        $cash = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $sales = $unit->getAccountByName('売上高')->subAccounts()->firstOrFail();

        return (new TransactionRegistrar)->register($fiscalYear, [
            'date' => $date,
            'description' => $description,
            'counterparty_id' => $counterparty?->id,
        ], [
            [
                'sub_account_id' => $reverse ? $sales->id : $cash->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => $amount,
                'tax_type' => $reverse ? $taxType : JournalEntry::TAX_TYPE_NON_TAXABLE,
            ],
            [
                'sub_account_id' => $reverse ? $cash->id : $sales->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => $amount,
                'tax_type' => $reverse ? JournalEntry::TAX_TYPE_NON_TAXABLE : $taxType,
            ],
        ]);
    }

    private function registerExpense(
        BusinessUnit $unit,
        FiscalYear $fiscalYear,
        string $date,
        int $amount,
        string $description,
        string $accountName = '消耗品費',
        bool $reverse = false,
    ): Transaction {
        $expense = $unit->getAccountByName($accountName)->subAccounts()->firstOrFail();
        $cash = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        return (new TransactionRegistrar)->register($fiscalYear, [
            'date' => $date,
            'description' => $description,
        ], [
            [
                'sub_account_id' => $reverse ? $cash->id : $expense->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => $amount,
                'tax_type' => JournalEntry::TAX_TYPE_NON_TAXABLE,
            ],
            [
                'sub_account_id' => $reverse ? $expense->id : $cash->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => $amount,
                'tax_type' => JournalEntry::TAX_TYPE_NON_TAXABLE,
            ],
        ]);
    }
}
