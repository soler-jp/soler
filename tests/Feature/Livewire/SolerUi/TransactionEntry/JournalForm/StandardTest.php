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
    public function entryの構造にbusiness_ratioは含まれない(): void
    {
        // JournalForm は raw な journal_entry を扱う。家事按分（business_ratio）は
        // ExpenseForm 側の抽象で、この画面では auto-magic なしで利用者が
        // 事業主貸 の行を手で追加する運用にする。
        $user = User::factory()->create();
        $this->initializeUnit($user);

        $component = Livewire::actingAs($user)
            ->test(Standard::class)
            ->call('addDebit')
            ->call('addCredit');

        foreach ($component->get('entries') as $entry) {
            $this->assertArrayNotHasKey('business_ratio', $entry);
        }
    }

    #[Test]
    public function business_ratio入力欄はどの行にも描画されない(): void
    {
        $user = User::factory()->create();
        $this->initializeUnit($user);

        $html = Livewire::actingAs($user)
            ->test(Standard::class)
            ->call('addDebit')
            ->call('addCredit')
            ->html();

        $this->assertStringNotContainsString('business_ratio', $html);
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
    public function 家事按分を手で書き下ろした複合仕訳を登録できてauto行は生成されない(): void
    {
        // ratio auto-magic はもうこの画面にない。家事按分したい利用者は
        // 事業主貸 の借方行を明示的に足す。DB には利用者が書いたN行が
        // そのまま入る（自動追加行なし）。
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $expenseSub = $unit->getAccountByName('消耗品費')->subAccounts()->first();
        $ownerDrawSub = $unit->getAccountByName('事業主貸')->subAccounts()->first();
        $creditSub = $unit->getAccountByName('事業主借')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0610')
            ->set('description', '手動按分テスト')
            ->call('addDebit')
            ->set('entries.0.sub_account_id', $expenseSub->id)
            ->set('entries.0.gross_amount', 600)
            ->set('entries.0.tax_type', JournalEntry::TAX_TYPE_OUT_OF_SCOPE)
            ->set('entries.1.sub_account_id', $creditSub->id)
            ->set('entries.1.gross_amount', 1000)
            ->set('entries.1.tax_type', JournalEntry::TAX_TYPE_OUT_OF_SCOPE)
            ->set('entries.2.sub_account_id', $ownerDrawSub->id)
            ->set('entries.2.gross_amount', 400)
            ->set('entries.2.tax_type', JournalEntry::TAX_TYPE_OUT_OF_SCOPE)
            ->call('submit')
            ->assertHasNoErrors();

        $transaction = Transaction::where('description', '手動按分テスト')->firstOrFail();

        // 利用者が書いた3行がそのまま入る。フォームが auto-magic で追加する行はない。
        $this->assertSame(3, $transaction->journalEntries()->count());

        // 利用者が書いた 事業主貸 行はそのまま保存されている
        $householdEntry = $transaction->journalEntries()
            ->where('sub_account_id', $ownerDrawSub->id)
            ->firstOrFail();
        $this->assertSame(400, (int) $householdEntry->net_amount);
        $this->assertSame(JournalEntry::TYPE_DEBIT, $householdEntry->type);

        // 費用行は allocation_group_id を持たない（グループ扱いされていない）
        $expenseEntry = $transaction->journalEntries()
            ->where('sub_account_id', $expenseSub->id)
            ->firstOrFail();
        $this->assertNull($expenseEntry->allocation_group_id);
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
