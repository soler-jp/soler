<?php

namespace Tests\Feature\Livewire\SolerUi\TransactionEntry\TransferForm;

use App\Livewire\SolerUi\TransactionEntry\TransferForm\Edit;
use App\Models\JournalEntry;
use App\Models\SubAccount;
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

    protected function initializeUnit(User $user, string $name = 'テスト事業体')
    {
        return (new GeneralBusinessInitializer)->initialize($user, [
            'name' => $name,
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => false,
            'is_tax_exclusive' => false,
            'cash_balance' => null,
            'bank_accounts' => [],
            'fixed_assets' => [],
            'recurring_templates' => [],
        ]);
    }

    /**
     * @return array{0: Transaction, 1: SubAccount, 2: SubAccount}
     */
    private function createTransferTransaction(User $user, $unit): array
    {
        $fiscalYear = $unit->currentFiscalYear;
        $cash = $unit->getAccountByName('現金')->addCustomSubAccount('レジ', $user);
        $bank = $unit->getAccountByName('その他の預金')->addCustomSubAccount('メイン口座', $user);

        $transaction = app(TransactionRegistrar::class)->register(
            $fiscalYear,
            [
                'date' => '2025-05-10',
                'description' => '預金から現金へ引き出し',
            ],
            [
                [
                    'sub_account_id' => $cash->id,
                    'type' => JournalEntry::TYPE_DEBIT,
                    'gross_amount' => 8000,
                    'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                ],
                [
                    'sub_account_id' => $bank->id,
                    'type' => JournalEntry::TYPE_CREDIT,
                    'gross_amount' => 8000,
                    'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                ],
            ],
            $user,
        );

        return [$transaction, $cash, $bank];
    }

    #[Test]
    public function 既存の振替取引の内容をフォームにプリフィルする(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        [$transaction, $cash, $bank] = $this->createTransferTransaction($user, $unit);

        Livewire::actingAs($user)
            ->test(Edit::class, ['transactionId' => $transaction->id])
            ->assertSet('date_input', '0510')
            ->assertSet('note', '預金から現金へ引き出し')
            ->assertSet('amount', 8000)
            ->assertSet('to_sub_account_id', $cash->id)
            ->assertSet('from_sub_account_id', $bank->id);
    }

    #[Test]
    public function 振替を更新すると元取引が無効化され改訂取引が作成される(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        [$transaction, $cash] = $this->createTransferTransaction($user, $unit);

        $owner = $unit->getAccountByName('事業主借')->subAccounts()->firstOrFail();

        Livewire::actingAs($user)
            ->test(Edit::class, ['transactionId' => $transaction->id])
            ->set('amount', 12000)
            ->set('note', '個人からの立替')
            ->set('from_sub_account_id', $owner->id)
            ->set('to_sub_account_id', $cash->id)
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
        $this->assertSame('個人からの立替', $revised->description);

        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $revised->id,
            'sub_account_id' => $cash->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 12000,
            'tax_amount' => 0,
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $revised->id,
            'sub_account_id' => $owner->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 12000,
            'tax_amount' => 0,
        ]);
    }

    #[Test]
    public function 移動元と移動先が同じならエラーになる(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        [$transaction, $cash] = $this->createTransferTransaction($user, $unit);

        Livewire::actingAs($user)
            ->test(Edit::class, ['transactionId' => $transaction->id])
            ->set('from_sub_account_id', $cash->id)
            ->set('to_sub_account_id', $cash->id)
            ->call('submit')
            ->assertHasErrors(['from_sub_account_id', 'to_sub_account_id']);
    }

    #[Test]
    public function 許可されていない科目を指定するとエラーになる(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        [$transaction] = $this->createTransferTransaction($user, $unit);

        $supplies = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();

        Livewire::actingAs($user)
            ->test(Edit::class, ['transactionId' => $transaction->id])
            ->set('to_sub_account_id', $supplies->id)
            ->call('submit')
            ->assertHasErrors(['to_sub_account_id']);
    }

    #[Test]
    public function キャンセルするとイベントが発火する(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        [$transaction] = $this->createTransferTransaction($user, $unit);

        Livewire::actingAs($user)
            ->test(Edit::class, ['transactionId' => $transaction->id])
            ->call('cancel')
            ->assertDispatched('transaction-edit-cancelled');
    }
}
