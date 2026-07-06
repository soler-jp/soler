<?php

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\User;
use App\Services\FiscalYearRollover;
use App\Services\TransactionRegistrar;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FiscalYearRolloverTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    #[Group('mysql')]
    public function 締め済み年度を翌期へ繰り越して期首仕訳を作成できる(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => '繰越事業体',
        ]);

        $closedYear = $businessUnit->createFiscalYear(2025);
        $nextYear = $businessUnit->createFiscalYear(2026);

        $cash = $businessUnit->getSubAccountByName('現金', '現金');
        $loan = $businessUnit->getSubAccountByName('借入金', '借入金');
        $sales = $businessUnit->getSubAccountByName('売上高', '売上高');
        $expense = $businessUnit->getSubAccountByName('消耗品費', '消耗品費');

        $closedYear->registerOpeningEntry([
            [
                'account_name' => '現金',
                'sub_account_name' => '現金',
                'amount' => 100_000,
            ],
        ]);

        (new TransactionRegistrar)->register($closedYear, [
            'date' => '2025-04-10',
            'description' => '借入',
        ], [
            [
                'sub_account_id' => $cash->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 20_000,
            ],
            [
                'sub_account_id' => $loan->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => 20_000,
            ],
        ]);

        (new TransactionRegistrar)->register($closedYear, [
            'date' => '2025-05-10',
            'description' => '売上',
        ], [
            [
                'sub_account_id' => $cash->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 30_000,
            ],
            [
                'sub_account_id' => $sales->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => 30_000,
            ],
        ]);

        (new TransactionRegistrar)->register($closedYear, [
            'date' => '2025-06-10',
            'description' => '消耗品購入',
        ], [
            [
                'sub_account_id' => $expense->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 10_000,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => 10_000,
            ],
        ]);

        $closedYear->close($user);

        $rolloverData = $closedYear->calculateRolloverData();

        $this->assertSame(2026, $rolloverData['next_year']);
        $this->assertSame([
            [
                'account_name' => '現金',
                'sub_account_name' => '現金',
                'amount' => 140_000,
                'type' => 'debit',
            ],
            [
                'account_name' => '借入金',
                'sub_account_name' => '借入金',
                'amount' => 20_000,
                'type' => 'credit',
            ],
        ], $rolloverData['opening_entries']);
        $this->assertSame([
            'account_name' => '元入金',
            'sub_account_name' => '元入金',
            'amount' => 120_000,
            'type' => 'credit',
        ], $rolloverData['capital_entry']);

        $openingTransaction = app(FiscalYearRollover::class)->rollover($closedYear, $nextYear);

        $this->assertSame('期首残高設定', $openingTransaction->description);
        $this->assertTrue($openingTransaction->is_opening_entry);
        $this->assertCount(3, $openingTransaction->journalEntries);
        $this->assertSame(140_000, $openingTransaction->journalEntries->where('type', 'debit')->sum('net_amount'));
        $this->assertSame(140_000, $openingTransaction->journalEntries->where('type', 'credit')->sum('net_amount'));
        $this->assertDatabaseHas('transactions', [
            'id' => $openingTransaction->id,
            'fiscal_year_id' => $nextYear->id,
            'is_opening_entry' => true,
        ]);
    }

    #[Test]
    #[Group('mysql')]
    public function 元入金がマイナスになる場合は借方の元入金として繰り越せる(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => '債務超過事業体',
        ]);

        $closedYear = $businessUnit->createFiscalYear(2025);
        $nextYear = $businessUnit->createFiscalYear(2026);

        $cash = $businessUnit->getSubAccountByName('現金', '現金');
        $loan = $businessUnit->getSubAccountByName('借入金', '借入金');
        $expense = $businessUnit->getSubAccountByName('消耗品費', '消耗品費');

        $closedYear->registerOpeningEntry([
            [
                'account_name' => '現金',
                'sub_account_name' => '現金',
                'amount' => 50_000,
            ],
        ]);

        (new TransactionRegistrar)->register($closedYear, [
            'date' => '2025-04-10',
            'description' => '借入',
        ], [
            [
                'sub_account_id' => $cash->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 200_000,
            ],
            [
                'sub_account_id' => $loan->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => 200_000,
            ],
        ]);

        (new TransactionRegistrar)->register($closedYear, [
            'date' => '2025-05-10',
            'description' => '消耗品購入',
        ], [
            [
                'sub_account_id' => $expense->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 240_000,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => 240_000,
            ],
        ]);

        $closedYear->close($user);

        $rolloverData = $closedYear->calculateRolloverData();

        $this->assertSame(-240_000, $rolloverData['current_profit']);
        $this->assertSame([
            [
                'account_name' => '現金',
                'sub_account_name' => '現金',
                'amount' => 10_000,
                'type' => 'debit',
            ],
            [
                'account_name' => '借入金',
                'sub_account_name' => '借入金',
                'amount' => 200_000,
                'type' => 'credit',
            ],
        ], $rolloverData['opening_entries']);
        $this->assertSame([
            'account_name' => '元入金',
            'sub_account_name' => '元入金',
            'amount' => 190_000,
            'type' => 'debit',
        ], $rolloverData['capital_entry']);

        $openingTransaction = app(FiscalYearRollover::class)->rollover($closedYear, $nextYear);

        $this->assertCount(3, $openingTransaction->journalEntries);
        $this->assertSame(200_000, $openingTransaction->journalEntries->where('type', 'debit')->sum('net_amount'));
        $this->assertSame(200_000, $openingTransaction->journalEntries->where('type', 'credit')->sum('net_amount'));
        $this->assertSame(10_000, $nextYear->calculateBalanceSummary()['asset']['total_balance']);
        $this->assertSame(200_000, $nextYear->calculateBalanceSummary()['liability']['total_balance']);
        $this->assertSame(-190_000, $nextYear->calculateBalanceSummary()['equity']['total_balance']);
    }

    #[Test]
    #[Group('mysql')]
    public function 翌期首の元入金は国税庁の式のとおり期末元入金と所得と事業主借から事業主貸を引いた額になる(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => '元入金計算事業体',
        ]);

        $closedYear = $businessUnit->createFiscalYear(2025);
        $nextYear = $businessUnit->createFiscalYear(2026);

        $cash = $businessUnit->getSubAccountByName('現金', '現金');
        $sales = $businessUnit->getSubAccountByName('売上高', '売上高');
        $expense = $businessUnit->getSubAccountByName('消耗品費', '消耗品費');
        $ownerLoan = $businessUnit->getSubAccountByName('事業主借', '事業主借');
        $ownerDraw = $businessUnit->getSubAccountByName('事業主貸', '事業主貸');

        // 期末の元入金 500,000
        $closedYear->registerOpeningEntry([
            [
                'account_name' => '現金',
                'sub_account_name' => '現金',
                'amount' => 500_000,
            ],
        ]);

        // 青色申告特別控除前の所得金額 300,000 - 100,000 = 200,000
        (new TransactionRegistrar)->register($closedYear, [
            'date' => '2025-04-10',
            'description' => '売上',
        ], [
            [
                'sub_account_id' => $cash->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 300_000,
            ],
            [
                'sub_account_id' => $sales->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => 300_000,
            ],
        ]);

        (new TransactionRegistrar)->register($closedYear, [
            'date' => '2025-05-10',
            'description' => '消耗品購入',
        ], [
            [
                'sub_account_id' => $expense->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 100_000,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => 100_000,
            ],
        ]);

        // 期末の事業主借 60,000
        (new TransactionRegistrar)->register($closedYear, [
            'date' => '2025-06-10',
            'description' => '家計から事業資金を補填',
        ], [
            [
                'sub_account_id' => $cash->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 60_000,
            ],
            [
                'sub_account_id' => $ownerLoan->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => 60_000,
            ],
        ]);

        // 期末の事業主貸 80,000
        (new TransactionRegistrar)->register($closedYear, [
            'date' => '2025-07-10',
            'description' => '生活費の引き出し',
        ], [
            [
                'sub_account_id' => $ownerDraw->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 80_000,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => 80_000,
            ],
        ]);

        $closedYear->close($user);

        $rolloverData = $closedYear->calculateRolloverData();

        $this->assertSame(200_000, $rolloverData['current_profit']);

        // 翌期首の元入金 = 500,000 + 200,000 + 60,000 - 80,000 = 680,000
        $this->assertSame([
            'account_name' => '元入金',
            'sub_account_name' => '元入金',
            'amount' => 680_000,
            'type' => 'credit',
        ], $rolloverData['capital_entry']);

        // 事業主貸・事業主借は翌期に引き継がず、元入金に振り替えられる
        $this->assertSame([
            [
                'account_name' => '現金',
                'sub_account_name' => '現金',
                'amount' => 680_000,
                'type' => 'debit',
            ],
        ], $rolloverData['opening_entries']);

        $openingTransaction = app(FiscalYearRollover::class)->rollover($closedYear, $nextYear);

        $this->assertCount(2, $openingTransaction->journalEntries);
        $this->assertSame(680_000, $nextYear->calculateBalanceSummary()['asset']['total_balance']);
        $this->assertSame(680_000, $nextYear->calculateBalanceSummary()['equity']['total_balance']);
    }

    #[Test]
    #[Group('mysql')]
    public function 締めていない年度は繰越できない(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => '繰越失敗事業体',
        ]);

        $closedYear = $businessUnit->createFiscalYear(2025);
        $nextYear = $businessUnit->createFiscalYear(2026);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('締め済みの会計年度のみ繰越できます。');

        app(FiscalYearRollover::class)->rollover($closedYear, $nextYear);
    }

    #[Test]
    #[Group('mysql')]
    public function 繰越元と繰越先が同じ事業体でない場合は繰越できない(): void
    {
        $user = User::factory()->create();
        $closedUnit = $user->createBusinessUnitWithDefaults([
            'name' => '繰越元事業体',
        ]);
        $nextUnit = $user->createBusinessUnitWithDefaults([
            'name' => '繰越先事業体',
        ]);

        $closedYear = $closedUnit->createFiscalYear(2025);
        $nextYear = $nextUnit->createFiscalYear(2026);

        $closedYear->close($user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('繰越元と繰越先は同じ事業体でなければなりません。');

        app(FiscalYearRollover::class)->rollover($closedYear, $nextYear);
    }

    #[Test]
    #[Group('mysql')]
    public function 繰越先が翌年度でない場合は繰越できない(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => '翌年度外事業体',
        ]);

        $closedYear = $businessUnit->createFiscalYear(2025);
        $nextYear = $businessUnit->createFiscalYear(2027);

        $closedYear->close($user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('繰越先は翌年度でなければなりません。');

        app(FiscalYearRollover::class)->rollover($closedYear, $nextYear);
    }

    #[Test]
    #[Group('mysql')]
    public function 繰越先にすでに期首仕訳がある場合は繰越できない(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => '期首仕訳あり事業体',
        ]);

        $closedYear = $businessUnit->createFiscalYear(2025);
        $nextYear = $businessUnit->createFiscalYear(2026);

        $closedYear->close($user);
        $nextYear->registerOpeningEntry([
            [
                'account_name' => '現金',
                'sub_account_name' => '現金',
                'amount' => 1_000,
            ],
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('繰越先の会計年度にはすでに期首仕訳があります。');

        app(FiscalYearRollover::class)->rollover($closedYear, $nextYear);
    }

    #[Test]
    #[Group('mysql')]
    public function 繰越データが空の場合は期首仕訳を作成しない(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => '繰越データなし事業体',
        ]);

        $closedYear = $businessUnit->createFiscalYear(2025);
        $nextYear = $businessUnit->createFiscalYear(2026);

        $closedYear->close($user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('繰越する残高がありません。');

        app(FiscalYearRollover::class)->rollover($closedYear, $nextYear);
    }
}
