<?php

namespace Tests\Feature\Livewire\Recurring;

use App\Livewire\Recurring\IncomeRealizationList;
use App\Models\Counterparty;
use App\Models\JournalEntry;
use App\Models\RecurringTransactionPlan;
use App\Models\SubAccount;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IncomeRealizationListTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 実現フォームに課税事業者向けの受取予定プレビューを表示できる(): void
    {
        Carbon::setTestNow('2026-08-08 10:00:00');

        try {
            [$user, $plannedTransaction, $depositSubAccount] = $this->createMonthlyIncomePlannedTransaction('2026-07-25');

            $this->actingAs($user);

            Livewire::test(IncomeRealizationList::class)
                ->assertSee('受取日・入金日')
                ->assertSee('売上金額(税込)')
                ->assertSee('源泉徴収額')
                ->set("inputs.{$plannedTransaction->id}.amount", 110000)
                ->set("inputs.{$plannedTransaction->id}.withholding_tax_amount", 10210)
                ->set("inputs.{$plannedTransaction->id}.receipt_date", '2026-08-25')
                ->set("inputs.{$plannedTransaction->id}.receipt_sub_account_id", $depositSubAccount->id)
                ->assertSee('7月分のインストラクター業務委託 110,000円のうち、消費税(10%)は10,000円です。')
                ->assertSee('源泉徴収の10,210円を差し引いて99,790円が8/25にその他の預金に振り込まれる予定です。');
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function 売上金額を修正するとプレビュー文も即時に切り替わる(): void
    {
        Carbon::setTestNow('2026-08-08 10:00:00');

        try {
            [$user, $plannedTransaction, $depositSubAccount] = $this->createMonthlyIncomePlannedTransaction('2026-07-25');

            $this->actingAs($user);

            Livewire::test(IncomeRealizationList::class)
                ->set("inputs.{$plannedTransaction->id}.withholding_tax_amount", 10210)
                ->set("inputs.{$plannedTransaction->id}.receipt_date", '2026-08-25')
                ->set("inputs.{$plannedTransaction->id}.receipt_sub_account_id", $depositSubAccount->id)
                ->set("inputs.{$plannedTransaction->id}.amount", 110000)
                ->assertSee('7月分のインストラクター業務委託 110,000円のうち、消費税(10%)は10,000円です。')
                ->assertSee('源泉徴収の10,210円を差し引いて99,790円が8/25にその他の預金に振り込まれる予定です。')
                ->set("inputs.{$plannedTransaction->id}.amount", 220000)
                ->assertSee('7月分のインストラクター業務委託 220,000円のうち、消費税(10%)は20,000円です。')
                ->assertSee('源泉徴収の10,210円を差し引いて209,790円が8/25にその他の預金に振り込まれる予定です。');
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function 消費税率を8パーセントに切り替えるとプレビュー文も更新される(): void
    {
        Carbon::setTestNow('2026-08-08 10:00:00');

        try {
            [$user, $plannedTransaction, $depositSubAccount] = $this->createMonthlyIncomePlannedTransaction('2026-07-25');

            $this->actingAs($user);

            Livewire::test(IncomeRealizationList::class)
                ->set("inputs.{$plannedTransaction->id}.amount", 108000)
                ->set("inputs.{$plannedTransaction->id}.tax_option", '8')
                ->set("inputs.{$plannedTransaction->id}.withholding_tax_amount", 10210)
                ->set("inputs.{$plannedTransaction->id}.receipt_date", '2026-08-25')
                ->set("inputs.{$plannedTransaction->id}.receipt_sub_account_id", $depositSubAccount->id)
                ->assertSee('7月分のインストラクター業務委託 108,000円のうち、消費税(8%)は8,000円です。');
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function 実現フォームに課税事業者向けの受取済みプレビューを表示できる(): void
    {
        Carbon::setTestNow('2026-08-08 10:00:00');

        try {
            [$user, $plannedTransaction, $depositSubAccount] = $this->createMonthlyIncomePlannedTransaction('2026-07-25');

            $this->actingAs($user);

            Livewire::test(IncomeRealizationList::class)
                ->set("inputs.{$plannedTransaction->id}.amount", 110000)
                ->set("inputs.{$plannedTransaction->id}.withholding_tax_amount", 10210)
                ->set("inputs.{$plannedTransaction->id}.receipt_date", '2026-08-05')
                ->set("inputs.{$plannedTransaction->id}.receipt_sub_account_id", $depositSubAccount->id)
                ->assertSee('7月分のインストラクター業務委託 110,000円のうち、消費税(10%)は10,000円です。')
                ->assertSee('源泉徴収の10,210円を差し引いて99,790円が8/5にその他の預金に振り込まれました。');
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function 実現操作で予定取引を確定できる(): void
    {
        [$user, $plannedTransaction, $depositSubAccount] = $this->createMonthlyIncomePlannedTransaction('2025-01-25');

        $this->actingAs($user);

        Livewire::test(IncomeRealizationList::class)
            ->set("inputs.{$plannedTransaction->id}.amount", 110000)
            ->set("inputs.{$plannedTransaction->id}.withholding_tax_amount", 10210)
            ->set("inputs.{$plannedTransaction->id}.receipt_date", '2025-01-31')
            ->set("inputs.{$plannedTransaction->id}.receipt_sub_account_id", $depositSubAccount->id)
            ->call('realize', $plannedTransaction->id)
            ->assertSee('定期収入を登録しました。');

        $realizedTransaction = Transaction::query()->findOrFail($plannedTransaction->id)->fresh();

        $this->assertFalse($realizedTransaction->is_planned);
        $this->assertSame('2025-01-31', $realizedTransaction->date?->toDateString());
    }

    /**
     * @return array{0: User, 1: Transaction, 2: SubAccount}
     */
    private function createMonthlyIncomePlannedTransaction(string $plannedDate): array
    {
        $plannedDateCarbon = Carbon::parse($plannedDate);

        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '定期収入UIテスト']);
        $fiscalYear = $unit->createFiscalYear((int) $plannedDateCarbon->year, $user);
        $fiscalYear->forceFill(['is_taxable' => true])->save();

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
            'day_of_month' => (int) $plannedDateCarbon->day,
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
            ->firstWhere('date', $plannedDateCarbon);

        $this->assertNotNull($plannedTransaction);

        return [$user, $plannedTransaction, $depositSubAccount];
    }
}
