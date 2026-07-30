<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\RecurringTransactionPlan;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PlannedTransactionConfirmer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlannedTransactionConfirmerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: BusinessUnit, 1: Transaction}
     */
    private function makePlannedTransaction(User $user): array
    {
        $unit = $user->createBusinessUnitWithDefaults(['name' => '確定認可テスト']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $debit = $unit->getAccountByName('通信費')->subAccounts()->firstOrFail();
        $credit = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        $plan = $unit->createRecurringTransactionPlan([
            'name' => 'サーバー代',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'amount' => 1100,
            'tax_amount' => 0,
            'type' => RecurringTransactionPlan::TYPE_EXPENSE,
            'debit_sub_account_id' => $debit->id,
            'credit_sub_account_id' => $credit->id,
        ], $user);

        $transaction = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user)->firstOrFail();

        return [$unit, $transaction];
    }

    #[Test]
    public function 他ユーザーは予定取引を確定できない(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        [, $transaction] = $this->makePlannedTransaction($user);

        $this->expectException(AuthorizationException::class);

        app(PlannedTransactionConfirmer::class)->confirm($transaction, $otherUser);
    }

    #[Test]
    public function actorがnullなら予定取引を確定できない(): void
    {
        $user = User::factory()->create();
        [, $transaction] = $this->makePlannedTransaction($user);

        $this->expectException(\TypeError::class);

        app(PlannedTransactionConfirmer::class)->confirm($transaction, null);
    }
}
