<?php

namespace Tests\Feature\Livewire\SolerUi\TransactionEntry\RevenueForm;

use App\Livewire\SolerUi\TransactionEntry\RevenueForm\Edit;
use App\Livewire\SolerUi\TransactionEntry\RevenueForm\Standard;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionRegistrar;
use App\Setup\Initializers\GeneralBusinessInitializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EditTest extends TestCase
{
    use RefreshDatabase;

    protected function initializeUnit(User $user, string $name = 'テスト事業体', bool $isTaxable = false)
    {
        return (new GeneralBusinessInitializer)->initialize($user, [
            'name' => $name,
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => $isTaxable,
            'is_tax_exclusive' => false,
            'cash_balance' => null,
            'bank_accounts' => [],
            'fixed_assets' => [],
            'recurring_templates' => [],
        ]);
    }

    private function createRevenueTransaction(User $user, $unit, array $overrides = []): Transaction
    {
        $fiscalYear = $unit->currentFiscalYear;
        $revenue = $unit->getAccountByName('売上高')->subAccounts()->first();
        $receipt = $unit->getAccountByName('現金')->subAccounts()->first();

        return app(TransactionRegistrar::class)->register(
            $fiscalYear,
            array_merge([
                'date' => '2025-04-10',
                'description' => 'サービス提供',
                'created_by' => $user->id,
            ], $overrides),
            [
                [
                    'sub_account_id' => $revenue->id,
                    'type' => JournalEntry::TYPE_CREDIT,
                    'gross_amount' => 11000,
                    'tax_type' => JournalEntry::TAX_TYPE_DEEMED_TAXABLE_SALES_10,
                ],
                [
                    'sub_account_id' => $receipt->id,
                    'type' => JournalEntry::TYPE_DEBIT,
                    'gross_amount' => 11000,
                    'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                ],
            ],
            $user,
        );
    }

    #[Test]
    public function 既存の売上取引の内容をフォームにプリフィルする(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $transaction = $this->createRevenueTransaction($user, $unit);

        $revenue = $unit->getAccountByName('売上高')->subAccounts()->first();
        $receipt = $unit->getAccountByName('現金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Edit::class, ['transactionId' => $transaction->id])
            ->assertSet('date_input', '0410')
            ->assertSet('note', 'サービス提供')
            ->assertSet('amount', 11000)
            ->assertSet('revenue_sub_account_id', $revenue->id)
            ->assertSet('receipt_sub_account_id', $receipt->id)
            ->assertSet('tax_option', Standard::TAX_OPTION_10);
    }

    #[Test]
    public function 売上を更新すると元取引が無効化され改訂取引が作成される(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $transaction = $this->createRevenueTransaction($user, $unit);

        $newReceipt = $unit->getSubAccountByName('売掛金', '売掛金');

        Livewire::actingAs($user)
            ->test(Edit::class, ['transactionId' => $transaction->id])
            ->set('amount', 22000)
            ->set('note', '追加サービス提供')
            ->set('receipt_sub_account_id', $newReceipt->id)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertDispatched('transaction-edit-finished')
            ->assertDispatched('dashboard-transaction-created');

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'is_active' => false,
        ]);

        $revised = Transaction::where('revised_from_transaction_id', $transaction->id)->firstOrFail();
        $this->assertTrue((bool) $revised->is_active);
        $this->assertSame('追加サービス提供', $revised->description);

        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $revised->id,
            'sub_account_id' => $newReceipt->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 22000,
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $revised->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 20000,
            'tax_amount' => 2000,
        ]);
    }

    #[Test]
    public function キャンセルするとイベントが発火する(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $transaction = $this->createRevenueTransaction($user, $unit);

        Livewire::actingAs($user)
            ->test(Edit::class, ['transactionId' => $transaction->id])
            ->call('cancel')
            ->assertDispatched('transaction-edit-cancelled');
    }

    #[Test]
    public function 源泉徴収付き売上取引の一覧行はis_single_pairがfalseになる(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $fiscalYear = $unit->currentFiscalYear;

        $revenue = $unit->getAccountByName('売上高')->subAccounts()->first();
        $receipt = $unit->getAccountByName('現金')->subAccounts()->first();
        $withheld = $unit->getSubAccountByName('事業主貸', '源泉徴収');

        app(TransactionRegistrar::class)->register(
            $fiscalYear,
            [
                'date' => '2025-04-10',
                'description' => '源泉徴収あり売上',
                'created_by' => $user->id,
            ],
            [
                [
                    'sub_account_id' => $revenue->id,
                    'type' => JournalEntry::TYPE_CREDIT,
                    'gross_amount' => 11000,
                    'tax_type' => JournalEntry::TAX_TYPE_DEEMED_TAXABLE_SALES_10,
                ],
                [
                    'sub_account_id' => $receipt->id,
                    'type' => JournalEntry::TYPE_DEBIT,
                    'gross_amount' => 10000,
                    'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                ],
                [
                    'sub_account_id' => $withheld->id,
                    'type' => JournalEntry::TYPE_DEBIT,
                    'gross_amount' => 1000,
                    'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                ],
            ],
            $user,
        );

        $rows = $fiscalYear->accountTypeTransactions(Account::TYPE_REVENUE);

        $this->assertCount(1, $rows);
        $this->assertFalse($rows[0]['is_single_pair']);
    }
}
