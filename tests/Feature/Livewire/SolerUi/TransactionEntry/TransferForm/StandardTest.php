<?php

namespace Tests\Feature\Livewire\SolerUi\TransactionEntry\TransferForm;

use App\Livewire\SolerUi\TransactionEntry\TransferForm\Standard;
use App\Models\JournalEntry;
use App\Models\SubAccount;
use App\Models\User;
use App\Setup\Initializers\GeneralBusinessInitializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StandardTest extends TestCase
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

    #[Test]
    public function お金の移動フォームがダッシュボードに表示される(): void
    {
        $user = User::factory()->create();
        $this->initializeUnit($user);

        $this->actingAs($user);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSeeLivewire(Standard::class);
    }

    #[Test]
    public function お金の移動を正しく入力すると仕訳が登録される(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $fromSub = $unit->getAccountByName('事業主借')->subAccounts()->firstOrFail();
        $toSub = $unit->getAccountByName('現金')->addCustomSubAccount('レジ', $user);

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0510')
            ->set('amount', 30000)
            ->set('note', '手元資金の補充')
            ->set('from_sub_account_id', $fromSub->id)
            ->set('to_sub_account_id', $toSub->id)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSee(__('transactions.transfer_form.messages.registered'));

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $toSub->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 30000,
            'tax_amount' => 0,
            'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $fromSub->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 30000,
            'tax_amount' => 0,
            'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
        ]);

        $this->assertDatabaseHas('transactions', [
            'date' => '2025-05-10 00:00:00',
            'description' => '手元資金の補充',
        ]);
    }

    #[Test]
    public function メモが空なら移動元と移動先から摘要を自動生成する(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $fromSub = $unit->getAccountByName('現金')->addCustomSubAccount('レジ', $user);
        $toSub = $unit->getAccountByName('事業主貸')->subAccounts()->firstOrFail();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0511')
            ->set('amount', 12000)
            ->set('note', '')
            ->set('from_sub_account_id', $fromSub->id)
            ->set('to_sub_account_id', $toSub->id)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('transactions', [
            'date' => '2025-05-11 00:00:00',
            'description' => 'レジ → 個人の財布へ 振替',
        ]);
    }

    #[Test]
    public function 移動元と移動先が同じなら登録できない(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $cashSub = $unit->getAccountByName('現金')->addCustomSubAccount('レジ', $user);

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0512')
            ->set('amount', 5000)
            ->set('from_sub_account_id', $cashSub->id)
            ->set('to_sub_account_id', $cashSub->id)
            ->call('submit')
            ->assertHasErrors(['from_sub_account_id', 'to_sub_account_id']);

        $this->assertDatabaseMissing('transactions', [
            'date' => '2025-05-12 00:00:00',
        ]);
    }

    #[Test]
    public function 移動元と移動先で候補とラベルが文脈に応じて変わる(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $bankAccount = $unit->getAccountByName('その他の預金');
        $bankAccount->createSubAccount(['name' => 'メイン口座'], $user);
        $bankAccount->createSubAccount([
            'name' => '非表示口座',
            'visibility' => SubAccount::VISIBILITY_HIDDEN,
        ], $user);

        $cashAccount = $unit->getAccountByName('現金');
        $cashAccount->addCustomSubAccount('レジ', $user);

        $component = Livewire::actingAs($user)
            ->test(Standard::class)
            ->instance();

        $fromLabels = $component->fromOptions
            ->pluck('label')
            ->values()
            ->all();

        $toLabels = $component->toOptions
            ->pluck('label')
            ->values()
            ->all();

        $this->assertContains('レジ', $fromLabels);
        $this->assertContains('メイン口座', $fromLabels);
        $this->assertContains('個人の財布から', $fromLabels);
        $this->assertNotContains('個人の財布へ', $fromLabels);
        $this->assertNotContains('非表示口座', $fromLabels);

        $this->assertContains('レジ', $toLabels);
        $this->assertContains('メイン口座', $toLabels);
        $this->assertContains('個人の財布へ', $toLabels);
        $this->assertNotContains('個人の財布から', $toLabels);
        $this->assertNotContains('非表示口座', $toLabels);
    }

    #[Test]
    public function 何か選択した後でもhiddenと不要補助科目は表示されない(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $bankAccount = $unit->getAccountByName('その他の預金');
        $bankAccount->createSubAccount([
            'name' => '非表示口座',
            'visibility' => SubAccount::VISIBILITY_HIDDEN,
        ], $user);

        $ownerDrawAccount = $unit->getAccountByName('事業主貸');
        $ownerDrawAccount->createSubAccount([
            'name' => '家事按分',
            'system_purpose' => SubAccount::PURPOSE_HOUSEHOLD_ALLOCATION,
        ], $user);

        $cashSub = $unit->getAccountByName('現金')->addCustomSubAccount('レジ', $user);

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('from_sub_account_id', $cashSub->id)
            ->assertDontSee('非表示口座')
            ->assertDontSee('源泉徴収')
            ->assertDontSee('家事按分');
    }
}
