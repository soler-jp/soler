<?php

namespace Tests\Feature;

use App\Data\TransactionSearchFilters;
use App\Models\BusinessUnit;
use App\Models\Counterparty;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionJournalSearchTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 借方勘定科目で絞り込める(): void
    {
        [$user, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;

        $bank = $unit->getAccountByName('その他の預金')->createSubAccount(['name' => '三井住友銀行']);
        $sales = $unit->getAccountByName('売上高')->subAccounts()->firstOrFail();
        $cash = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $supplies = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();

        $sale = $this->registerTransaction($fiscalYear, [
            'date' => '2025-01-10',
            'description' => '売上入金',
        ], [
            ['sub_account_id' => $bank->id, 'type' => 'debit', 'net_amount' => 50000],
            ['sub_account_id' => $sales->id, 'type' => 'credit', 'net_amount' => 50000],
        ]);

        $expense = $this->registerTransaction($fiscalYear, [
            'date' => '2025-01-12',
            'description' => '文具購入',
        ], [
            ['sub_account_id' => $supplies->id, 'type' => 'debit', 'net_amount' => 3000],
            ['sub_account_id' => $cash->id, 'type' => 'credit', 'net_amount' => 3000],
        ]);

        $results = $fiscalYear->searchTransactionsForJournal(TransactionSearchFilters::from(
            debitAccountNames: ['その他の預金'],
        ));

        $this->assertSame([$sale->id], collect($results->items())->pluck('id')->all());
        $this->assertNotContains($expense->id, collect($results->items())->pluck('id')->all());
        $this->assertSame('その他の預金 / 三井住友銀行 50,000', $results->items()[0]->debit_summary);
    }

    #[Test]
    public function 貸方勘定科目のみと借方貸方の組み合わせで絞り込める(): void
    {
        [$user, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;

        $bank = $unit->getAccountByName('その他の預金')->createSubAccount(['name' => '楽天銀行']);
        $sales = $unit->getAccountByName('売上高')->subAccounts()->firstOrFail();
        $capital = $unit->getAccountByName('事業主借')->subAccounts()->firstOrFail();

        $sale = $this->registerTransaction($fiscalYear, [
            'date' => '2025-02-05',
            'description' => '売上入金',
        ], [
            ['sub_account_id' => $bank->id, 'type' => 'debit', 'net_amount' => 80000],
            ['sub_account_id' => $sales->id, 'type' => 'credit', 'net_amount' => 80000],
        ]);

        $capitalIn = $this->registerTransaction($fiscalYear, [
            'date' => '2025-02-06',
            'description' => '元入れ',
        ], [
            ['sub_account_id' => $bank->id, 'type' => 'debit', 'net_amount' => 20000],
            ['sub_account_id' => $capital->id, 'type' => 'credit', 'net_amount' => 20000],
        ]);

        $creditOnly = $fiscalYear->searchTransactionsForJournal(TransactionSearchFilters::from(
            creditAccountNames: ['売上高'],
        ));
        $debitAndCredit = $fiscalYear->searchTransactionsForJournal(TransactionSearchFilters::from(
            debitAccountNames: ['その他の預金'],
            creditAccountNames: ['売上高'],
        ));

        $this->assertSame([$sale->id], collect($creditOnly->items())->pluck('id')->all());
        $this->assertSame([$sale->id], collect($debitAndCredit->items())->pluck('id')->all());
        $this->assertNotContains($capitalIn->id, collect($creditOnly->items())->pluck('id')->all());
    }

    #[Test]
    public function フリーワードは摘要と相手先と勘定科目と補助科目を横断検索する(): void
    {
        [$user, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;
        $counterparty = Counterparty::factory()->create([
            'business_unit_id' => $unit->id,
            'name' => '丸の内文具',
        ]);

        $bank = $unit->getAccountByName('その他の預金')->createSubAccount(['name' => '三井住友銀行']);
        $supplies = $unit->getAccountByName('消耗品費')->createSubAccount(['name' => '文具']);

        $transaction = $this->registerTransaction($fiscalYear, [
            'date' => '2025-03-03',
            'description' => '文具まとめ買い',
            'counterparty_id' => $counterparty->id,
        ], [
            ['sub_account_id' => $supplies->id, 'type' => 'debit', 'net_amount' => 12000],
            ['sub_account_id' => $bank->id, 'type' => 'credit', 'net_amount' => 12000],
        ]);

        $descriptionResults = $fiscalYear->searchTransactionsForJournal(TransactionSearchFilters::from(keyword: 'まとめ買い'));
        $counterpartyResults = $fiscalYear->searchTransactionsForJournal(TransactionSearchFilters::from(keyword: '丸の内文具'));
        $accountResults = $fiscalYear->searchTransactionsForJournal(TransactionSearchFilters::from(keyword: '消耗品費'));
        $subAccountResults = $fiscalYear->searchTransactionsForJournal(TransactionSearchFilters::from(keyword: '三井住友銀行'));

        $expected = [$transaction->id];

        $this->assertSame($expected, collect($descriptionResults->items())->pluck('id')->all());
        $this->assertSame($expected, collect($counterpartyResults->items())->pluck('id')->all());
        $this->assertSame($expected, collect($accountResults->items())->pluck('id')->all());
        $this->assertSame($expected, collect($subAccountResults->items())->pluck('id')->all());
    }

    #[Test]
    public function 月フィルタは複数月を対象にできる(): void
    {
        [$user, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;

        $cash = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $sales = $unit->getAccountByName('売上高')->subAccounts()->firstOrFail();

        $january = $this->registerTransaction($fiscalYear, [
            'date' => '2025-01-05',
            'description' => '1月売上',
        ], [
            ['sub_account_id' => $cash->id, 'type' => 'debit', 'net_amount' => 1000],
            ['sub_account_id' => $sales->id, 'type' => 'credit', 'net_amount' => 1000],
        ]);

        $march = $this->registerTransaction($fiscalYear, [
            'date' => '2025-03-05',
            'description' => '3月売上',
        ], [
            ['sub_account_id' => $cash->id, 'type' => 'debit', 'net_amount' => 3000],
            ['sub_account_id' => $sales->id, 'type' => 'credit', 'net_amount' => 3000],
        ]);

        $april = $this->registerTransaction($fiscalYear, [
            'date' => '2025-04-05',
            'description' => '4月売上',
        ], [
            ['sub_account_id' => $cash->id, 'type' => 'debit', 'net_amount' => 4000],
            ['sub_account_id' => $sales->id, 'type' => 'credit', 'net_amount' => 4000],
        ]);

        $results = $fiscalYear->searchTransactionsForJournal(TransactionSearchFilters::from(
            months: [1, 3],
        ));

        $this->assertSame(
            [$march->id, $january->id],
            collect($results->items())->pluck('id')->all(),
        );
        $this->assertNotContains($april->id, collect($results->items())->pluck('id')->all());
    }

    #[Test]
    public function 金額一致と範囲検索ができる(): void
    {
        [$user, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;

        $cash = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $sales = $unit->getAccountByName('売上高')->subAccounts()->firstOrFail();

        $small = $this->registerTransaction($fiscalYear, [
            'date' => '2025-05-01',
            'description' => '少額売上',
        ], [
            ['sub_account_id' => $cash->id, 'type' => 'debit', 'net_amount' => 5000],
            ['sub_account_id' => $sales->id, 'type' => 'credit', 'net_amount' => 5000],
        ]);

        $medium = $this->registerTransaction($fiscalYear, [
            'date' => '2025-05-02',
            'description' => '中額売上',
        ], [
            ['sub_account_id' => $cash->id, 'type' => 'debit', 'net_amount' => 12000],
            ['sub_account_id' => $sales->id, 'type' => 'credit', 'net_amount' => 12000],
        ]);

        $large = $this->registerTransaction($fiscalYear, [
            'date' => '2025-05-03',
            'description' => '高額売上',
        ], [
            ['sub_account_id' => $cash->id, 'type' => 'debit', 'net_amount' => 30000],
            ['sub_account_id' => $sales->id, 'type' => 'credit', 'net_amount' => 30000],
        ]);

        $exactResults = $fiscalYear->searchTransactionsForJournal(TransactionSearchFilters::from(
            exactAmount: 12000,
        ));
        $rangeResults = $fiscalYear->searchTransactionsForJournal(TransactionSearchFilters::from(
            minAmount: 6000,
            maxAmount: 20000,
        ));

        $this->assertSame([$medium->id], collect($exactResults->items())->pluck('id')->all());
        $this->assertSame([$medium->id], collect($rangeResults->items())->pluck('id')->all());
        $this->assertNotContains($small->id, collect($rangeResults->items())->pluck('id')->all());
        $this->assertNotContains($large->id, collect($rangeResults->items())->pluck('id')->all());
    }

    #[Test]
    public function 無効取引と予定取引を除外し複数明細でも1transactionとして返す(): void
    {
        [$user, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;

        $cash = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $supplies = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();
        $travel = $unit->getAccountByName('旅費交通費')->subAccounts()->firstOrFail();
        $capital = $unit->getAccountByName('事業主借')->subAccounts()->firstOrFail();

        $split = $this->registerTransaction($fiscalYear, [
            'date' => '2025-06-01',
            'description' => '複合経費',
        ], [
            ['sub_account_id' => $supplies->id, 'type' => 'debit', 'net_amount' => 4000, 'tax_type' => JournalEntry::TAX_TYPE_NON_TAXABLE],
            ['sub_account_id' => $travel->id, 'type' => 'debit', 'net_amount' => 6000, 'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10],
            ['sub_account_id' => $cash->id, 'type' => 'credit', 'net_amount' => 10000, 'tax_type' => JournalEntry::TAX_TYPE_NON_TAXABLE],
        ]);

        $inactive = $this->registerTransaction($fiscalYear, [
            'date' => '2025-06-02',
            'description' => '無効取引',
        ], [
            ['sub_account_id' => $cash->id, 'type' => 'debit', 'net_amount' => 5000],
            ['sub_account_id' => $capital->id, 'type' => 'credit', 'net_amount' => 5000],
        ]);
        $inactive->deactivate($user, 'test');

        $planned = Transaction::factory()->create([
            'fiscal_year_id' => $fiscalYear->id,
            'date' => '2025-06-03',
            'description' => '予定取引',
            'is_planned' => true,
            'is_active' => true,
        ]);
        $planned->journalEntries()->createMany([
            ['sub_account_id' => $cash->id, 'type' => 'debit', 'net_amount' => 9000],
            ['sub_account_id' => $capital->id, 'type' => 'credit', 'net_amount' => 9000],
        ]);

        $results = $fiscalYear->searchTransactionsForJournal(TransactionSearchFilters::from());

        $this->assertSame([$split->id], collect($results->items())->pluck('id')->all());
        $this->assertSame('消耗品費 4,000 / 旅費交通費 6,000', $results->items()[0]->debit_summary);
        $this->assertSame('現金 10,000', $results->items()[0]->credit_summary);
        $this->assertSame('非課税 / 10%', $results->items()[0]->journal_tax_type_summary);
    }

    /**
     * @return array{0: User, 1: BusinessUnit}
     */
    private function createInitializedUser(): array
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '仕訳帳テスト事業']);
        $unit->createFiscalYear(2025);
        $unit->refresh();

        return [$user, $unit];
    }

    /**
     * @param  array<string, mixed>  $transactionData
     * @param  array<int, array<string, mixed>>  $journalEntriesData
     */
    private function registerTransaction(
        FiscalYear $fiscalYear,
        array $transactionData,
        array $journalEntriesData,
    ): Transaction {
        return (new TransactionRegistrar)->register($fiscalYear, $transactionData, $journalEntriesData);
    }
}
