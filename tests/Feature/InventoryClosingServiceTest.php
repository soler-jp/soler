<?php

namespace Tests\Feature;

use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InventoryClosingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InventoryClosingServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, int>  $openingBySubAccountName  [棚卸資産SubAccount名 => 期首残高]
     */
    private function makeFiscalYearWithOpeningInventory(array $openingBySubAccountName): FiscalYear
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
        ]);
        $fiscalYear = $unit->createFiscalYear(2025);

        $entries = [];
        foreach ($openingBySubAccountName as $subAccountName => $amount) {
            $entries[] = [
                'account_name' => '棚卸資産',
                'sub_account_name' => $subAccountName,
                'amount' => $amount,
            ];
        }

        if ($entries !== []) {
            $fiscalYear->registerOpeningEntry($entries);
        }

        return $fiscalYear;
    }

    private function inventorySubAccountId(FiscalYear $fiscalYear, string $subAccountName): int
    {
        return $fiscalYear->businessUnit->getSubAccountByName('棚卸資産', $subAccountName)->id;
    }

    private function inventoryAssetBalance(FiscalYear $fiscalYear): int
    {
        foreach ($fiscalYear->calculateBalanceSummary()['asset']['accounts'] as $account) {
            if ($account['account_name'] === '棚卸資産') {
                return $account['balance'];
            }
        }

        return 0;
    }

    private function inventorySubAccountBalance(FiscalYear $fiscalYear, string $subAccountName): int
    {
        foreach ($fiscalYear->calculateBalanceSummary()['asset']['accounts'] as $account) {
            if ($account['account_name'] !== '棚卸資産') {
                continue;
            }

            foreach ($account['sub_accounts'] as $subAccount) {
                if ($subAccount['sub_account_name'] === $subAccountName) {
                    return $subAccount['balance'];
                }
            }
        }

        return 0;
    }

    #[Test]
    public function 単一の棚卸資産_sub_accountで期首と期末を振り替えられる()
    {
        $fiscalYear = $this->makeFiscalYearWithOpeningInventory(['棚卸資産' => 400]);

        $transaction = app(InventoryClosingService::class)->registerFor($fiscalYear, [
            $this->inventorySubAccountId($fiscalYear, '棚卸資産') => 500,
        ]);

        $this->assertNotNull($transaction);
        $this->assertTrue($transaction->is_adjusting_entry);
        $this->assertSame(Transaction::ADJUSTING_ENTRY_TYPE_INVENTORY_CLOSING, $transaction->adjusting_entry_type);
        $this->assertSame($fiscalYear->end_date->toDateString(), $transaction->date->toDateString());
        $this->assertCount(4, $transaction->journalEntries);

        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $transaction->id,
            'sub_account_id' => $fiscalYear->businessUnit->getSubAccountByName('期首商品（棚卸高）', '期首商品（棚卸高）')->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 400,
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $transaction->id,
            'sub_account_id' => $fiscalYear->businessUnit->getSubAccountByName('期末商品（棚卸高）', '期末商品（棚卸高）')->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 500,
        ]);

        $this->assertSame(500, $this->inventoryAssetBalance($fiscalYear));
        $this->assertSame(-100, $fiscalYear->calculateAmountSummary()['actual']['expenses']['gross_amount']);
    }

    #[Test]
    public function sub_accountを分離している場合は_sub_account単位で振り替える()
    {
        $fiscalYear = $this->makeFiscalYearWithOpeningInventory(['商品' => 300, '製品' => 100]);

        $transaction = app(InventoryClosingService::class)->registerFor($fiscalYear, [
            $this->inventorySubAccountId($fiscalYear, '棚卸資産') => 0,
            $this->inventorySubAccountId($fiscalYear, '商品') => 500,
            $this->inventorySubAccountId($fiscalYear, '製品') => 150,
        ]);

        $this->assertNotNull($transaction);

        // 期首商品（合算400）/ 棚卸資産:商品300・製品100 / 棚卸資産:商品500・製品150 / 期末商品（合算650）
        $this->assertCount(6, $transaction->journalEntries);

        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $transaction->id,
            'sub_account_id' => $fiscalYear->businessUnit->getSubAccountByName('期首商品（棚卸高）', '期首商品（棚卸高）')->id,
            'type' => JournalEntry::TYPE_DEBIT,
            'net_amount' => 400,
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $transaction->id,
            'sub_account_id' => $fiscalYear->businessUnit->getSubAccountByName('期末商品（棚卸高）', '期末商品（棚卸高）')->id,
            'type' => JournalEntry::TYPE_CREDIT,
            'net_amount' => 650,
        ]);

        // SubAccount 別の残高が実態と一致する
        $this->assertSame(500, $this->inventorySubAccountBalance($fiscalYear, '商品'));
        $this->assertSame(150, $this->inventorySubAccountBalance($fiscalYear, '製品'));
        $this->assertSame(650, $this->inventoryAssetBalance($fiscalYear));

        // 売上原価調整 = 期首400 - 期末650 = -250
        $this->assertSame(-250, $fiscalYear->calculateAmountSummary()['actual']['expenses']['gross_amount']);
    }

    #[Test]
    public function 期末棚卸高を指定しない_sub_accountがあると例外になる()
    {
        $fiscalYear = $this->makeFiscalYearWithOpeningInventory(['商品' => 300, '製品' => 100]);

        $this->expectException(\InvalidArgumentException::class);

        app(InventoryClosingService::class)->registerFor($fiscalYear, [
            $this->inventorySubAccountId($fiscalYear, '棚卸資産') => 0,
            $this->inventorySubAccountId($fiscalYear, '商品') => 500,
        ]);
    }

    #[Test]
    public function 期首棚卸高がない場合は期末分だけ登録される()
    {
        $fiscalYear = $this->makeFiscalYearWithOpeningInventory([]);

        $transaction = app(InventoryClosingService::class)->registerFor($fiscalYear, [
            $this->inventorySubAccountId($fiscalYear, '棚卸資産') => 500,
        ]);

        $this->assertNotNull($transaction);
        $this->assertCount(2, $transaction->journalEntries);
        $this->assertSame(500, $this->inventoryAssetBalance($fiscalYear));
        $this->assertSame(-500, $fiscalYear->calculateAmountSummary()['actual']['expenses']['gross_amount']);
    }

    #[Test]
    public function 期首も期末も0なら登録せずnullを返す()
    {
        $fiscalYear = $this->makeFiscalYearWithOpeningInventory([]);

        $transaction = app(InventoryClosingService::class)->registerFor($fiscalYear, [
            $this->inventorySubAccountId($fiscalYear, '棚卸資産') => 0,
        ]);

        $this->assertNull($transaction);
        $this->assertDatabaseMissing('transactions', [
            'fiscal_year_id' => $fiscalYear->id,
            'adjusting_entry_type' => Transaction::ADJUSTING_ENTRY_TYPE_INVENTORY_CLOSING,
        ]);
    }

    #[Test]
    public function 期末棚卸高は単一_sub_accountでも0を含めて指定が必要である()
    {
        $fiscalYear = $this->makeFiscalYearWithOpeningInventory([]);

        $this->expectException(\InvalidArgumentException::class);

        app(InventoryClosingService::class)->registerFor($fiscalYear, []);
    }

    #[Test]
    public function 棚卸資産以外の_sub_accountを指定すると例外になる()
    {
        $fiscalYear = $this->makeFiscalYearWithOpeningInventory(['棚卸資産' => 400]);
        $cashSubAccountId = $fiscalYear->businessUnit->getSubAccountByName('現金', '現金')->id;

        $this->expectException(\InvalidArgumentException::class);

        app(InventoryClosingService::class)->registerFor($fiscalYear, [
            $cashSubAccountId => 500,
        ]);
    }

    #[Test]
    public function 期末棚卸高が負数なら例外になる()
    {
        $fiscalYear = $this->makeFiscalYearWithOpeningInventory(['棚卸資産' => 400]);

        $this->expectException(\InvalidArgumentException::class);

        app(InventoryClosingService::class)->registerFor($fiscalYear, [
            $this->inventorySubAccountId($fiscalYear, '棚卸資産') => -1,
        ]);
    }

    #[Test]
    public function 期末棚卸高が整数でない値なら例外になる()
    {
        $fiscalYear = $this->makeFiscalYearWithOpeningInventory(['棚卸資産' => 400]);

        $this->expectException(\InvalidArgumentException::class);

        app(InventoryClosingService::class)->registerFor($fiscalYear, [
            $this->inventorySubAccountId($fiscalYear, '棚卸資産') => '1.5',
        ]);
    }

    #[Test]
    public function すでに棚卸の決算整理仕訳がある年度には二重登録できない()
    {
        $fiscalYear = $this->makeFiscalYearWithOpeningInventory(['棚卸資産' => 400]);
        $subAccountId = $this->inventorySubAccountId($fiscalYear, '棚卸資産');
        app(InventoryClosingService::class)->registerFor($fiscalYear, [$subAccountId => 500]);

        $this->expectException(\InvalidArgumentException::class);

        app(InventoryClosingService::class)->registerFor($fiscalYear, [$subAccountId => 600]);
    }

    #[Test]
    public function 無効化した棚卸仕訳がある場合は登録し直せる()
    {
        $fiscalYear = $this->makeFiscalYearWithOpeningInventory(['棚卸資産' => 400]);
        $subAccountId = $this->inventorySubAccountId($fiscalYear, '棚卸資産');
        $first = app(InventoryClosingService::class)->registerFor($fiscalYear, [$subAccountId => 500]);

        $first->deactivate(null, '決算整理仕訳の修正');

        $second = app(InventoryClosingService::class)->registerFor($fiscalYear, [$subAccountId => 600]);

        $this->assertNotNull($second);
        $this->assertNotSame($first->id, $second->id);
        // 期首棚卸高は期首仕訳から導出するため、無効化した仕訳には影響されない
        $this->assertSame(600, $this->inventoryAssetBalance($fiscalYear));
    }

    #[Test]
    public function 決算済みの年度には登録できない()
    {
        $fiscalYear = $this->makeFiscalYearWithOpeningInventory(['棚卸資産' => 400]);
        $subAccountId = $this->inventorySubAccountId($fiscalYear, '棚卸資産');
        $fiscalYear->update(['is_closed' => true]);

        $this->expectException(ValidationException::class);

        app(InventoryClosingService::class)->registerFor($fiscalYear, [$subAccountId => 500]);
    }
}
