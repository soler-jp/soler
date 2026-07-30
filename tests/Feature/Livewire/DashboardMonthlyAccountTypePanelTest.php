<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Dashboard\MonthlyAccountTypePanel;
use App\Livewire\Dashboard\MonthlyTransactionTable;
use App\Models\BusinessUnit;
use App\Models\Counterparty;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\TransactionRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardMonthlyAccountTypePanelTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 売上の月別合計を表示できる(): void
    {
        [$user, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;
        $counterparty = Counterparty::factory()->create(['business_unit_id' => $unit->id, 'name' => 'A社']);

        $registrar = new TransactionRegistrar;

        $registrar->register($fiscalYear, [
            'date' => '2025-01-10',
            'description' => '1月売上',
            'counterparty_id' => $counterparty->id,
        ], [
            [
                'sub_account_id' => $unit->getAccountByName('現金')->subAccounts()->firstOrFail()->id,
                'type' => 'debit',
                'net_amount' => 11000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $unit->getAccountByName('売上高')->subAccounts()->firstOrFail()->id,
                'type' => 'credit',
                'net_amount' => 11000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $user);

        $registrar->register($fiscalYear, [
            'date' => '2025-02-20',
            'description' => '2月売上',
        ], [
            [
                'sub_account_id' => $unit->getAccountByName('現金')->subAccounts()->firstOrFail()->id,
                'type' => 'debit',
                'net_amount' => 22000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $unit->getAccountByName('売上高')->subAccounts()->firstOrFail()->id,
                'type' => 'credit',
                'net_amount' => 22000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $user);

        Livewire::actingAs($user)
            ->test(MonthlyAccountTypePanel::class, [
                'accountType' => 'revenue',
                'title' => '売上',
            ])
            ->call('openMonthsModal')
            ->assertSet('showMonthsModal', true)
            ->assertSee('2025年1月')
            ->assertSee('11,000 円')
            ->assertSee('2025年2月')
            ->assertSee('22,000 円')
            ->assertSet('totalAmount', 33000)
            ->assertSet('selectedMonth', '2025-01')
            ->call('selectMonth', '2025-01')
            ->assertSet('selectedMonth', '2025-01')
            ->assertSet('showMonthsModal', false)
            ->assertSet('showTransactionsModal', true);
    }

    #[Test]
    public function 売上明細テーブルに必要な列を表示できる(): void
    {
        [$user, $unit] = $this->createInitializedUser();
        $unit->currentFiscalYear->forceFill(['is_taxable' => true])->save();
        $unit->refresh();

        $fiscalYear = $unit->currentFiscalYear;
        $counterparty = Counterparty::factory()->create(['business_unit_id' => $unit->id, 'name' => 'A社']);

        (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-03-15',
            'description' => '売上明細',
            'counterparty_id' => $counterparty->id,
        ], [
            [
                'sub_account_id' => $unit->getAccountByName('現金')->subAccounts()->firstOrFail()->id,
                'type' => 'debit',
                'net_amount' => 15000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $unit->getAccountByName('売上高')->subAccounts()->firstOrFail()->id,
                'type' => 'credit',
                'net_amount' => 15000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $user);

        Livewire::actingAs($user)
            ->test(MonthlyTransactionTable::class, [
                'accountType' => 'revenue',
                'yearMonth' => '2025-03',
            ])
            ->assertSee('入金先(debit)')
            ->assertSee('消費税タイプ')
            ->assertSee('CounterParty')
            ->assertSee('2025-03-15')
            ->assertSee('15,000')
            ->assertSee('現金')
            ->assertSee('不課税')
            ->assertSee('A社');
    }

    #[Test]
    public function 経費明細テーブルに必要な列を表示できる(): void
    {
        [$user, $unit] = $this->createInitializedUser();
        $unit->currentFiscalYear->forceFill(['is_taxable' => true])->save();
        $unit->refresh();

        $fiscalYear = $unit->currentFiscalYear;
        $counterparty = Counterparty::factory()->create(['business_unit_id' => $unit->id, 'name' => '文具店']);

        (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-04-08',
            'description' => '経費明細',
            'counterparty_id' => $counterparty->id,
        ], [
            [
                'sub_account_id' => $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail()->id,
                'type' => 'debit',
                'net_amount' => 4200,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $unit->getAccountByName('現金')->subAccounts()->firstOrFail()->id,
                'type' => 'credit',
                'net_amount' => 4200,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $user);

        Livewire::actingAs($user)
            ->test(MonthlyTransactionTable::class, [
                'accountType' => 'expense',
                'yearMonth' => '2025-04',
            ])
            ->assertSee('支払い勘定科目(debit)')
            ->assertSee('支払い元(credit)')
            ->assertSee('2025-04-08')
            ->assertSee('4,200')
            ->assertSee('消耗品費')
            ->assertSee('現金')
            ->assertSee('不課税')
            ->assertSee('文具店');
    }

    #[Test]
    public function 非課税業者の明細テーブルは消費税タイプを表示しない(): void
    {
        [$user, $unit] = $this->createInitializedUser();
        $unit->currentFiscalYear->forceFill(['is_taxable' => false])->save();
        $unit->refresh();

        $fiscalYear = $unit->currentFiscalYear;
        $counterparty = Counterparty::factory()->create(['business_unit_id' => $unit->id, 'name' => '文具店']);

        (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-05-09',
            'description' => '按分経費',
            'counterparty_id' => $counterparty->id,
        ], [
            [
                'sub_account_id' => $unit->getAccountByName('通信費')->subAccounts()->firstOrFail()->id,
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

        Livewire::actingAs($user)
            ->test(MonthlyTransactionTable::class, [
                'accountType' => 'expense',
                'yearMonth' => '2025-05',
            ])
            ->assertDontSee('消費税タイプ')
            ->assertSee('6,000')
            ->assertSee('按分経費')
            ->assertSee('支払い10,000円の60％分')
            ->assertSee('文具店');
    }

    #[Test]
    public function 取引先と注釈が空ならプレースホルダを出さない(): void
    {
        [$user, $unit] = $this->createInitializedUser();
        $unit->currentFiscalYear->forceFill(['is_taxable' => true])->save();
        $unit->refresh();

        $fiscalYear = $unit->currentFiscalYear;

        (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-06-01',
            'description' => '摘要なし',
        ], [
            [
                'sub_account_id' => $unit->getAccountByName('売上高')->subAccounts()->firstOrFail()->id,
                'type' => 'credit',
                'net_amount' => 1000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $unit->getAccountByName('現金')->subAccounts()->firstOrFail()->id,
                'type' => 'debit',
                'net_amount' => 1000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ], $user);

        Livewire::actingAs($user)
            ->test(MonthlyTransactionTable::class, [
                'accountType' => 'revenue',
                'yearMonth' => '2025-06',
            ])
            ->assertSee('2025-06-01')
            ->assertSee('摘要なし')
            ->assertSeeHtml('<td class="px-4 py-3"></td>')
            ->assertSee('1,000');
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
