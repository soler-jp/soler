<?php

namespace Tests\Feature\Livewire\SolerUi\TransactionEntry\JournalForm;

use App\Livewire\SolerUi\TransactionEntry\JournalForm\Edit;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionRegistrar;
use App\Setup\Initializers\GeneralBusinessInitializer;
use Illuminate\Auth\Access\AuthorizationException;
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

    protected function registerTransaction(User $user, $unit, string $description = 'テスト取引'): Transaction
    {
        $debit = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();
        $credit = $unit->getAccountByName('事業主借')->subAccounts()->firstOrFail();

        return app(TransactionRegistrar::class)->register(
            $unit->currentFiscalYear,
            [
                'date' => '2025-05-10',
                'description' => $description,
            ],
            [
                [
                    'sub_account_id' => $debit->id,
                    'type' => JournalEntry::TYPE_DEBIT,
                    'gross_amount' => 1000,
                    'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                ],
                [
                    'sub_account_id' => $credit->id,
                    'type' => JournalEntry::TYPE_CREDIT,
                    'gross_amount' => 1000,
                    'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                ],
            ],
            $user,
        );
    }

    #[Test]
    public function mountで既存transactionをformにプリフィルする(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $transaction = $this->registerTransaction($user, $unit, 'プリフィルテスト');

        $component = Livewire::actingAs($user)
            ->test(Edit::class, ['transactionId' => $transaction->id]);

        $component
            ->assertSet('transactionId', $transaction->id)
            ->assertSet('date_input', '0510')
            ->assertSet('description', 'プリフィルテスト')
            ->assertCount('entries', 2)
            ->assertSet('entries.0.type', JournalEntry::TYPE_DEBIT)
            ->assertSet('entries.0.gross_amount', 1000)
            ->assertSet('entries.1.type', JournalEntry::TYPE_CREDIT)
            ->assertSet('entries.1.gross_amount', 1000);
    }

    #[Test]
    public function revision_reasonが空だと保存できない(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $transaction = $this->registerTransaction($user, $unit);

        Livewire::actingAs($user)
            ->test(Edit::class, ['transactionId' => $transaction->id])
            ->set('revision_reason', '')
            ->call('submit')
            ->assertHasErrors('revision_reason');

        // 元 transaction はまだ active
        $this->assertTrue($transaction->fresh()->is_active);
    }

    #[Test]
    public function 修正保存で旧transactionがdeactivateされ新しいrevisedが作られる(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $transaction = $this->registerTransaction($user, $unit, '元取引');

        $debitSub = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();
        $creditSub = $unit->getAccountByName('事業主借')->subAccounts()->firstOrFail();

        Livewire::actingAs($user)
            ->test(Edit::class, ['transactionId' => $transaction->id])
            ->set('description', '訂正後の説明')
            ->set('revision_reason', '金額を訂正')
            ->set('entries.0.gross_amount', 1200)
            ->set('entries.1.gross_amount', 1200)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertDispatched('journal-form-edit-saved');

        // 旧が deactivate
        $original = $transaction->fresh();
        $this->assertFalse($original->is_active);

        // 新しい revised transaction が作られている
        $revised = Transaction::where('revised_from_transaction_id', $transaction->id)->firstOrFail();
        $this->assertTrue($revised->is_active);
        $this->assertSame('訂正後の説明', $revised->description);
        $this->assertSame(2, $revised->journalEntries()->count());

        $debitEntry = $revised->journalEntries->firstWhere('type', JournalEntry::TYPE_DEBIT);
        $this->assertSame(1200, (int) $debitEntry->net_amount);
        $this->assertSame($debitSub->id, (int) $debitEntry->sub_account_id);

        $creditEntry = $revised->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT);
        $this->assertSame(1200, (int) $creditEntry->net_amount);
        $this->assertSame($creditSub->id, (int) $creditEntry->sub_account_id);
    }

    #[Test]
    public function 修正不可なtransactionをmountすると例外(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $transaction = $this->registerTransaction($user, $unit);

        // is_active=false にして修正不可へ
        $transaction->forceFill(['is_active' => false])->save();

        // Livewire は mount() の例外を ViewException に wrap するので、
        // メッセージが含まれるかで検証する。
        try {
            Livewire::actingAs($user)
                ->test(Edit::class, ['transactionId' => $transaction->id]);
            $this->fail('修正不可な transaction で mount しても例外が発生しませんでした。');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('無効化済みの取引は修正できません。', $e->getMessage());
        }
    }

    #[Test]
    public function 他事業体のtransactionをmountすると認可失敗(): void
    {
        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();

        $unitA = $this->initializeUnit($ownerA, 'A');
        $this->initializeUnit($ownerB, 'B');

        $transaction = $this->registerTransaction($ownerA, $unitA);

        // Laravel の ExceptionHandler が AuthorizationException を 403 に変換して
        // Livewire test に返してしまうのを避け、素の例外として受け取れるようにする。
        $this->withoutExceptionHandling();

        $this->expectException(AuthorizationException::class);

        Livewire::actingAs($ownerB)
            ->test(Edit::class, ['transactionId' => $transaction->id]);
    }

    #[Test]
    public function 借方と貸方の合計が一致しないと保存されない(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $transaction = $this->registerTransaction($user, $unit);

        Livewire::actingAs($user)
            ->test(Edit::class, ['transactionId' => $transaction->id])
            ->set('revision_reason', '金額を訂正')
            ->set('entries.0.gross_amount', 1000)
            ->set('entries.1.gross_amount', 900)
            ->call('submit')
            ->assertHasErrors('entries');

        // 元 transaction はまだ active、revised は無い
        $this->assertTrue($transaction->fresh()->is_active);
        $this->assertNull(Transaction::where('revised_from_transaction_id', $transaction->id)->first());
    }

    #[Test]
    public function cancelでイベントが発火する(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $transaction = $this->registerTransaction($user, $unit);

        Livewire::actingAs($user)
            ->test(Edit::class, ['transactionId' => $transaction->id])
            ->call('cancel')
            ->assertDispatched('journal-form-edit-cancelled');
    }

    #[Test]
    public function 編集画面には修正理由入力欄が描画される(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $transaction = $this->registerTransaction($user, $unit);

        Livewire::actingAs($user)
            ->test(Edit::class, ['transactionId' => $transaction->id])
            ->assertSeeHtml('wire:model.defer="revision_reason"')
            ->assertSee(__('transactions.journal_form.fields.revision_reason'));
    }
}
