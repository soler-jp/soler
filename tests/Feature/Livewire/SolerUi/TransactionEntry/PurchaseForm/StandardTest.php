<?php

namespace Tests\Feature\Livewire\SolerUi\TransactionEntry\PurchaseForm;

use App\Livewire\SolerUi\TransactionEntry\PurchaseForm\Standard;
use App\Models\JournalEntry;
use App\Models\User;
use App\Setup\Initializers\GeneralBusinessInitializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
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
    public function 仕入入力フォームがダッシュボードに表示される(): void
    {
        $user = User::factory()->create();
        $this->initializeUnit($user);

        $this->actingAs($user);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSeeLivewire(Standard::class);
    }

    #[Test]
    public function mount時に仕入科目を自動取得できる(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $purchaseSub = $unit->getAccountByName('仕入金額')->subAccounts()->firstOrFail();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->assertSet('purchase_sub_account_id', $purchaseSub->id);
    }

    #[Test]
    public function 金額が不正なら登録不可の文言を表示する(): void
    {
        $user = User::factory()->create();
        $this->initializeUnit($user);

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('amount', '12a34')
            ->assertSee('金額が不正なので登録できません');
    }

    #[Test]
    public function 仕入を正しく入力すると仕訳が登録される(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $purchaseSub = $unit->getAccountByName('仕入金額')->subAccounts()->firstOrFail();
        $creditSub = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0510')
            ->set('amount', 1100)
            ->set('tax_option', Standard::TAX_OPTION_10)
            ->set('note', '食材の仕入れ')
            ->set('credit_sub_account_id', $creditSub->id)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSee(__('transactions.purchase_form.messages.registered'));

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $purchaseSub->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 1000,
            'tax_amount' => 100,
            'tax_type' => JournalEntry::TAX_TYPE_DEEMED_TAXABLE_PURCHASES_10,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $creditSub->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 1100,
            'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
        ]);

        $this->assertDatabaseHas('transactions', [
            'date' => '2025-05-10 00:00:00',
            'description' => '食材の仕入れ',
        ]);
    }

    #[Test]
    public function 非課税を選ぶと税額が計上されずexemptになる(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user, isTaxable: true);

        $purchaseSub = $unit->getAccountByName('仕入金額')->subAccounts()->firstOrFail();
        $creditSub = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0510')
            ->set('amount', 1000)
            ->set('tax_option', Standard::TAX_OPTION_EXEMPT)
            ->set('credit_sub_account_id', $creditSub->id)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $purchaseSub->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 1000,
            'tax_amount' => 0,
            'tax_type' => JournalEntry::TAX_TYPE_EXEMPT,
        ]);
    }

    #[Test]
    public function 支払い先を入力するとcounterpartyが作成される(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $creditSub = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0510')
            ->set('amount', 550)
            ->set('note', '包装資材')
            ->set('counterparty_name', '仕入先ストア')
            ->set('credit_sub_account_id', $creditSub->id)
            ->call('submit')
            ->assertHasNoErrors();

        $counterparty = $unit->counterparties()->where('name', '仕入先ストア')->firstOrFail();

        $this->assertDatabaseHas('transactions', [
            'counterparty_id' => $counterparty->id,
            'date' => '2025-05-10 00:00:00',
        ]);
    }

    #[Test]
    public function 買掛金を支払方法にして仕入を登録できる(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $purchaseSub = $unit->getAccountByName('仕入金額')->subAccounts()->firstOrFail();
        $accountsPayableSub = $unit->getAccountByName('買掛金')->subAccounts()->firstOrFail();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0515')
            ->set('amount', 2200)
            ->set('note', '雑貨の仕入れ')
            ->set('credit_sub_account_id', $accountsPayableSub->id)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $purchaseSub->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 2000,
            'tax_amount' => 200,
            'tax_type' => JournalEntry::TAX_TYPE_DEEMED_TAXABLE_PURCHASES_10,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $accountsPayableSub->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 2200,
            'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
        ]);
    }

    #[Test]
    public function 摘要が空ならdescriptionは仕入になる(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $creditSub = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0510')
            ->set('amount', 550)
            ->set('note', '')
            ->set('credit_sub_account_id', $creditSub->id)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('transactions', [
            'date' => '2025-05-10 00:00:00',
            'description' => '仕入',
        ]);
    }

    #[Test]
    public function 免税事業者では消費税の選択肢を表示しない(): void
    {
        $user = User::factory()->create();
        $this->initializeUnit($user, isTaxable: false);

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->assertSet('isTaxable', false)
            ->assertDontSee(__('transactions.purchase_form.fields.tax_option'));
    }

    #[Test]
    public function 免税事業者でも仕入を登録できる(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user, isTaxable: false);

        $purchaseSub = $unit->getAccountByName('仕入金額')->subAccounts()->firstOrFail();
        $creditSub = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0601')
            ->set('amount', 5500)
            ->set('note', '乾物の仕入れ')
            ->set('counterparty_name', '問屋A')
            ->set('credit_sub_account_id', $creditSub->id)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSee(__('transactions.purchase_form.messages.registered'));

        $counterparty = $unit->counterparties()->where('name', '問屋A')->firstOrFail();

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $purchaseSub->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 5000,
            'tax_amount' => 500,
            'tax_type' => JournalEntry::TAX_TYPE_DEEMED_TAXABLE_PURCHASES_10,
        ]);

        $this->assertDatabaseHas('transactions', [
            'date' => '2025-06-01 00:00:00',
            'description' => '乾物の仕入れ',
            'counterparty_id' => $counterparty->id,
        ]);
    }

    #[Test]
    public function 免税事業者で支払方法が未選択だとバリデーションエラーになる(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user, isTaxable: false);

        $purchaseSub = $unit->getAccountByName('仕入金額')->subAccounts()->firstOrFail();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0601')
            ->set('amount', 5500)
            ->set('note', '乾物の仕入れ')
            ->set('credit_sub_account_id', null)
            ->call('submit')
            ->assertHasErrors(['credit_sub_account_id' => 'required']);

        $this->assertDatabaseMissing('journal_entries', [
            'sub_account_id' => $purchaseSub->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 5000,
        ]);
    }

    #[Test]
    public function 免税事業者で存在しない日付だと登録されない(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user, isTaxable: false);

        $creditSub = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0231')
            ->set('amount', 5500)
            ->set('note', '乾物の仕入れ')
            ->set('credit_sub_account_id', $creditSub->id)
            ->call('submit')
            ->assertHasErrors(['date_input']);

        $this->assertDatabaseMissing('transactions', [
            'description' => '乾物の仕入れ',
        ]);
    }

    #[Test]
    public function 免税事業者では消費税の選択を変えてもみなし10パーセントで登録される(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user, isTaxable: false);

        $purchaseSub = $unit->getAccountByName('仕入金額')->subAccounts()->firstOrFail();
        $creditSub = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0510')
            ->set('amount', 1100)
            ->set('tax_option', Standard::TAX_OPTION_EXEMPT)
            ->set('credit_sub_account_id', $creditSub->id)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('tax_option', Standard::TAX_OPTION_10);

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $purchaseSub->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 1000,
            'tax_amount' => 100,
            'tax_type' => JournalEntry::TAX_TYPE_DEEMED_TAXABLE_PURCHASES_10,
        ]);
    }

    #[Test]
    #[Group('mysql')]
    public function 貸方勘定科目が想定順で表示される(): void
    {
        $user = User::factory()->create();
        $this->initializeUnit($user);

        $creditAccountNames = Livewire::actingAs($user)
            ->test(Standard::class)
            ->instance()
            ->creditAccounts
            ->pluck('name')
            ->values()
            ->all();

        $this->assertSame(['現金', 'その他の預金', '事業主借', '買掛金'], $creditAccountNames);
    }

    #[Test]
    public function 銀行口座_その他の預金の補助科目_を支払方法にして仕入を登録できる(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $purchaseSub = $unit->getAccountByName('仕入金額')->subAccounts()->firstOrFail();
        $bankSub = $unit->getAccountByName('その他の預金')
            ->addCustomSubAccount('メインバンク', $user);

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0515')
            ->set('amount', 2200)
            ->set('note', '雑貨の仕入れ')
            ->set('credit_sub_account_id', $bankSub->id)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $purchaseSub->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 2000,
            'tax_amount' => 200,
            'tax_type' => JournalEntry::TAX_TYPE_DEEMED_TAXABLE_PURCHASES_10,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $bankSub->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 2200,
            'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
        ]);
    }

    #[Test]
    public function 他ユーザー事業体の補助科目は仕入登録に使えない(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $unit = $this->initializeUnit($user, '自分の事業体');
        $otherUnit = $this->initializeUnit($otherUser, '他人の事業体');

        $foreignCredit = $otherUnit->getAccountByName('現金')->subAccounts()->firstOrFail();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0510')
            ->set('amount', 1500)
            ->set('credit_sub_account_id', $foreignCredit->id)
            ->call('submit')
            ->assertHasErrors(['credit_sub_account_id']);

        $this->assertDatabaseMissing('journal_entries', [
            'sub_account_id' => $foreignCredit->id,
            'type' => JournalEntry::TYPE_CREDIT,
        ]);
    }
}
