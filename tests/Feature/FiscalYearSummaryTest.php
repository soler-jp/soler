<?php

namespace Tests\Feature;

use App\Services\TransactionRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use App\Models\User;

class FiscalYearSummaryTest extends TestCase
{
    use RefreshDatabase;


    #[Test]
    public function 売上と経費の合計が取得できる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
        ]);

        $fiscalYear = $unit->createFiscalYear(2025);

        $revenueAccount = $unit->accounts()->where('type', 'revenue')->first();
        $expenseAccount = $unit->accounts()->where('type', 'expense')->first();
        $assetAccount = $unit->accounts()->where('type', 'asset')->first();
        $liabilityAccount = $unit->accounts()->where('type', 'liability')->first();

        $registrar = new TransactionRegistrar();

        // 売上 10,000円
        $registrar->register($fiscalYear, [
            'date' => '2025-04-01',
            'description' => '売上取引',
        ], [
            [
                'account_id' => $revenueAccount->id,
                'type' => 'credit',
                'amount' => 10000,
            ],
            [
                'account_id' => $assetAccount->id,
                'type' => 'debit',
                'amount' => 10000,
            ],
        ]);

        // 経費 5,000円
        $registrar->register($fiscalYear, [
            'date' => '2025-04-02',
            'description' => '経費取引',
        ], [
            [
                'account_id' => $expenseAccount->id,
                'type' => 'debit',
                'amount' => 5000,
            ],
            [
                'account_id' => $liabilityAccount->id,
                'type' => 'credit',
                'amount' => 5000,
            ],
        ]);

        $summary = $fiscalYear->calculateSummary();

        $this->assertSame(10000, $summary['total_income']);
        $this->assertSame(5000, $summary['total_expense']);
        $this->assertSame(5000, $summary['profit']);
    }


    #[Test]
    public function 消費税を含めた金額で売上と経費を集計できる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業所']);
        $fiscalYear = $unit->createFiscalYear(2025);

        $revenueAccount = $unit->accounts()->where('type', 'revenue')->first();
        $expenseAccount = $unit->accounts()->where('type', 'expense')->first();
        $assetAccount = $unit->accounts()->where('type', 'asset')->first();
        $liabilityAccount = $unit->accounts()->where('type', 'liability')->first();

        $registrar = new TransactionRegistrar();

        // 売上 10,000 + 消費税 1,000
        $registrar->register($fiscalYear, [
            'date' => '2025-04-01',
            'description' => '課税売上',
        ], [
            [
                'account_id' => $revenueAccount->id,
                'type' => 'credit',
                'amount' => 10000,
                'tax_amount' => 1000,
                'tax_type' => 'taxable_sales_10',
            ],
            [
                'account_id' => $assetAccount->id,
                'type' => 'debit',
                'amount' => 11000,
            ],
        ]);

        // 経費 6,000 + 消費税 600
        $registrar->register($fiscalYear, [
            'date' => '2025-04-02',
            'description' => '課税経費',
        ], [
            [
                'account_id' => $expenseAccount->id,
                'type' => 'debit',
                'amount' => 6000,
                'tax_amount' => 600,
                'tax_type' => 'taxable_purchases_10',
            ],
            [
                'account_id' => $liabilityAccount->id,
                'type' => 'credit',
                'amount' => 6600,
            ],
        ]);

        $summary = $fiscalYear->calculateSummary();

        $this->assertSame(11000, $summary['total_income']);
        $this->assertSame(6600, $summary['total_expense']);
        $this->assertSame(4400, $summary['profit']);
    }

    #[Test]
    public function 他の年度の取引は集計に含まれない()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '複数年度テスト']);

        $fy2025 = $unit->createFiscalYear(2025);
        $fy2026 = $unit->createFiscalYear(2026);

        $revenueAccount = $unit->accounts()->where('type', 'revenue')->first();
        $assetAccount = $unit->accounts()->where('type', 'asset')->first();

        $registrar = new TransactionRegistrar();

        // 2025年度の売上
        $registrar->register($fy2025, [
            'date' => '2025-04-01',
            'description' => '売上（2025）',
        ], [
            [
                'account_id' => $revenueAccount->id,
                'type' => 'credit',
                'amount' => 10000,
            ],
            [
                'account_id' => $assetAccount->id,
                'type' => 'debit',
                'amount' => 10000,
            ],
        ]);

        // 2026年度の売上
        $registrar->register($fy2026, [
            'date' => '2026-04-01',
            'description' => '売上（2026）',
        ], [
            [
                'account_id' => $revenueAccount->id,
                'type' => 'credit',
                'amount' => 20000,
            ],
            [
                'account_id' => $assetAccount->id,
                'type' => 'debit',
                'amount' => 20000,
            ],
        ]);

        $summary = $fy2025->calculateSummary();

        $this->assertSame(10000, $summary['total_income']);
        $this->assertSame(0, $summary['total_expense']);
        $this->assertSame(10000, $summary['profit']);
    }

    #[Test]
    public function 売上のみある場合でも正しく集計できる()
    {
        $user = \App\Models\User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '売上のみ']);
        $fiscalYear = $unit->createFiscalYear(2025);

        $revenueAccount = $unit->accounts()->where('type', 'revenue')->first();
        $assetAccount = $unit->accounts()->where('type', 'asset')->first();

        $registrar = new \App\Services\TransactionRegistrar();

        $registrar->register($fiscalYear, [
            'date' => '2025-05-01',
            'description' => '売上のみの取引',
        ], [
            [
                'account_id' => $revenueAccount->id,
                'type' => 'credit',
                'amount' => 15000,
            ],
            [
                'account_id' => $assetAccount->id,
                'type' => 'debit',
                'amount' => 15000,
            ],
        ]);

        $summary = $fiscalYear->calculateSummary();

        $this->assertSame(15000, $summary['total_income']);
        $this->assertSame(0, $summary['total_expense']);
        $this->assertSame(15000, $summary['profit']);
    }

    #[Test]
    public function 経費のみある場合でも正しく集計できる()
    {
        $user = \App\Models\User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '経費のみ']);
        $fiscalYear = $unit->createFiscalYear(2025);

        $expenseAccount = $unit->accounts()->where('type', 'expense')->first();
        $liabilityAccount = $unit->accounts()->where('type', 'liability')->first();

        $registrar = new \App\Services\TransactionRegistrar();

        $registrar->register($fiscalYear, [
            'date' => '2025-06-01',
            'description' => '経費のみの取引',
        ], [
            [
                'account_id' => $expenseAccount->id,
                'type' => 'debit',
                'amount' => 8000,
            ],
            [
                'account_id' => $liabilityAccount->id,
                'type' => 'credit',
                'amount' => 8000,
            ],
        ]);

        $summary = $fiscalYear->calculateSummary();

        $this->assertSame(0, $summary['total_income']);
        $this->assertSame(8000, $summary['total_expense']);
        $this->assertSame(-8000, $summary['profit']);
    }

    #[Test]
    public function 売上も経費もない場合は0円になる()
    {
        $user = \App\Models\User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '空の年度']);
        $fiscalYear = $unit->createFiscalYear(2025);

        $summary = $fiscalYear->calculateSummary();

        $this->assertSame(0, $summary['total_income']);
        $this->assertSame(0, $summary['total_expense']);
        $this->assertSame(0, $summary['profit']);
    }
}
