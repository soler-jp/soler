<?php

namespace Tests\Feature\Livewire\SolerUi\TransactionEntry\ExpenseForm;

use App\Livewire\SolerUi\TransactionEntry\ExpenseForm\Edit;
use App\Livewire\SolerUi\TransactionEntry\ExpenseForm\Standard;
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

    private function createExpenseTransaction(User $user, $unit, array $overrides = []): Transaction
    {
        $fiscalYear = $unit->currentFiscalYear;
        $debit = $unit->getAccountByName('消耗品費')->subAccounts()->first();
        $credit = $unit->getAccountByName('現金')->subAccounts()->first();

        return app(TransactionRegistrar::class)->register(
            $fiscalYear,
            array_merge([
                'date' => '2025-04-10',
                'description' => '文房具',
                'created_by' => $user->id,
            ], $overrides),
            [
                [
                    'sub_account_id' => $debit->id,
                    'type' => JournalEntry::TYPE_DEBIT,
                    'gross_amount' => 1100,
                    'tax_type' => JournalEntry::TAX_TYPE_DEEMED_TAXABLE_PURCHASES_10,
                ],
                [
                    'sub_account_id' => $credit->id,
                    'type' => JournalEntry::TYPE_CREDIT,
                    'gross_amount' => 1100,
                    'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                ],
            ],
            $user,
        );
    }

    #[Test]
    public function 既存の経費取引の内容をフォームにプリフィルする(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $transaction = $this->createExpenseTransaction($user, $unit);

        $debit = $unit->getAccountByName('消耗品費')->subAccounts()->first();
        $credit = $unit->getAccountByName('現金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Edit::class, ['transactionId' => $transaction->id])
            ->assertSet('date_input', '0410')
            ->assertSet('note', '文房具')
            ->assertSet('amount', 1100)
            ->assertSet('debit_sub_account_id', $debit->id)
            ->assertSet('credit_sub_account_id', $credit->id)
            ->assertSet('tax_option', Standard::TAX_OPTION_10);
    }

    #[Test]
    public function 経費を更新すると元取引が無効化され改訂取引が作成される(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $transaction = $this->createExpenseTransaction($user, $unit);

        $newDebit = $unit->getAccountByName('通信費')->subAccounts()->first();
        $newCredit = $unit->getAccountByName('事業主借')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Edit::class, ['transactionId' => $transaction->id])
            ->set('amount', 2200)
            ->set('note', 'モバイル通信費')
            ->set('debit_sub_account_id', $newDebit->id)
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
        $this->assertSame('モバイル通信費', $revised->description);

        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $revised->id,
            'sub_account_id' => $newDebit->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 2000,
            'tax_amount' => 200,
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $revised->id,
            'sub_account_id' => $newCredit->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 2200,
        ]);
    }

    #[Test]
    public function キャンセルするとイベントが発火する(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $transaction = $this->createExpenseTransaction($user, $unit);

        Livewire::actingAs($user)
            ->test(Edit::class, ['transactionId' => $transaction->id])
            ->call('cancel')
            ->assertDispatched('transaction-edit-cancelled');
    }

    #[Test]
    public function 元取引の取引先は改訂取引に引き継がれる(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $transaction = $this->createExpenseTransaction($user, $unit, [
            'counterparty_name' => '既存店',
        ]);

        $this->assertNotNull($transaction->counterparty_id);
        $originalCounterpartyId = $transaction->counterparty_id;

        $debit = $unit->getAccountByName('消耗品費')->subAccounts()->first();
        $credit = $unit->getAccountByName('現金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Edit::class, ['transactionId' => $transaction->id])
            ->set('debit_sub_account_id', $debit->id)
            ->set('credit_sub_account_id', $credit->id)
            ->call('submit')
            ->assertHasNoErrors();

        $revised = Transaction::where('revised_from_transaction_id', $transaction->id)->firstOrFail();
        $this->assertSame($originalCounterpartyId, $revised->counterparty_id);
    }

    #[Test]
    public function 編集フォームから取引先名を変更すると改訂取引の取引先も更新される(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $transaction = $this->createExpenseTransaction($user, $unit, [
            'counterparty_name' => '既存店',
        ]);

        $debit = $unit->getAccountByName('消耗品費')->subAccounts()->first();
        $credit = $unit->getAccountByName('現金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Edit::class, ['transactionId' => $transaction->id])
            ->set('counterparty_name', '変更後取引先')
            ->set('debit_sub_account_id', $debit->id)
            ->set('credit_sub_account_id', $credit->id)
            ->call('submit')
            ->assertHasNoErrors();

        $revised = Transaction::where('revised_from_transaction_id', $transaction->id)->firstOrFail();
        $this->assertSame('変更後取引先', $revised->counterparty?->name);
    }

    #[Test]
    public function 編集フォームで取引先名を空にすると改訂取引の取引先を解除できる(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $transaction = $this->createExpenseTransaction($user, $unit, [
            'counterparty_name' => '解除対象',
        ]);

        $debit = $unit->getAccountByName('消耗品費')->subAccounts()->first();
        $credit = $unit->getAccountByName('現金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Edit::class, ['transactionId' => $transaction->id])
            ->set('counterparty_name', '')
            ->set('debit_sub_account_id', $debit->id)
            ->set('credit_sub_account_id', $credit->id)
            ->call('submit')
            ->assertHasNoErrors();

        $revised = Transaction::where('revised_from_transaction_id', $transaction->id)->firstOrFail();
        $this->assertNull($revised->counterparty_id);
    }
}
