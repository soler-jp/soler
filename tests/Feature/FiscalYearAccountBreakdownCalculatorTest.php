<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use App\Services\FiscalYearAccountBreakdownCalculator;
use App\Services\TransactionRegistrar;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FiscalYearAccountBreakdownCalculatorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 他ユーザーはcalculateで他人の会計年度を集計できない(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $otherUser = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(FiscalYearAccountBreakdownCalculator::class)->calculate($fiscalYear, $otherUser);
    }

    #[Test]
    public function 他ユーザーはtransactionsForBreakdownで他人の会計年度を参照できない(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);
        $unit->refresh();

        $account = $unit->getAccountByName('現金');

        $otherUser = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(FiscalYearAccountBreakdownCalculator::class)->transactionsForBreakdown(
            $fiscalYear,
            Account::TYPE_ASSET,
            $account->id,
            actor: $otherUser,
        );
    }

    #[Test]
    public function actorがnullの場合はcalculateが拒否される(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $this->expectException(AuthorizationException::class);

        app(FiscalYearAccountBreakdownCalculator::class)->calculate($fiscalYear, null);
    }

    #[Test]
    public function 正当なユーザーはcalculateで集計できる(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);
        $unit->refresh();

        $cash = $unit->getSubAccountByName('現金', '現金');
        $revenue = $unit->getSubAccountByName('売上高', '売上高');

        $registrar = new TransactionRegistrar;
        $registrar->register($fiscalYear, [
            'date' => '2025-01-10',
            'description' => '売上入金',
        ], [
            ['sub_account_id' => $cash->id, 'type' => 'debit', 'net_amount' => 50000],
            ['sub_account_id' => $revenue->id, 'type' => 'credit', 'net_amount' => 50000],
        ], $user);

        $result = app(FiscalYearAccountBreakdownCalculator::class)->calculate($fiscalYear, $user);

        $this->assertSame(50000, $result['asset']['total_amount']);
        $this->assertSame(50000, $result['revenue']['total_amount']);
    }

    #[Test]
    public function 正当なユーザーはtransactionsForBreakdownで明細を取得できる(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);
        $unit->refresh();

        $cash = $unit->getSubAccountByName('現金', '現金');
        $cashAccount = $unit->getAccountByName('現金');
        $revenue = $unit->getSubAccountByName('売上高', '売上高');

        $registrar = new TransactionRegistrar;
        $registrar->register($fiscalYear, [
            'date' => '2025-01-10',
            'description' => '売上入金',
        ], [
            ['sub_account_id' => $cash->id, 'type' => 'debit', 'net_amount' => 50000],
            ['sub_account_id' => $revenue->id, 'type' => 'credit', 'net_amount' => 50000],
        ], $user);

        $transactions = app(FiscalYearAccountBreakdownCalculator::class)->transactionsForBreakdown(
            $fiscalYear,
            Account::TYPE_ASSET,
            $cashAccount->id,
            actor: $user,
        );

        $this->assertCount(1, $transactions);
        $this->assertSame(50000, $transactions[0]['amount']);
        $this->assertSame(50000, $transactions[0]['balance']);
        $this->assertSame('売上入金', $transactions[0]['description']);
    }
}
