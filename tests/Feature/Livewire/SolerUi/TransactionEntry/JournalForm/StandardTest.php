<?php

namespace Tests\Feature\Livewire\SolerUi\TransactionEntry\JournalForm;

use App\Livewire\SolerUi\TransactionEntry\JournalForm\Standard;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\SubAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Setup\Initializers\GeneralBusinessInitializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StandardTest extends TestCase
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

    #[Test]
    public function mount時に借方1行貸方1行が初期化される(): void
    {
        $user = User::factory()->create();
        $this->initializeUnit($user);

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->assertCount('entries', 2)
            ->assertSet('entries.0.type', JournalEntry::TYPE_DEBIT)
            ->assertSet('entries.1.type', JournalEntry::TYPE_CREDIT);
    }

    #[Test]
    public function 借方の初期行はbusiness_ratio100をデフォルトに持つ(): void
    {
        $user = User::factory()->create();
        $this->initializeUnit($user);

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->assertSet('entries.0.business_ratio', '100')
            ->call('addDebit')
            ->assertSet('entries.2.business_ratio', '100');
    }

    #[Test]
    public function 貸方の行はbusiness_ratioフィールドを持たない(): void
    {
        $user = User::factory()->create();
        $this->initializeUnit($user);

        $component = Livewire::actingAs($user)
            ->test(Standard::class)
            ->call('addCredit');

        $entries = $component->get('entries');
        $this->assertArrayNotHasKey('business_ratio', $entries[1]);
        $this->assertArrayNotHasKey('business_ratio', $entries[2]);
    }

    #[Test]
    public function 貸方行はbusiness_ratio入力欄が描画されない(): void
    {
        $user = User::factory()->create();
        $this->initializeUnit($user);

        $html = Livewire::actingAs($user)
            ->test(Standard::class)
            ->html();

        // 借方 (index 0) には ratio 入力が存在するが、貸方 (index 1) には存在しない
        $this->assertStringContainsString('entries.0.business_ratio', $html);
        $this->assertStringNotContainsString('entries.1.business_ratio', $html);
    }

    #[Test]
    public function 借方の費用以外の科目でbusiness_ratio_100以外を指定するとバリデーションエラー(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        // debit を「事業主借」（=資本科目）にしてratioを60に設定 → 費用ではないので拒否される
        $debitNonExpense = $unit->getAccountByName('事業主借')->subAccounts()->first();
        $creditSub = $unit->getAccountByName('消耗品費')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0510')
            ->set('entries.0.sub_account_id', $debitNonExpense->id)
            ->set('entries.0.gross_amount', 1000)
            ->set('entries.0.tax_type', JournalEntry::TAX_TYPE_OUT_OF_SCOPE)
            ->set('entries.0.business_ratio', 60)
            ->set('entries.1.sub_account_id', $creditSub->id)
            ->set('entries.1.gross_amount', 1000)
            ->set('entries.1.tax_type', JournalEntry::TAX_TYPE_OUT_OF_SCOPE)
            ->call('submit')
            ->assertHasErrors('entries.0.business_ratio');

        $this->assertDatabaseCount('transactions', 0);
    }

    #[Test]
    public function 借方の費用以外の科目でbusiness_ratio_100は素通りする(): void
    {
        // 費用以外の借方でも「100」（デフォルト）は無視されるだけで登録は通る
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $debitNonExpense = $unit->getAccountByName('事業主貸')->subAccounts()->first();
        $creditSub = $unit->getAccountByName('事業主借')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0511')
            ->set('description', 'default100テスト')
            ->set('entries.0.sub_account_id', $debitNonExpense->id)
            ->set('entries.0.gross_amount', 1000)
            ->set('entries.0.tax_type', JournalEntry::TAX_TYPE_OUT_OF_SCOPE)
            // business_ratio はデフォルトの '100' のまま
            ->set('entries.1.sub_account_id', $creditSub->id)
            ->set('entries.1.gross_amount', 1000)
            ->set('entries.1.tax_type', JournalEntry::TAX_TYPE_OUT_OF_SCOPE)
            ->call('submit')
            ->assertHasNoErrors();

        $transaction = Transaction::where('description', 'default100テスト')->firstOrFail();
        $debitEntry = $transaction->journalEntries()
            ->where('sub_account_id', $debitNonExpense->id)
            ->firstOrFail();
        $this->assertNull($debitEntry->business_ratio);
    }

    #[Test]
    public function サブ科目optgroupが全typeレンダーされhidden補助科目は含まれない(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $component = Livewire::actingAs($user)->test(Standard::class);

        foreach (Account::TYPES as $type) {
            $component->assertSeeHtml('label="'.__('transactions.journal_form.account_type_labels.'.$type).'"');
        }

        // 現金の SubAccount は hidden なので option に含まれない
        $hiddenSub = $unit->getAccountByName('現金')->subAccounts()->first();
        $this->assertSame(SubAccount::VISIBILITY_HIDDEN, $hiddenSub->visibility);
        $component->assertDontSeeHtml('value="'.$hiddenSub->id.'"');

        // 消耗品費 (expense/standard) は含まれる
        $visibleSub = $unit->getAccountByName('消耗品費')->subAccounts()->first();
        $component->assertSeeHtml('value="'.$visibleSub->id.'"');
    }

    #[Test]
    public function add_debitとadd_creditで行を追加できる(): void
    {
        $user = User::factory()->create();
        $this->initializeUnit($user);

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->call('addDebit')
            ->call('addCredit')
            ->assertCount('entries', 4);
    }

    #[Test]
    public function remove_entryで削除できるが借方貸方それぞれ最低1行は残す(): void
    {
        $user = User::factory()->create();
        $this->initializeUnit($user);

        $component = Livewire::actingAs($user)
            ->test(Standard::class)
            ->call('addDebit');

        $this->assertCount(3, $component->get('entries'));

        $component->call('removeEntry', 2)
            ->assertCount('entries', 2);

        // 借方1行しかない状態でさらに借方を消そうとしても消えない
        $component->call('removeEntry', 0)
            ->assertCount('entries', 2);
    }

    #[Test]
    public function 単純な借方貸方1行ずつの仕訳を登録できる(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $debitSub = $unit->getAccountByName('消耗品費')->subAccounts()->first();
        $creditSub = $unit->getAccountByName('事業主借')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0510')
            ->set('description', '文房具購入')
            ->set('entries.0.sub_account_id', $debitSub->id)
            ->set('entries.0.gross_amount', 1000)
            ->set('entries.0.tax_type', JournalEntry::TAX_TYPE_OUT_OF_SCOPE)
            ->set('entries.1.sub_account_id', $creditSub->id)
            ->set('entries.1.gross_amount', 1000)
            ->set('entries.1.tax_type', JournalEntry::TAX_TYPE_OUT_OF_SCOPE)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('transactions', [
            'date' => '2025-05-10 00:00:00',
            'description' => '文房具購入',
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $debitSub->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 1000,
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $creditSub->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 1000,
        ]);
    }

    #[Test]
    public function 複合仕訳借方2行貸方1行を登録できる(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $debit1 = $unit->getAccountByName('消耗品費')->subAccounts()->first();
        $debit2 = $unit->getAccountByName('通信費')->subAccounts()->first();
        $credit = $unit->getAccountByName('事業主借')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0512')
            ->set('description', '複合仕訳テスト')
            ->call('addDebit')
            ->set('entries.0.sub_account_id', $debit1->id)
            ->set('entries.0.gross_amount', 600)
            ->set('entries.0.tax_type', JournalEntry::TAX_TYPE_OUT_OF_SCOPE)
            ->set('entries.1.sub_account_id', $credit->id)
            ->set('entries.1.gross_amount', 1000)
            ->set('entries.1.tax_type', JournalEntry::TAX_TYPE_OUT_OF_SCOPE)
            ->set('entries.2.sub_account_id', $debit2->id)
            ->set('entries.2.gross_amount', 400)
            ->set('entries.2.tax_type', JournalEntry::TAX_TYPE_OUT_OF_SCOPE)
            ->call('submit')
            ->assertHasNoErrors();

        $transaction = Transaction::where('description', '複合仕訳テスト')->firstOrFail();
        $this->assertSame(3, $transaction->journalEntries()->count());
        $this->assertSame(2, $transaction->journalEntries()->where('type', JournalEntry::TYPE_DEBIT)->count());
    }

    #[Test]
    public function 借方と貸方の合計が一致しない場合は登録されない(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $debit = $unit->getAccountByName('消耗品費')->subAccounts()->first();
        $credit = $unit->getAccountByName('事業主借')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0510')
            ->set('entries.0.sub_account_id', $debit->id)
            ->set('entries.0.gross_amount', 1000)
            ->set('entries.0.tax_type', JournalEntry::TAX_TYPE_OUT_OF_SCOPE)
            ->set('entries.1.sub_account_id', $credit->id)
            ->set('entries.1.gross_amount', 900)
            ->set('entries.1.tax_type', JournalEntry::TAX_TYPE_OUT_OF_SCOPE)
            ->call('submit')
            ->assertHasErrors('entries');

        $this->assertDatabaseCount('transactions', 0);
    }

    #[Test]
    public function hidden_sub_accountは選択できずバリデーションで弾かれる(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $creditSub = $unit->getAccountByName('事業主借')->subAccounts()->first();

        // 現金の SubAccount は hidden
        $cashSub = $unit->getAccountByName('現金')->subAccounts()->first();
        $this->assertSame(SubAccount::VISIBILITY_HIDDEN, $cashSub->visibility);

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0510')
            ->set('entries.0.sub_account_id', $cashSub->id)
            ->set('entries.0.gross_amount', 1000)
            ->set('entries.0.tax_type', JournalEntry::TAX_TYPE_OUT_OF_SCOPE)
            ->set('entries.1.sub_account_id', $creditSub->id)
            ->set('entries.1.gross_amount', 1000)
            ->set('entries.1.tax_type', JournalEntry::TAX_TYPE_OUT_OF_SCOPE)
            ->call('submit')
            ->assertHasErrors('entries.0.sub_account_id');

        $this->assertDatabaseCount('transactions', 0);
    }

    #[Test]
    public function business_ratioを指定すると家事按分行が生成される(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $expenseSub = $unit->getAccountByName('消耗品費')->subAccounts()->first();
        $creditSub = $unit->getAccountByName('事業主借')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0610')
            ->set('description', '家事按分テスト')
            ->set('entries.0.sub_account_id', $expenseSub->id)
            ->set('entries.0.gross_amount', 1000)
            ->set('entries.0.tax_type', JournalEntry::TAX_TYPE_OUT_OF_SCOPE)
            ->set('entries.0.business_ratio', 60)
            ->set('entries.1.sub_account_id', $creditSub->id)
            ->set('entries.1.gross_amount', 1000)
            ->set('entries.1.tax_type', JournalEntry::TAX_TYPE_OUT_OF_SCOPE)
            ->call('submit')
            ->assertHasNoErrors();

        $transaction = Transaction::where('description', '家事按分テスト')->firstOrFail();

        $businessEntry = $transaction->journalEntries()
            ->where('sub_account_id', $expenseSub->id)
            ->firstOrFail();
        $this->assertSame(600, (int) $businessEntry->net_amount);
        $this->assertSame(60, (int) $businessEntry->business_ratio);

        // 家事按分行が追加されている
        $householdSub = $unit->getSubAccountByName('事業主貸', '家事按分');
        $this->assertNotNull($householdSub);
        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $transaction->id,
            'sub_account_id' => $householdSub->id,
            'net_amount' => 400,
        ]);
    }

    #[Test]
    public function 見なし税区分はユーザー選択肢に含まれずバリデーションで弾かれる(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $debit = $unit->getAccountByName('消耗品費')->subAccounts()->first();
        $credit = $unit->getAccountByName('事業主借')->subAccounts()->first();

        $html = Livewire::actingAs($user)
            ->test(Standard::class)
            ->html();

        // UI に「見なし」税区分の option value が現れない
        $this->assertStringNotContainsString('value="'.JournalEntry::TAX_TYPE_DEEMED_TAXABLE_SALES_10.'"', $html);
        $this->assertStringNotContainsString('value="'.JournalEntry::TAX_TYPE_DEEMED_TAXABLE_PURCHASES_10.'"', $html);

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0510')
            ->set('entries.0.sub_account_id', $debit->id)
            ->set('entries.0.gross_amount', 1000)
            ->set('entries.0.tax_type', JournalEntry::TAX_TYPE_DEEMED_TAXABLE_PURCHASES_10)
            ->set('entries.1.sub_account_id', $credit->id)
            ->set('entries.1.gross_amount', 1000)
            ->set('entries.1.tax_type', JournalEntry::TAX_TYPE_OUT_OF_SCOPE)
            ->call('submit')
            ->assertHasErrors('entries.0.tax_type');
    }

    #[Test]
    public function 日付形式が不正だとバリデーションエラー(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $debit = $unit->getAccountByName('消耗品費')->subAccounts()->first();
        $credit = $unit->getAccountByName('事業主借')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', 'abcd')
            ->set('entries.0.sub_account_id', $debit->id)
            ->set('entries.0.gross_amount', 1000)
            ->set('entries.0.tax_type', JournalEntry::TAX_TYPE_OUT_OF_SCOPE)
            ->set('entries.1.sub_account_id', $credit->id)
            ->set('entries.1.gross_amount', 1000)
            ->set('entries.1.tax_type', JournalEntry::TAX_TYPE_OUT_OF_SCOPE)
            ->call('submit')
            ->assertHasErrors('date_input');
    }

    #[Test]
    public function 存在しない日付だとinvalid_dateエラー(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $debit = $unit->getAccountByName('消耗品費')->subAccounts()->first();
        $credit = $unit->getAccountByName('事業主借')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0230')
            ->set('entries.0.sub_account_id', $debit->id)
            ->set('entries.0.gross_amount', 1000)
            ->set('entries.0.tax_type', JournalEntry::TAX_TYPE_OUT_OF_SCOPE)
            ->set('entries.1.sub_account_id', $credit->id)
            ->set('entries.1.gross_amount', 1000)
            ->set('entries.1.tax_type', JournalEntry::TAX_TYPE_OUT_OF_SCOPE)
            ->call('submit')
            ->assertHasErrors('date_input');
    }
}
