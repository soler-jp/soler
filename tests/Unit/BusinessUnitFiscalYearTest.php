<?php

namespace Tests\Unit;

use App\Models\BusinessUnit;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BlueReturnStatementCalculator;
use App\Services\FiscalYearRollover;
use App\Services\TransactionRegistrar;
use App\Validators\FiscalYearValidator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BusinessUnitFiscalYearTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function create_fiscal_yearは年だけ指定して正しく作成される()
    {
        $user = User::factory()->create();
        $businessUnit = BusinessUnit::factory()->create(['user_id' => $user->id]);

        $fiscalYear = $businessUnit->createFiscalYear(2025, $user);

        $this->assertInstanceOf(FiscalYear::class, $fiscalYear);
        $this->assertEquals(2025, $fiscalYear->year);
        $this->assertEquals('2025-01-01', $fiscalYear->start_date->toDateString());
        $this->assertEquals('2025-12-31', $fiscalYear->end_date->toDateString());
        $this->assertFalse($fiscalYear->is_closed);
        $this->assertEquals($businessUnit->id, $fiscalYear->business_unit_id);

        $this->assertDatabaseHas('fiscal_years', [
            'id' => $fiscalYear->id,
            'year' => 2025,
        ]);
    }

    #[Test]
    public function 他ユーザーはfiscal_yearを作成できない()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $businessUnit = BusinessUnit::factory()->create(['user_id' => $user->id]);

        $this->expectException(AuthorizationException::class);

        $businessUnit->createFiscalYear(2025, $otherUser);
    }

    #[Test]
    public function 年度の重複でバリデーションエラーになる()
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業',
            'type' => BusinessUnit::TYPE_GENERAL,
        ]);

        $fiscalYear = $businessUnit->createFiscalYear(2025, $user);
        $this->assertEquals(2025, $fiscalYear->year);

        // 同じyearで重複登録しようとしてバリデーションエラー
        $this->expectException(ValidationException::class);

        FiscalYearValidator::validate([
            'business_unit_id' => $businessUnit->id,
            'year' => 2025,
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
            'is_closed' => false,
        ]);
    }

    #[Test]
    public function 初回作成した_fiscal_yearは自動でis_activeになる()
    {
        $user = User::factory()->create();
        $businessUnit = BusinessUnit::factory()->create(['user_id' => $user->id]);

        $this->assertDatabaseMissing('fiscal_years', [
            'business_unit_id' => $businessUnit->id,
        ]);

        $fiscalYear = $businessUnit->createFiscalYear(2025, $user);
        $fiscalYear->refresh(); // DBから最新の状態を取得

        $this->assertTrue($fiscalYear->is_active);
        $this->assertDatabaseHas('fiscal_years', [
            'id' => $fiscalYear->id,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function fiscal_year作成時に2つ目以降はis_activeがfalseで作成される()
    {
        $user = User::factory()->create();
        $businessUnit = BusinessUnit::factory()->create(['user_id' => $user->id]);

        // 1つ目作成でactiveになる
        $businessUnit->createFiscalYear(2024, $user);

        // 2つ目作成は非active
        $fiscalYear2 = $businessUnit->createFiscalYear(2025, $user);

        $this->assertFalse($fiscalYear2->is_active);
        $this->assertDatabaseHas('fiscal_years', [
            'id' => $fiscalYear2->id,
            'is_active' => false,
        ]);
    }

    #[Test]
    public function closeで会計年度を決算済みにできる()
    {
        $user = User::factory()->create();
        $businessUnit = BusinessUnit::factory()->create(['user_id' => $user->id]);

        $fiscalYear = $businessUnit->createFiscalYear(2025, $user);

        $closedFiscalYear = $fiscalYear->close($user);

        $this->assertTrue($closedFiscalYear->is_closed);
        $this->assertFalse($closedFiscalYear->is_active);
        $this->assertSame($user->id, $closedFiscalYear->closed_by);
        $this->assertNotNull($closedFiscalYear->closed_at);
        $this->assertDatabaseHas('fiscal_years', [
            'id' => $fiscalYear->id,
            'is_closed' => true,
            'is_active' => false,
            'closed_by' => $user->id,
        ]);
    }

    #[Test]
    public function blue_return_statementの集計はサービスに委譲される()
    {
        $user = User::factory()->create();
        $businessUnit = BusinessUnit::factory()->create(['user_id' => $user->id]);

        $fiscalYear = $businessUnit->createFiscalYear(2025, $user);

        $calculator = new class
        {
            public ?FiscalYear $receivedFiscalYear = null;

            public ?int $receivedBlueReturnDeduction = null;

            public function calculate(FiscalYear $fiscalYear, int $blueReturnDeduction): array
            {
                $this->receivedFiscalYear = $fiscalYear;
                $this->receivedBlueReturnDeduction = $blueReturnDeduction;

                return ['called' => true];
            }
        };

        app()->instance(BlueReturnStatementCalculator::class, $calculator);

        $result = app(BlueReturnStatementCalculator::class)->calculate($fiscalYear, 650000);

        $this->assertSame(['called' => true], $result);
        $this->assertSame($fiscalYear->id, $calculator->receivedFiscalYear?->id);
        $this->assertSame(650000, $calculator->receivedBlueReturnDeduction);
    }

    #[Test]
    public function すでに決算済みの会計年度を_closeしようとすると例外になる()
    {
        $user = User::factory()->create();
        $businessUnit = BusinessUnit::factory()->create(['user_id' => $user->id]);

        $fiscalYear = $businessUnit->createFiscalYear(2025, $user);
        $fiscalYear->update(['is_closed' => true, 'is_active' => false]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('この会計年度はすでに決算済みです。');

        $fiscalYear->close($user);
    }

    #[Test]
    #[Group('mysql')]
    public function rollover_dataから翌期の_fiscal_yearと期首仕訳を作成できる()
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => '繰越事業体',
        ]);

        $fiscalYear = $businessUnit->createFiscalYear(2025, $user);

        $cash = $businessUnit->getSubAccountByName('現金', '現金');
        $sales = $businessUnit->getSubAccountByName('売上高', '売上高');
        $expense = $businessUnit->getSubAccountByName('消耗品費', '消耗品費');

        $fiscalYear->registerOpeningEntry([
            [
                'account_name' => '現金',
                'sub_account_name' => '現金',
                'amount' => 100000,
            ],
        ]);

        (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-04-10',
            'description' => '売上',
        ], [
            [
                'sub_account_id' => $cash->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 30000,
            ],
            [
                'sub_account_id' => $sales->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => 30000,
            ],
        ]);

        (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-05-10',
            'description' => '消耗品購入',
        ], [
            [
                'sub_account_id' => $expense->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 5000,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => 5000,
            ],
        ]);

        $fiscalYear->close($user);

        $rolloverData = $fiscalYear->calculateRolloverData();

        $this->assertSame(2026, $rolloverData['next_year']);
        $this->assertSame(25000, $rolloverData['current_profit']);
        $this->assertSame([
            [
                'account_name' => '現金',
                'sub_account_name' => '現金',
                'amount' => 125000,
                'type' => 'debit',
            ],
        ], $rolloverData['opening_entries']);
        $this->assertSame([
            'account_name' => '元入金',
            'sub_account_name' => '元入金',
            'amount' => 125000,
            'type' => 'credit',
        ], $rolloverData['capital_entry']);

        $nextFiscalYear = $businessUnit->createFiscalYear($rolloverData['next_year'], $user);
        $openingTransaction = app(FiscalYearRollover::class)->rollover($fiscalYear, $nextFiscalYear, $user);

        $this->assertInstanceOf(Transaction::class, $openingTransaction);
        $this->assertCount(2, $openingTransaction->journalEntries);
        $this->assertDatabaseHas('transactions', [
            'id' => $openingTransaction->id,
            'fiscal_year_id' => $nextFiscalYear->id,
            'is_opening_entry' => true,
        ]);
        $this->assertSame(125000, $nextFiscalYear->calculateBalanceSummary()['asset']['total_balance']);
    }

    #[Test]
    public function rollover_dataは複数の資産_sub_accountを含められる()
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => '複数繰越事業体',
        ]);

        $fiscalYear = $businessUnit->createFiscalYear(2025, $user);

        $cash = $businessUnit->getSubAccountByName('現金', '現金');
        $deposit = $businessUnit->getSubAccountByName('その他の預金', 'その他の預金');

        $fiscalYear->registerOpeningEntry([
            [
                'account_name' => '現金',
                'sub_account_name' => '現金',
                'amount' => 100000,
            ],
            [
                'account_name' => 'その他の預金',
                'sub_account_name' => 'その他の預金',
                'amount' => 50000,
            ],
        ]);

        (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-04-10',
            'description' => '預金引落',
        ], [
            [
                'sub_account_id' => $deposit->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 10000,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => 10000,
            ],
        ]);

        $fiscalYear->close($user);

        $rolloverData = $fiscalYear->calculateRolloverData();

        $this->assertSame([
            [
                'account_name' => 'その他の預金',
                'sub_account_name' => 'その他の預金',
                'amount' => 60000,
                'type' => 'debit',
            ],
            [
                'account_name' => '現金',
                'sub_account_name' => '現金',
                'amount' => 90000,
                'type' => 'debit',
            ],
        ], $rolloverData['opening_entries']);
    }

    #[Test]
    public function rollover_dataは残高0の資産_sub_accountを含めない()
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'ゼロ残高繰越事業体',
        ]);

        $fiscalYear = $businessUnit->createFiscalYear(2025, $user);

        $cash = $businessUnit->getSubAccountByName('現金', '現金');
        $deposit = $businessUnit->getSubAccountByName('その他の預金', 'その他の預金');
        $expense = $businessUnit->getSubAccountByName('消耗品費', '消耗品費');

        $fiscalYear->registerOpeningEntry([
            [
                'account_name' => '現金',
                'sub_account_name' => '現金',
                'amount' => 100000,
            ],
            [
                'account_name' => 'その他の預金',
                'sub_account_name' => 'その他の預金',
                'amount' => 50000,
            ],
        ]);

        (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-04-10',
            'description' => '預金を使い切る',
        ], [
            [
                'sub_account_id' => $expense->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 50000,
            ],
            [
                'sub_account_id' => $deposit->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => 50000,
            ],
        ]);

        $fiscalYear->close($user);

        $rolloverData = $fiscalYear->calculateRolloverData();

        $this->assertSame([
            [
                'account_name' => '現金',
                'sub_account_name' => '現金',
                'amount' => 100000,
                'type' => 'debit',
            ],
        ], $rolloverData['opening_entries']);
    }
}
