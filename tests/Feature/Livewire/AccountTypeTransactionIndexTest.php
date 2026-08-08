<?php

namespace Tests\Feature\Livewire;

use App\Auditing\AuditEvent;
use App\Livewire\Pages\AccountTypeTransactionIndex;
use App\Models\AuditLog;
use App\Models\BusinessUnit;
use App\Models\Counterparty;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AccountTypeTransactionIndexTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 売上一覧ページを表示できる(): void
    {
        [$user] = $this->createInitializedUser();

        $this->actingAs($user);

        $response = $this->get(route('transactions.revenues'));

        $response->assertOk();
        $response->assertSeeLivewire(AccountTypeTransactionIndex::class);
    }

    #[Test]
    public function 経費の種類別一覧ページを表示できる(): void
    {
        [$user] = $this->createInitializedUser();

        $this->actingAs($user);

        $response = $this->get(route('transactions.expense-types'));

        $response->assertOk();
        $response->assertSee('経費の種類別一覧');
        $response->assertSeeLivewire(AccountTypeTransactionIndex::class);
    }

    #[Test]
    public function 仕入棚卸一覧は仕入と期末棚卸を含み通常経費は含めない(): void
    {
        [$user, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;
        $counterparty = Counterparty::factory()->create(['business_unit_id' => $unit->id, 'name' => '仕入先']);

        $purchase = $unit->getAccountByName('仕入金額')->subAccounts()->firstOrFail();
        $expense = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();
        $cash = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $inventoryAsset = $unit->getAccountByName('棚卸資産')->subAccounts()->firstOrFail();
        $endingInventory = $unit->getAccountByName('期末商品（棚卸高）')->subAccounts()->firstOrFail();

        $registrar = new TransactionRegistrar;

        $registrar->register($fiscalYear, [
            'date' => '2025-01-10',
            'description' => '仕入れ',
            'counterparty_id' => $counterparty->id,
        ], [
            [
                'sub_account_id' => $purchase->id,
                'type' => 'debit',
                'net_amount' => 12000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'credit',
                'net_amount' => 12000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $user);

        $registrar->register($fiscalYear, [
            'date' => '2025-01-20',
            'description' => '通常経費',
        ], [
            [
                'sub_account_id' => $expense->id,
                'type' => 'debit',
                'net_amount' => 3000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'credit',
                'net_amount' => 3000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $user);

        $registrar->register($fiscalYear, [
            'date' => '2025-12-15',
            'description' => '期末棚卸',
        ], [
            [
                'sub_account_id' => $inventoryAsset->id,
                'type' => 'debit',
                'net_amount' => 4200,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $endingInventory->id,
                'type' => 'credit',
                'net_amount' => 4200,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $user);

        $component = Livewire::actingAs($user)
            ->test(AccountTypeTransactionIndex::class, ['kind' => 'purchase']);

        $component
            ->assertSee('12,000')
            ->assertSee('仕入金額')
            ->assertSee('仕入先')
            ->assertSee('期末棚卸')
            ->assertSee('期末商品（棚卸高）')
            ->assertDontSee('消耗品費');

        $this->assertSame('仕入・棚卸一覧', $component->get('title'));
        $this->assertSame(
            ['期首商品（棚卸高）', '仕入金額', '期末商品（棚卸高）'],
            $component->get('accountNames'),
        );
    }

    #[Test]
    public function 経費一覧は按分金額と注釈を表示し非課税なら消費税タイプを出さない(): void
    {
        [$user, $unit] = $this->createInitializedUser();
        $unit->currentFiscalYear->forceFill(['is_taxable' => false])->save();
        $unit->refresh();

        $fiscalYear = $unit->currentFiscalYear;
        $counterparty = Counterparty::factory()->create(['business_unit_id' => $unit->id, 'name' => '文具店']);

        $registrar = new TransactionRegistrar;

        $registrar->register($fiscalYear, [
            'date' => '2025-03-15',
            'description' => '按分経費',
            'counterparty_id' => $counterparty->id,
        ], [
            [
                'sub_account_id' => $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail()->id,
                'type' => 'debit',
                'gross_amount' => 10000,
                'business_ratio' => 60,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $unit->getAccountByName('現金')->subAccounts()->firstOrFail()->id,
                'type' => 'credit',
                'gross_amount' => 10000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $user);

        $registrar->register($fiscalYear, [
            'date' => '2025-03-20',
            'description' => '仕入れ',
            'counterparty_id' => $unit->counterparties()->create(['name' => '仕入先B'])->id,
        ], [
            [
                'sub_account_id' => $unit->getAccountByName('仕入金額')->subAccounts()->firstOrFail()->id,
                'type' => 'debit',
                'net_amount' => 12000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $unit->getAccountByName('現金')->subAccounts()->firstOrFail()->id,
                'type' => 'credit',
                'net_amount' => 12000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $user);

        Livewire::actingAs($user)
            ->test(AccountTypeTransactionIndex::class, ['kind' => 'expense'])
            ->assertSee('6,000')
            ->assertSee('按分経費')
            ->assertSee('支払い10,000円の60％分')
            ->assertSee('消耗品費')
            ->assertSee('現金')
            ->assertDontSee('家事按分')
            ->assertSee('文具店')
            ->assertDontSee('消費税タイプ')
            ->assertDontSee('仕入先B')
            ->assertDontSee('仕入金額');
    }

    #[Test]
    public function 売上一覧は月が昇順で表示される(): void
    {
        [$user, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;
        $cash = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $sales = $unit->getAccountByName('売上高')->subAccounts()->firstOrFail();

        $registrar = new TransactionRegistrar;

        $registrar->register($fiscalYear, [
            'date' => '2025-02-01',
            'description' => '2月売上',
        ], [
            [
                'sub_account_id' => $cash->id,
                'type' => 'debit',
                'net_amount' => 2000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $sales->id,
                'type' => 'credit',
                'net_amount' => 2000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $user);

        $registrar->register($fiscalYear, [
            'date' => '2025-01-01',
            'description' => '1月売上',
        ], [
            [
                'sub_account_id' => $cash->id,
                'type' => 'debit',
                'net_amount' => 1000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $sales->id,
                'type' => 'credit',
                'net_amount' => 1000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $user);

        $component = Livewire::actingAs($user)
            ->test(AccountTypeTransactionIndex::class, ['kind' => 'revenue']);

        $months = $component->get('months');

        $this->assertCount(12, $months);
        $this->assertSame('2025-01', $months[0]['year_month']);
        $this->assertSame('2025-02', $months[1]['year_month']);
        $this->assertSame('2025-12', $months[11]['year_month']);
    }

    #[Test]
    public function 売上一覧は月タブと前年タブを切り替えられる(): void
    {
        [$user, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;
        $cash = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $sales = $unit->getAccountByName('売上高')->subAccounts()->firstOrFail();

        $registrar = new TransactionRegistrar;

        $registrar->register($fiscalYear, [
            'date' => '2025-01-10',
            'description' => '1月売上',
        ], [
            [
                'sub_account_id' => $cash->id,
                'type' => 'debit',
                'net_amount' => 1000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $sales->id,
                'type' => 'credit',
                'net_amount' => 1000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $user);

        $registrar->register($fiscalYear, [
            'date' => '2025-02-20',
            'description' => '2月売上',
        ], [
            [
                'sub_account_id' => $cash->id,
                'type' => 'debit',
                'net_amount' => 2000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $sales->id,
                'type' => 'credit',
                'net_amount' => 2000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $user);

        Livewire::actingAs($user)
            ->test(AccountTypeTransactionIndex::class, ['kind' => 'revenue'])
            ->assertSet('selectedPeriod', AccountTypeTransactionIndex::YEARLY_PERIOD)
            ->assertSee('全年')
            ->assertSee('1月売上')
            ->assertSee('2月売上')
            ->call('selectPeriod', '2025-02')
            ->assertSet('selectedPeriod', '2025-02')
            ->assertSee('2月売上')
            ->assertDontSee('1月売上')
            ->call('selectPeriod', AccountTypeTransactionIndex::YEARLY_PERIOD)
            ->assertSee('1月売上')
            ->assertSee('2月売上');
    }

    #[Test]
    public function 経費の種類別一覧は複数選択した科目を日付順に表示する(): void
    {
        [$user, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;
        $cash = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $supplies = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();
        $travel = $unit->getAccountByName('旅費交通費')->subAccounts()->firstOrFail();
        $purchase = $unit->getAccountByName('仕入金額')->subAccounts()->firstOrFail();

        $registrar = new TransactionRegistrar;

        $registrar->register($fiscalYear, [
            'date' => '2025-03-10',
            'description' => '消耗品',
        ], [
            [
                'sub_account_id' => $supplies->id,
                'type' => 'debit',
                'net_amount' => 1000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'credit',
                'net_amount' => 1000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $user);

        $registrar->register($fiscalYear, [
            'date' => '2025-01-20',
            'description' => '交通費',
        ], [
            [
                'sub_account_id' => $travel->id,
                'type' => 'debit',
                'net_amount' => 2000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'credit',
                'net_amount' => 2000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $user);

        $registrar->register($fiscalYear, [
            'date' => '2025-02-05',
            'description' => '仕入れ',
        ], [
            [
                'sub_account_id' => $purchase->id,
                'type' => 'debit',
                'net_amount' => 3000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'credit',
                'net_amount' => 3000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $user);

        $component = Livewire::actingAs($user)
            ->test(AccountTypeTransactionIndex::class, ['kind' => 'expense_type'])
            ->assertSet('groupByMonth', false)
            ->assertSee('消耗品費')
            ->assertSee('1件')
            ->assertSee('旅費交通費')
            ->assertDontSee('仕入金額')
            ->call('clearAccountNames')
            ->assertSet('accountNames', [])
            ->assertSee('表示する経費の種類を選ぶと、対象取引がここに表示されます。')
            ->call('selectAllAccountNames')
            ->set('accountNames', ['旅費交通費', '消耗品費'])
            ->assertSee('交通費')
            ->assertSee('消耗品')
            ->assertDontSee('仕入れ');

        $transactions = $component->get('transactions');

        $this->assertCount(2, $transactions);
        $this->assertSame('2025-01-20', $transactions[0]['date']);
        $this->assertSame('旅費交通費', $transactions[0]['debit_label']);
        $this->assertSame('2025-03-10', $transactions[1]['date']);
        $this->assertSame('消耗品費', $transactions[1]['debit_label']);
    }

    #[Test]
    public function 経費一覧から取引を帳簿上削除できる(): void
    {
        [$user, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;
        $cash = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $supplies = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();

        $registrar = new TransactionRegistrar;

        $transaction = $registrar->register($fiscalYear, [
            'date' => '2025-04-01',
            'description' => '削除対象の経費',
        ], [
            [
                'sub_account_id' => $supplies->id,
                'type' => 'debit',
                'net_amount' => 1500,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'credit',
                'net_amount' => 1500,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $user);

        $component = Livewire::actingAs($user)
            ->test(AccountTypeTransactionIndex::class, ['kind' => 'expense'])
            ->assertSee('削除対象の経費');

        $component->call('deleteTransaction', $transaction->id)
            ->assertDispatched('dashboard-transaction-created')
            ->assertDontSee('削除対象の経費');

        $this->assertFalse($transaction->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'business_unit_id' => $unit->id,
            'event_type' => AuditEvent::TransactionDeactivated->value,
            'actor_id' => $user->id,
            'reason' => '利用者による削除',
        ]);

        $auditLog = AuditLog::query()->latest('id')->firstOrFail();

        $this->assertTrue($auditLog->targets()->exists());
    }

    #[Test]
    public function 経費の種類別一覧から取引を帳簿上削除できる(): void
    {
        [$user, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;
        $cash = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $supplies = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();

        $registrar = new TransactionRegistrar;

        $transaction = $registrar->register($fiscalYear, [
            'date' => '2025-05-05',
            'description' => '種類別で削除',
        ], [
            [
                'sub_account_id' => $supplies->id,
                'type' => 'debit',
                'net_amount' => 2500,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'credit',
                'net_amount' => 2500,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $user);

        $component = Livewire::actingAs($user)
            ->test(AccountTypeTransactionIndex::class, ['kind' => 'expense_type'])
            ->set('accountNames', ['消耗品費'])
            ->assertSee('種類別で削除');

        $component->call('deleteTransaction', $transaction->id)
            ->assertDispatched('dashboard-transaction-created')
            ->assertDontSee('種類別で削除');

        $this->assertFalse($transaction->fresh()->is_active);
        $this->assertSame([], $component->get('transactions'));
    }

    #[Test]
    public function 別ユーザーの取引は削除できない(): void
    {
        [$user, $unit] = $this->createInitializedUser();
        [$otherUser, $otherUnit] = $this->createInitializedUser();
        $otherFiscalYear = $otherUnit->currentFiscalYear;
        $cash = $otherUnit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $supplies = $otherUnit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();

        $registrar = new TransactionRegistrar;

        $otherTransaction = $registrar->register($otherFiscalYear, [
            'date' => '2025-04-10',
            'description' => '他人の経費',
        ], [
            [
                'sub_account_id' => $supplies->id,
                'type' => 'debit',
                'net_amount' => 1000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'credit',
                'net_amount' => 1000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $otherUser);

        Livewire::actingAs($user)
            ->test(AccountTypeTransactionIndex::class, ['kind' => 'expense'])
            ->call('deleteTransaction', $otherTransaction->id)
            ->assertStatus(403);

        $this->assertTrue(Transaction::find($otherTransaction->id)->is_active);
    }

    #[Test]
    public function お金の動き一覧ページを表示できる(): void
    {
        [$user] = $this->createInitializedUser();

        $this->actingAs($user);

        $response = $this->get(route('transactions.others'));

        $response->assertOk();
        $response->assertSee('お金の移動の登録・確認');
        $response->assertSeeLivewire(AccountTypeTransactionIndex::class);
    }

    #[Test]
    public function お金の動き一覧は損益科目を含む取引を除外する(): void
    {
        [$user, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;

        $cash = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $bank = $unit->getAccountByName('当座預金')->subAccounts()->firstOrFail();
        $ownerDrawings = $unit->getAccountByName('事業主借')->subAccounts()->firstOrFail();
        $sales = $unit->getAccountByName('売上高')->subAccounts()->firstOrFail();
        $supplies = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();
        $purchase = $unit->getAccountByName('仕入金額')->subAccounts()->firstOrFail();
        $endingInventory = $unit->getAccountByName('期末商品（棚卸高）')->subAccounts()->firstOrFail();
        $inventoryAsset = $unit->getAccountByName('棚卸資産')->subAccounts()->firstOrFail();

        $registrar = new TransactionRegistrar;

        // 除外される: 売上
        $registrar->register($fiscalYear, [
            'date' => '2025-01-05',
            'description' => '売上入金',
        ], [
            ['sub_account_id' => $cash->id, 'type' => 'debit', 'net_amount' => 5000, 'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE],
            ['sub_account_id' => $sales->id, 'type' => 'credit', 'net_amount' => 5000, 'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE],
        ], $user);

        // 除外される: 経費
        $registrar->register($fiscalYear, [
            'date' => '2025-01-06',
            'description' => '文具購入',
        ], [
            ['sub_account_id' => $supplies->id, 'type' => 'debit', 'net_amount' => 300, 'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE],
            ['sub_account_id' => $cash->id, 'type' => 'credit', 'net_amount' => 300, 'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE],
        ], $user);

        // 除外される: 仕入
        $registrar->register($fiscalYear, [
            'date' => '2025-01-07',
            'description' => '商品仕入',
        ], [
            ['sub_account_id' => $purchase->id, 'type' => 'debit', 'net_amount' => 1200, 'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE],
            ['sub_account_id' => $cash->id, 'type' => 'credit', 'net_amount' => 1200, 'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE],
        ], $user);

        // 除外される: 期末棚卸 (期末商品（棚卸高）は損益科目)
        $registrar->register($fiscalYear, [
            'date' => '2025-12-31',
            'description' => '期末棚卸',
        ], [
            ['sub_account_id' => $inventoryAsset->id, 'type' => 'debit', 'net_amount' => 800, 'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE],
            ['sub_account_id' => $endingInventory->id, 'type' => 'credit', 'net_amount' => 800, 'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE],
        ], $user);

        // 含まれる: 資産↔資産の振替
        $registrar->register($fiscalYear, [
            'date' => '2025-02-10',
            'description' => '当座預金から現金へ引き出し',
        ], [
            ['sub_account_id' => $cash->id, 'type' => 'debit', 'net_amount' => 10000, 'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE],
            ['sub_account_id' => $bank->id, 'type' => 'credit', 'net_amount' => 10000, 'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE],
        ], $user);

        // 含まれる: 資本→資産（自己資金投入）
        $registrar->register($fiscalYear, [
            'date' => '2025-03-01',
            'description' => '自己資金投入',
        ], [
            ['sub_account_id' => $bank->id, 'type' => 'debit', 'net_amount' => 20000, 'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE],
            ['sub_account_id' => $ownerDrawings->id, 'type' => 'credit', 'net_amount' => 20000, 'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE],
        ], $user);

        $component = Livewire::actingAs($user)
            ->test(AccountTypeTransactionIndex::class, ['kind' => AccountTypeTransactionIndex::KIND_OTHER]);

        $component
            ->assertSee('お金の移動の登録・確認')
            ->assertSee('当座預金から現金へ引き出し')
            ->assertSee('自己資金投入')
            ->assertDontSee('売上入金')
            ->assertDontSee('文具購入')
            ->assertDontSee('商品仕入')
            ->assertDontSee('期末棚卸');

        $transactions = $component->get('transactions');

        $this->assertCount(2, $transactions);
        $this->assertSame('2025-02-10', $transactions[0]['date']);
        $this->assertSame(10000, $transactions[0]['amount']);
        $this->assertSame('現金', $transactions[0]['debit_label']);
        $this->assertSame('当座預金', $transactions[0]['credit_label']);
        $this->assertSame('2025-03-01', $transactions[1]['date']);
        $this->assertSame(20000, $transactions[1]['amount']);
        $this->assertSame('当座預金', $transactions[1]['debit_label']);
        $this->assertSame('事業主借', $transactions[1]['credit_label']);
    }

    #[Test]
    public function お金の移動一覧は許可科目のみ編集可能とする(): void
    {
        [$user, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;

        $cash = $unit->getAccountByName('現金')->addCustomSubAccount('レジ', $user);
        $bank = $unit->getAccountByName('その他の預金')->addCustomSubAccount('メイン口座', $user);
        $inventoryAsset = $unit->getAccountByName('棚卸資産')->subAccounts()->firstOrFail();
        $owner = $unit->getAccountByName('事業主借')->subAccounts()->firstOrFail();

        $registrar = new TransactionRegistrar;

        $editableTransaction = $registrar->register($fiscalYear, [
            'date' => '2025-02-10',
            'description' => '預金から現金へ引き出し',
        ], [
            ['sub_account_id' => $cash->id, 'type' => 'debit', 'net_amount' => 10000, 'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE],
            ['sub_account_id' => $bank->id, 'type' => 'credit', 'net_amount' => 10000, 'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE],
        ], $user);

        $nonEditableTransaction = $registrar->register($fiscalYear, [
            'date' => '2025-02-11',
            'description' => '棚卸資産を現金購入',
        ], [
            ['sub_account_id' => $inventoryAsset->id, 'type' => 'debit', 'net_amount' => 5000, 'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE],
            ['sub_account_id' => $owner->id, 'type' => 'credit', 'net_amount' => 5000, 'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE],
        ], $user);

        $transactions = Livewire::actingAs($user)
            ->test(AccountTypeTransactionIndex::class, ['kind' => AccountTypeTransactionIndex::KIND_OTHER])
            ->get('transactions');

        $editableRow = collect($transactions)->firstWhere('id', $editableTransaction->id);
        $nonEditableRow = collect($transactions)->firstWhere('id', $nonEditableTransaction->id);

        $this->assertTrue($editableRow['is_single_pair']);
        $this->assertFalse($nonEditableRow['is_single_pair']);
    }

    /**
     * @return array{0: User, 1: BusinessUnit}
     */
    private function createInitializedUser(): array
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $unit->createFiscalYear(2025, $user);
        $unit->refresh();

        return [$user, $unit];
    }
}
