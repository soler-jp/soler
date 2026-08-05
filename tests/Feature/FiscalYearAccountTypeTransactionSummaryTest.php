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
                'tax_type' => JournalEntry::TAX_TYPE_EXEMPT,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'gross_amount' => 10000,
                'tax_type' => JournalEntry::TAX_TYPE_EXEMPT,
            ],
        ], $fiscalYear->businessUnit->user);

        $registrar->register($fiscalYear, [
            'date' => '2025-03-20',
            'description' => '仕入れ',
        ], [
            [
                'sub_account_id' => $unit->getAccountByName('仕入金額')->subAccounts()->firstOrFail()->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 12000,
                'tax_type' => JournalEntry::TAX_TYPE_EXEMPT,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => 12000,
                'tax_type' => JournalEntry::TAX_TYPE_EXEMPT,
            ],
        ], $fiscalYear->businessUnit->user);

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
    public function 月別明細は新しい税区分ラベルを返す(): void
    {
        [, $unit] = $this->createInitializedUser();
        $unit->currentFiscalYear->forceFill(['is_taxable' => true])->save();
        $unit->refresh();

        $fiscalYear = $unit->currentFiscalYear;

        $this->registerExpense(
            $unit,
            $fiscalYear,
            '2025-04-10',
            3000,
            '対象外経費',
            taxType: JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
        );
        $this->registerRevenue(
            $unit,
            $fiscalYear,
            '2025-04-11',
            5000,
            '免税売上',
            taxType: JournalEntry::TAX_TYPE_ZERO_RATED,
        );

        $expenseTransaction = $fiscalYear->monthlyAccountTypeTransactions(Account::TYPE_EXPENSE, '2025-04')[0];
        $revenueTransaction = $fiscalYear->monthlyAccountTypeTransactions(Account::TYPE_REVENUE, '2025-04')[0];

        $this->assertSame('不課税', $expenseTransaction['tax_type_label']);
        $this->assertSame('免税', $revenueTransaction['tax_type_label']);
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
    public function 経営サマリーカードは仕入れがなければ利益カードを返す(): void
    {
        [, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;

        $this->registerRevenue($unit, $fiscalYear, '2025-07-01', 10000, '売上');
        $this->registerExpense($unit, $fiscalYear, '2025-07-02', 3000, '経費');

        $cards = $fiscalYear->managementSummaryCards();

        $this->assertSame(['revenue', 'expense', 'profit', 'cash_balance'], array_column($cards, 'key'));
        $this->assertSame(7000, $cards[2]['amount']);
        $this->assertSame(
            ['期首商品（棚卸高）', '仕入金額', '期末商品（棚卸高）'],
            $cards[1]['excluded_account_names'],
        );
    }

    #[Test]
    public function 経営サマリーカードは仕入れがあれば仕入れと今の差し引きを返す(): void
    {
        [, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;

        $this->registerRevenue($unit, $fiscalYear, '2025-08-01', 20000, '売上');
        $this->registerExpense($unit, $fiscalYear, '2025-08-02', 4000, '経費');
        $this->registerExpense($unit, $fiscalYear, '2025-08-03', 6000, '仕入れ', accountName: '仕入金額');

        $cards = $fiscalYear->managementSummaryCards();
        $cogsAccountNames = ['期首商品（棚卸高）', '仕入金額', '期末商品（棚卸高）'];

        $this->assertSame(
            ['revenue', 'expense', 'purchase', 'current_difference', 'cash_balance'],
            array_column($cards, 'key'),
        );
        $this->assertSame('仕入れ', $cards[2]['title']);
        $this->assertSame($cogsAccountNames, $cards[1]['excluded_account_names']);
        $this->assertSame(['仕入金額'], $cards[2]['account_names']);
        $this->assertSame(
            [
                'opening_inventory' => 0,
                'purchases' => 6000,
                'ending_inventory' => 0,
                'cost_of_goods_sold' => 6000,
            ],
            $cards[2]['inventory_adjustment'],
        );
        $this->assertSame([], $cards[2]['note_lines'], '棚卸がなければ補足文言は出さない');
        $this->assertSame(10000, $cards[3]['amount']);
        $this->assertSame([
            '売上から、記録済みの経費と仕入(6,000円)を引いた金額です。',
            '年末に在庫を入力すると、最終的な利益は変わることがあります。',
        ], $cards[3]['note_lines']);
    }

    #[Test]
    public function 経営サマリーカードは期末棚卸を経費でなく仕入れの相殺として扱う(): void
    {
        [, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;

        $this->registerRevenue($unit, $fiscalYear, '2025-09-01', 100000, '売上');
        $this->registerExpense($unit, $fiscalYear, '2025-09-02', 30000, '通常経費');
        $this->registerExpense($unit, $fiscalYear, '2025-09-03', 40000, '仕入', accountName: '仕入金額');
        $this->registerClosingInventoryAdjustment($unit, $fiscalYear, '2025-12-31', 12640);

        $cards = $fiscalYear->managementSummaryCards();

        $expenseCard = collect($cards)->firstWhere('key', 'expense');
        $purchaseCard = collect($cards)->firstWhere('key', 'purchase');
        $differenceCard = collect($cards)->firstWhere('key', 'current_difference');

        $expenseAmount = $fiscalYear->monthlyAccountTypeSummaryData(
            Account::TYPE_EXPENSE,
            excludedAccountNames: $expenseCard['excluded_account_names'],
        )['total_amount'];
        $purchaseAmount = $fiscalYear->monthlyAccountTypeSummaryData(
            Account::TYPE_EXPENSE,
            accountNames: $purchaseCard['account_names'],
        )['total_amount'];

        $this->assertSame(30000, $expenseAmount, '期末棚卸で経費が減額されてはいけない');
        $this->assertSame(40000, $purchaseAmount, '仕入れカードには支払った仕入額そのものが出る');

        $this->assertSame(
            [
                'opening_inventory' => 0,
                'purchases' => 40000,
                'ending_inventory' => 12640,
                'cost_of_goods_sold' => 40000 - 12640,
            ],
            $purchaseCard['inventory_adjustment'],
        );
        $this->assertSame([
            'ただし、期末に残っている 12,640 円分を差し引いて、27,360 円を経費として計上します。',
        ], $purchaseCard['note_lines']);
        $this->assertSame(100000 - 30000 - (40000 - 12640), $differenceCard['amount']);
        $this->assertSame(
            '売上から、記録済みの経費と仕入(27,360円)を引いた金額です。',
            $differenceCard['note_lines'][0],
        );
    }

    #[Test]
    public function 仕入れカードのモーダル冒頭に期首と期末の棚卸残を表示する(): void
    {
        [, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;

        $this->registerRevenue($unit, $fiscalYear, '2025-09-01', 100000, '売上');
        $this->registerExpense($unit, $fiscalYear, '2025-09-03', 40000, '仕入', accountName: '仕入金額');
        $this->registerOpeningInventoryAdjustment($unit, $fiscalYear, '2025-01-01', 3000);
        $this->registerClosingInventoryAdjustment($unit, $fiscalYear, '2025-12-15', 12640);

        $cards = $fiscalYear->managementSummaryCards();
        $purchaseCard = collect($cards)->firstWhere('key', 'purchase');

        $this->assertSame(['仕入金額'], $purchaseCard['account_names'], 'モーダル月別は仕入だけを表示');
        $this->assertSame(
            '前期から 3,000 円分の在庫が繰り越されていて、期末には 12,640 円分が残っています。',
            $purchaseCard['modal_header_note'],
        );
        $this->assertArrayNotHasKey(
            'drill_account_names',
            $purchaseCard,
            '棚卸仕訳は集計に混ぜないので drill 用の絞り込みは不要',
        );
    }

    #[Test]
    public function 仕入れカードのモーダルnoteは期末のみでも文言を出す(): void
    {
        [, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;

        $this->registerRevenue($unit, $fiscalYear, '2025-09-01', 100000, '売上');
        $this->registerExpense($unit, $fiscalYear, '2025-09-03', 40000, '仕入', accountName: '仕入金額');
        $this->registerClosingInventoryAdjustment($unit, $fiscalYear, '2025-12-15', 12640);

        $purchaseCard = collect($fiscalYear->managementSummaryCards())->firstWhere('key', 'purchase');

        $this->assertSame(
            '期末には 12,640 円分の在庫が残っています。',
            $purchaseCard['modal_header_note'],
        );
    }

    #[Test]
    public function 仕入れカードのモーダルnoteは棚卸がなければ空になる(): void
    {
        [, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;

        $this->registerRevenue($unit, $fiscalYear, '2025-08-01', 20000, '売上');
        $this->registerExpense($unit, $fiscalYear, '2025-08-03', 6000, '仕入', accountName: '仕入金額');

        $purchaseCard = collect($fiscalYear->managementSummaryCards())->firstWhere('key', 'purchase');

        $this->assertSame('', $purchaseCard['modal_header_note']);
    }

    #[Test]
    public function 経営サマリーカードは期首棚卸を仕入れの補足で表示する(): void
    {
        [, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;

        $this->registerRevenue($unit, $fiscalYear, '2025-10-01', 50000, '売上');
        $this->registerExpense($unit, $fiscalYear, '2025-10-02', 5000, '通常経費');
        $this->registerOpeningInventoryAdjustment($unit, $fiscalYear, '2025-01-01', 8000);
        $this->registerExpense($unit, $fiscalYear, '2025-10-03', 20000, '仕入', accountName: '仕入金額');
        $this->registerClosingInventoryAdjustment($unit, $fiscalYear, '2025-12-31', 5000);

        $cards = $fiscalYear->managementSummaryCards();
        $purchaseCard = collect($cards)->firstWhere('key', 'purchase');

        $this->assertSame(
            [
                'opening_inventory' => 8000,
                'purchases' => 20000,
                'ending_inventory' => 5000,
                'cost_of_goods_sold' => 8000 + 20000 - 5000,
            ],
            $purchaseCard['inventory_adjustment'],
        );
        $this->assertSame([
            '前年から繰り越した在庫 8,000 円を加算します。',
            'ただし、期末に残っている 5,000 円分を差し引いて、23,000 円を経費として計上します。',
        ], $purchaseCard['note_lines']);
    }

    #[Test]
    public function 経営サマリーカードは期末棚卸のみでも仕入れカードを表示し経費から除外する(): void
    {
        [, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;

        $this->registerRevenue($unit, $fiscalYear, '2025-11-01', 30000, '売上');
        $this->registerExpense($unit, $fiscalYear, '2025-11-02', 5000, '通常経費');
        $this->registerClosingInventoryAdjustment($unit, $fiscalYear, '2025-12-31', 3000);

        $cards = $fiscalYear->managementSummaryCards();

        $this->assertSame(
            ['revenue', 'expense', 'purchase', 'current_difference', 'cash_balance'],
            array_column($cards, 'key'),
        );

        $expenseCard = $cards[1];
        $purchaseCard = $cards[2];
        $differenceCard = $cards[3];

        $expenseAmount = $fiscalYear->monthlyAccountTypeSummaryData(
            Account::TYPE_EXPENSE,
            excludedAccountNames: $expenseCard['excluded_account_names'],
        )['total_amount'];

        $this->assertSame(5000, $expenseAmount, '経費は棚卸で減額されない');
        $this->assertSame(
            [
                'opening_inventory' => 0,
                'purchases' => 0,
                'ending_inventory' => 3000,
                'cost_of_goods_sold' => -3000,
            ],
            $purchaseCard['inventory_adjustment'],
        );
        $this->assertSame(30000 - 5000 - (-3000), $differenceCard['amount']);
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
        $unit->createFiscalYear(2025, $user);
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
        string $taxType = JournalEntry::TAX_TYPE_EXEMPT,
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
                'tax_type' => $reverse ? $taxType : JournalEntry::TAX_TYPE_EXEMPT,
            ],
            [
                'sub_account_id' => $reverse ? $cash->id : $sales->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => $amount,
                'tax_type' => $reverse ? JournalEntry::TAX_TYPE_EXEMPT : $taxType,
            ],
        ], $fiscalYear->businessUnit->user);
    }

    private function registerExpense(
        BusinessUnit $unit,
        FiscalYear $fiscalYear,
        string $date,
        int $amount,
        string $description,
        string $accountName = '消耗品費',
        string $taxType = JournalEntry::TAX_TYPE_EXEMPT,
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
                'tax_type' => $taxType,
            ],
            [
                'sub_account_id' => $reverse ? $expense->id : $cash->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => $amount,
                'tax_type' => $reverse ? $taxType : JournalEntry::TAX_TYPE_EXEMPT,
            ],
        ], $fiscalYear->businessUnit->user);
    }

    private function registerClosingInventoryAdjustment(
        BusinessUnit $unit,
        FiscalYear $fiscalYear,
        string $date,
        int $amount,
    ): Transaction {
        $inventoryAsset = $unit->getAccountByName('棚卸資産')->subAccounts()->firstOrFail();
        $closing = $unit->getAccountByName('期末商品（棚卸高）')->subAccounts()->firstOrFail();

        return (new TransactionRegistrar)->register($fiscalYear, [
            'date' => $date,
            'description' => '期末棚卸',
        ], [
            [
                'sub_account_id' => $inventoryAsset->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => $amount,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $closing->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => $amount,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $fiscalYear->businessUnit->user);
    }

    private function registerOpeningInventoryAdjustment(
        BusinessUnit $unit,
        FiscalYear $fiscalYear,
        string $date,
        int $amount,
    ): Transaction {
        $inventoryAsset = $unit->getAccountByName('棚卸資産')->subAccounts()->firstOrFail();
        $opening = $unit->getAccountByName('期首商品（棚卸高）')->subAccounts()->firstOrFail();

        return (new TransactionRegistrar)->register($fiscalYear, [
            'date' => $date,
            'description' => '期首棚卸',
        ], [
            [
                'sub_account_id' => $opening->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => $amount,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $inventoryAsset->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => $amount,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $fiscalYear->businessUnit->user);
    }
}
