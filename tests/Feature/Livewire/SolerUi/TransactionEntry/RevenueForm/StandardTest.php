<?php

namespace Tests\Feature\Livewire\SolerUi\TransactionEntry\RevenueForm;

use App\Livewire\SolerUi\TransactionEntry\RevenueForm\Standard;
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
    public function 通常の売上が登録できる()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $revenueSub = $unit->getAccountByName('売上高')->subAccounts()->first();
        $cashSub = $unit->getAccountByName('現金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0401')
            ->set('amount', 11000)
            ->set('note', '通常売上テスト')
            ->set('tax_option', Standard::TAX_OPTION_10)
            ->set('revenue_sub_account_id', $revenueSub->id)
            ->set('receipt_sub_account_id', $cashSub->id)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSee(__('transactions.revenue_form.messages.registered'));

        // 免税事業者・10%税込11000 → net=10000, tax=1000
        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $revenueSub->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 10000,
            'tax_amount' => 1000,
            'tax_type' => JournalEntry::TAX_TYPE_DEEMED_TAXABLE_SALES_10,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $cashSub->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 11000,
            'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
        ]);
    }

    #[Test]
    public function 非課税を選ぶと税額が計上されずexemptになる()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user, isTaxable: true);

        $revenueSub = $unit->getAccountByName('売上高')->subAccounts()->first();
        $cashSub = $unit->getAccountByName('現金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0401')
            ->set('amount', 10000)
            ->set('tax_option', Standard::TAX_OPTION_EXEMPT)
            ->set('revenue_sub_account_id', $revenueSub->id)
            ->set('receipt_sub_account_id', $cashSub->id)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $revenueSub->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 10000,
            'tax_amount' => 0,
            'tax_type' => JournalEntry::TAX_TYPE_EXEMPT,
        ]);
    }

    #[Test]
    public function 金額の日本語表記を文字列入力でも表示できる(): void
    {
        $user = User::factory()->create();
        $this->initializeUnit($user);

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('amount', '123456')
            ->assertSee('12万 3,456円')
            ->assertSee('を登録する');
    }

    #[Test]
    public function 金額が不正なら登録不可の文言を表示する(): void
    {
        $user = User::factory()->create();
        $this->initializeUnit($user);

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('amount', '12a34')
            ->assertSee('金額が不正なので登録できません')
            ->assertDontSee('を登録する');
    }

    #[Test]
    public function 源泉徴収ありの売上が即時入金で登録できる()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user, isTaxable: true);

        $revenueSub = $unit->getAccountByName('売上高')->subAccounts()->first();
        $cashSub = $unit->getAccountByName('現金')->subAccounts()->first();
        $withheldTaxSub = $unit->getSubAccountByName('事業主貸', '源泉徴収');

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0401')
            ->set('amount', 10000)
            ->set('tax_option', Standard::TAX_OPTION_EXEMPT)
            ->set('withholding', true)
            ->set('withholding_amount', 1021)
            ->set('note', '源泉あり売上テスト')
            ->set('revenue_sub_account_id', $revenueSub->id)
            ->set('receipt_sub_account_id', $cashSub->id)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('confirming', true)
            ->call('confirm')
            ->assertHasNoErrors()
            ->assertSet('confirming', false)
            ->assertSee(__('transactions.revenue_form.messages.registered'));

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $revenueSub->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 10000,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $cashSub->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 8979,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $withheldTaxSub->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 1021,
        ]);
    }

    #[Test]
    public function 源泉徴収ありのsubmitでは即時登録されずconfirm画面に遷移する()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user, isTaxable: true);

        $revenueSub = $unit->getAccountByName('売上高')->subAccounts()->first();
        $cashSub = $unit->getAccountByName('現金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0401')
            ->set('amount', 11000)
            ->set('tax_option', Standard::TAX_OPTION_10)
            ->set('withholding', true)
            ->set('withholding_amount', 1021)
            ->set('revenue_sub_account_id', $revenueSub->id)
            ->set('receipt_sub_account_id', $cashSub->id)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('confirming', true)
            ->assertDontSee(__('transactions.revenue_form.messages.registered'));

        $this->assertDatabaseMissing('journal_entries', [
            'sub_account_id' => $revenueSub->id,
            'type' => JournalEntry::TYPE_CREDIT,
        ]);
    }

    #[Test]
    public function confirm画面に売上金額と消費税と源泉徴収と差引金額が表示される()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user, isTaxable: true);

        $revenueSub = $unit->getAccountByName('売上高')->subAccounts()->first();
        $cashSub = $unit->getAccountByName('現金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0401')
            ->set('amount', 11000)
            ->set('tax_option', Standard::TAX_OPTION_10)
            ->set('withholding', true)
            ->set('withholding_amount', 1021)
            ->set('revenue_sub_account_id', $revenueSub->id)
            ->set('receipt_sub_account_id', $cashSub->id)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('confirming', true)
            ->assertSee(__('transactions.revenue_form.confirm.title'))
            ->assertSee(__('transactions.revenue_form.confirm.amount_net_taxable'))
            ->assertSee('10,000') // 税抜
            ->assertSee(__('transactions.revenue_form.confirm.tax_10'))
            ->assertSee('1,000') // 消費税
            ->assertSee(__('transactions.revenue_form.confirm.withholding'))
            ->assertSee('1,021') // 源泉徴収
            ->assertSee('9,979') // 差引 (11000 - 1021)
            ->assertSee('4/1 に') // 日付
            ->assertSee('入金された'); // 現金 → 入金された
    }

    #[Test]
    public function confirm画面で銀行入金の場合は振込と表示される()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user, isTaxable: true);

        $revenueSub = $unit->getAccountByName('売上高')->subAccounts()->first();
        $bankAccount = $unit->getAccountByName('その他の預金');
        $bankSub = $bankAccount->createSubAccount(['name' => 'テスト銀行'], $user);

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0401')
            ->set('amount', 10000)
            ->set('tax_option', Standard::TAX_OPTION_EXEMPT)
            ->set('withholding', true)
            ->set('withholding_amount', 1000)
            ->set('revenue_sub_account_id', $revenueSub->id)
            ->set('receipt_sub_account_id', $bankSub->id)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('confirming', true)
            ->assertSee('振り込まれた');
    }

    #[Test]
    public function confirm画面で事業主貸入金は個人の財布に入れた文言になる()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user, isTaxable: true);

        $revenueSub = $unit->getAccountByName('売上高')->subAccounts()->first();
        $ownerDrawSub = $unit->getSubAccountByName('事業主貸', '事業主貸');

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0401')
            ->set('amount', 10000)
            ->set('tax_option', Standard::TAX_OPTION_EXEMPT)
            ->set('withholding', true)
            ->set('withholding_amount', 1000)
            ->set('revenue_sub_account_id', $revenueSub->id)
            ->set('receipt_sub_account_id', $ownerDrawSub->id)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('confirming', true)
            ->assertSee('受け取って、個人の財布に入れた');
    }

    #[Test]
    public function confirm画面で売掛金入金は後日入金予定で計上する文言になる()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user, isTaxable: true);

        $revenueSub = $unit->getAccountByName('売上高')->subAccounts()->first();
        $accountsReceivableSub = $unit->getAccountByName('売掛金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0401')
            ->set('amount', 10000)
            ->set('tax_option', Standard::TAX_OPTION_EXEMPT)
            ->set('withholding', true)
            ->set('withholding_amount', 1000)
            ->set('revenue_sub_account_id', $revenueSub->id)
            ->set('receipt_sub_account_id', $accountsReceivableSub->id)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('confirming', true)
            ->assertSee('後日入金予定で計上する');
    }

    #[Test]
    public function confirm画面からbackで入力画面に戻れる()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user, isTaxable: true);

        $revenueSub = $unit->getAccountByName('売上高')->subAccounts()->first();
        $cashSub = $unit->getAccountByName('現金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0401')
            ->set('amount', 10000)
            ->set('tax_option', Standard::TAX_OPTION_EXEMPT)
            ->set('withholding', true)
            ->set('withholding_amount', 1000)
            ->set('revenue_sub_account_id', $revenueSub->id)
            ->set('receipt_sub_account_id', $cashSub->id)
            ->call('submit')
            ->assertSet('confirming', true)
            ->call('back')
            ->assertSet('confirming', false)
            ->assertSet('amount', 10000)
            ->assertSet('withholding', true)
            ->assertSet('withholding_amount', 1000);
    }

    #[Test]
    public function 源泉徴収なしはconfirmを経由せず即登録される()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $revenueSub = $unit->getAccountByName('売上高')->subAccounts()->first();
        $cashSub = $unit->getAccountByName('現金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0401')
            ->set('amount', 11000)
            ->set('tax_option', Standard::TAX_OPTION_10)
            ->set('revenue_sub_account_id', $revenueSub->id)
            ->set('receipt_sub_account_id', $cashSub->id)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('confirming', false)
            ->assertSee(__('transactions.revenue_form.messages.registered'));
    }

    #[Test]
    public function 取引先を入力すると_counterpartyが作成される()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $revenueSub = $unit->getAccountByName('売上高')->subAccounts()->first();
        $cashSub = $unit->getAccountByName('現金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0401')
            ->set('amount', 10000)
            ->set('tax_option', Standard::TAX_OPTION_EXEMPT)
            ->set('counterparty_name', '株式会社テスト')
            ->set('revenue_sub_account_id', $revenueSub->id)
            ->set('receipt_sub_account_id', $cashSub->id)
            ->call('submit')
            ->assertHasNoErrors();

        $counterparty = $unit->counterparties()->where('name', '株式会社テスト')->firstOrFail();

        $this->assertDatabaseHas('transactions', [
            'counterparty_id' => $counterparty->id,
        ]);
    }

    #[Test]
    public function 他ユーザー事業体の補助科目は売上登録に使えない()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $unit = $this->initializeUnit($user, '自分の事業体');
        $otherUnit = $this->initializeUnit($otherUser, '他人の事業体');

        $ownRevenue = $unit->getAccountByName('売上高')->subAccounts()->first();
        $foreignReceipt = $otherUnit->getAccountByName('現金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0401')
            ->set('amount', 10000)
            ->set('tax_option', Standard::TAX_OPTION_EXEMPT)
            ->set('note', '不正な売上登録')
            ->set('revenue_sub_account_id', $ownRevenue->id)
            ->set('receipt_sub_account_id', $foreignReceipt->id)
            ->call('submit')
            ->assertHasErrors(['receipt_sub_account_id']);

        $this->assertDatabaseMissing('journal_entries', [
            'sub_account_id' => $foreignReceipt->id,
            'type' => JournalEntry::TYPE_DEBIT,
        ]);
    }

    #[Test]
    public function mount時に売上高と源泉徴収の科目を自動取得できる()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $revenueSub = $unit->getAccountByName('売上高')->subAccounts()->first();
        $withheldSub = $unit->getSubAccountByName('事業主貸', '源泉徴収');

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->assertSet('revenue_sub_account_id', $revenueSub->id)
            ->assertSet('withheld_tax_sub_account_id', $withheldSub->id);
    }

    #[Test]
    public function 免税事業者では消費税の選択肢を表示しない()
    {
        $user = User::factory()->create();
        $this->initializeUnit($user, isTaxable: false);

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->assertSet('isTaxable', false)
            ->assertDontSee(__('transactions.revenue_form.fields.tax_option'));
    }

    #[Test]
    public function 免税事業者では消費税の選択を変えてもみなし10パーセントで登録される()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user, isTaxable: false);

        $revenueSub = $unit->getAccountByName('売上高')->subAccounts()->first();
        $cashSub = $unit->getAccountByName('現金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0401')
            ->set('amount', 11000)
            ->set('tax_option', Standard::TAX_OPTION_EXEMPT)
            ->set('revenue_sub_account_id', $revenueSub->id)
            ->set('receipt_sub_account_id', $cashSub->id)
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('tax_option', Standard::TAX_OPTION_10);

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $revenueSub->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 10000,
            'tax_amount' => 1000,
            'tax_type' => JournalEntry::TAX_TYPE_DEEMED_TAXABLE_SALES_10,
        ]);
    }

    #[Test]
    public function 入金先には標準表示の現金と預金サブアカウントが並ぶ()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $cashSub = $unit->getAccountByName('現金')->subAccounts()->first();
        $bankAccount = $unit->getAccountByName('その他の預金');
        $bankSub = $bankAccount->createSubAccount(
            ['name' => 'テスト銀行', 'visibility' => SubAccount::VISIBILITY_STANDARD],
            $user,
        );

        $component = Livewire::actingAs($user)->test(Standard::class);
        $ids = collect($component->instance()->receiptStandardSubAccounts)->pluck('id')->all();

        $this->assertContains($cashSub->id, $ids);
        $this->assertContains($bankSub->id, $ids);
    }

    #[Test]
    public function 入金先のexpanded補助科目は初期表示に含まれない()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $bankAccount = $unit->getAccountByName('その他の預金');
        $hiddenBank = $bankAccount->createSubAccount(
            ['name' => '隠し銀行', 'visibility' => SubAccount::VISIBILITY_EXPANDED],
            $user,
        );

        $component = Livewire::actingAs($user)->test(Standard::class);
        $ids = collect($component->instance()->receiptStandardSubAccounts)->pluck('id')->all();

        $this->assertNotContains($hiddenBank->id, $ids);
    }

    #[Test]
    public function 入金先の事業主貸セクションに事業主貸は含まれ源泉徴収は含まれない()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $ownerDraw = $unit->getSubAccountByName('事業主貸', '事業主貸');
        $withheld = $unit->getSubAccountByName('事業主貸', '源泉徴収');

        $component = Livewire::actingAs($user)->test(Standard::class);
        $ownerDrawIds = collect($component->instance()->receiptOwnerDrawSubAccounts)->pluck('id')->all();
        $standardIds = collect($component->instance()->receiptStandardSubAccounts)->pluck('id')->all();
        $specialIds = collect($component->instance()->receiptSpecialSubAccounts)->pluck('id')->all();
        $allIds = array_merge($standardIds, $ownerDrawIds, $specialIds);

        $this->assertContains($ownerDraw->id, $ownerDrawIds);
        $this->assertNotContains($withheld->id, $allIds);
    }

    #[Test]
    public function 売掛金は入金先の特別セクションに並ぶ()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $accountsReceivable = $unit->getAccountByName('売掛金')->subAccounts()->first();

        $component = Livewire::actingAs($user)->test(Standard::class);
        $specialIds = collect($component->instance()->receiptSpecialSubAccounts)->pluck('id')->all();
        $standardIds = collect($component->instance()->receiptStandardSubAccounts)->pluck('id')->all();

        $this->assertContains($accountsReceivable->id, $specialIds);
        $this->assertNotContains($accountsReceivable->id, $standardIds);
    }

    #[Test]
    public function 源泉徴収ありにチェックすると金額入力欄が表示される()
    {
        $user = User::factory()->create();
        $this->initializeUnit($user);

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('withholding', true)
            ->assertSee(__('transactions.revenue_form.fields.withholding_amount'));
    }

    #[Test]
    public function 日付が未入力だとバリデーションエラーになる()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $revenueSub = $unit->getAccountByName('売上高')->subAccounts()->first();
        $cashSub = $unit->getAccountByName('現金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '')
            ->set('amount', 10000)
            ->set('revenue_sub_account_id', $revenueSub->id)
            ->set('receipt_sub_account_id', $cashSub->id)
            ->call('submit')
            ->assertHasErrors(['date_input' => 'required']);
    }

    #[Test]
    public function 日付が存在しない日だとバリデーションエラーになる()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        $revenueSub = $unit->getAccountByName('売上高')->subAccounts()->first();
        $cashSub = $unit->getAccountByName('現金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0231')
            ->set('amount', 10000)
            ->set('revenue_sub_account_id', $revenueSub->id)
            ->set('receipt_sub_account_id', $cashSub->id)
            ->call('submit')
            ->assertHasErrors(['date_input']);
    }

    #[Test]
    public function 必須入力が不足していると全てのバリデーションエラーが表示される()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $cashSub = $unit->getAccountByName('現金')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', 'abc')
            ->set('amount', null)
            ->set('receipt_sub_account_id', $cashSub->id)
            ->call('submit')
            ->assertHasErrors([
                'date_input' => 'regex',
                'amount' => 'required',
            ]);
    }

    #[Test]
    public function 入金先が未選択だとバリデーションエラーが表示される()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $revenueSub = $unit->getAccountByName('売上高')->subAccounts()->first();

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0403')
            ->set('amount', 10000)
            ->set('revenue_sub_account_id', $revenueSub->id)
            ->set('receipt_sub_account_id', null)
            ->call('submit')
            ->assertHasErrors(['receipt_sub_account_id' => 'required']);
    }

    #[Test]
    public function 銀行サブアカウントに入金された売上が登録できる()
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user, isTaxable: true);

        $revenueSub = $unit->getAccountByName('売上高')->subAccounts()->first();
        $bankAccount = $unit->getAccountByName('その他の預金');
        $bankSub = $bankAccount->createSubAccount(['name' => 'テスト銀行'], $user);

        Livewire::actingAs($user)
            ->test(Standard::class)
            ->set('date_input', '0402')
            ->set('amount', 15000)
            ->set('tax_option', Standard::TAX_OPTION_EXEMPT)
            ->set('note', '銀行売上テスト')
            ->set('revenue_sub_account_id', $revenueSub->id)
            ->set('receipt_sub_account_id', $bankSub->id)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $bankSub->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 15000,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $revenueSub->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 15000,
        ]);
    }
}
