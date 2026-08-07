<?php

namespace Tests\Feature\Livewire\SolerUi\TransactionEntry\PurchaseForm;

use App\Livewire\SolerUi\TransactionEntry\PurchaseForm\Edit;
use App\Livewire\SolerUi\TransactionEntry\PurchaseForm\Standard;
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

    private function createPurchaseTransaction(User $user, $unit, array $overrides = []): Transaction
    {
        $fiscalYear = $unit->currentFiscalYear;
        $purchase = $unit->getAccountByName('仕入金額')->subAccounts()->first();
        $credit = $unit->getAccountByName('現金')->subAccounts()->first();

        return app(TransactionRegistrar::class)->register(
            $fiscalYear,
            array_merge([
                'date' => '2025-04-10',
                'description' => '食材の仕入れ',
                'created_by' => $user->id,
            ], $overrides),
            [
                [
                    'sub_account_id' => $purchase->id,
                    'type' => JournalEntry::TYPE_DEBIT,
                    'gross_amount' => 11000,
                    'tax_type' => JournalEntry::TAX_TYPE_DEEMED_TAXABLE_PURCHASES_10,
                ],
                [
                    'sub_account_id' => $credit->id,
                    'type' => JournalEntry::TYPE_CREDIT,
                    'gross_amount' => 11000,
                    'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                ],
            ],
            $user,
        );
    }

    #[Test]
    public function 既存の仕入取引の内容をフォームにプリフィルする(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $transaction = $this->createPurchaseTransaction($user, $unit);

        $purchase = $unit->getAccountByName('仕入金額')->subAccounts()->first();
        $credit = $unit->getAccountByName('現金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Edit::class, ['transactionId' => $transaction->id])
            ->assertSet('date_input', '0410')
            ->assertSet('note', '食材の仕入れ')
            ->assertSet('amount', 11000)
            ->assertSet('purchase_sub_account_id', $purchase->id)
            ->assertSet('credit_sub_account_id', $credit->id)
            ->assertSet('tax_option', Standard::TAX_OPTION_10);
    }

    #[Test]
    public function 仕入を更新すると元取引が無効化され改訂取引が作成される(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $transaction = $this->createPurchaseTransaction($user, $unit);

        $newCredit = $unit->getAccountByName('事業主借')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Edit::class, ['transactionId' => $transaction->id])
            ->set('amount', 22000)
            ->set('note', '追加仕入')
            ->set('credit_sub_account_id', $newCredit->id)
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
        $this->assertSame('追加仕入', $revised->description);

        $purchase = $unit->getAccountByName('仕入金額')->subAccounts()->first();
        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $revised->id,
            'sub_account_id' => $purchase->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 20000,
            'tax_amount' => 2000,
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $revised->id,
            'sub_account_id' => $newCredit->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 22000,
        ]);
    }

    #[Test]
    public function キャンセルするとイベントが発火する(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $transaction = $this->createPurchaseTransaction($user, $unit);

        Livewire::actingAs($user)
            ->test(Edit::class, ['transactionId' => $transaction->id])
            ->call('cancel')
            ->assertDispatched('transaction-edit-cancelled');
    }
}
