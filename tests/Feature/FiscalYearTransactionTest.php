<?php

namespace Tests\Feature;

use App\Models\FiscalYear;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use DomainException;
use InvalidArgumentException;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class FiscalYearTransactionTest extends TestCase
{
    use RefreshDatabase;

    private function createSubAccount(): array
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
            'type' => 'general',
            'is_taxable' => false,
            'is_tax_exclusive' => false,
        ]);
        $fiscalYear = $unit->createFiscalYear(2025);

        $account = $unit->createAccount([
            'name' => 'テスト科目',
            'type' => 'asset',
        ]);

        return [$fiscalYear, $account->subAccounts->first()];
    }

    #[Test]
    public function 取引と仕訳を正常に登録できる()
    {
        [$fiscalYear, $sub] = $this->createSubAccount();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => '文房具を現金で購入',
        ];

        $journalEntriesData = [
            ['sub_account_id' => $sub->id, 'type' => 'debit',  'amount' => 1000],
            ['sub_account_id' => $sub->id, 'type' => 'credit', 'amount' => 1000],
        ];

        $transaction = $fiscalYear->registerTransaction($transactionData, $journalEntriesData);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'fiscal_year_id' => $fiscalYear->id,
            'description' => '文房具を現金で購入',
        ]);

        $this->assertDatabaseCount('journal_entries', 2);
    }

    #[Test]
    public function 借方と貸方の金額が一致しないと例外を投げる()
    {
        $this->expectException(DomainException::class);

        [$fiscalYear, $sub] = $this->createSubAccount();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'バランスエラー',
        ];

        $journalEntriesData = [
            ['sub_account_id' => $sub->id, 'type' => 'debit',  'amount' => 1000],
            ['sub_account_id' => $sub->id, 'type' => 'credit', 'amount' => 900],
        ];

        $fiscalYear->registerTransaction($transactionData, $journalEntriesData);
    }

    #[Test]
    public function 仕訳が空だと例外を投げる()
    {
        $this->expectException(InvalidArgumentException::class);

        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
            'type' => 'general',
            'is_taxable' => false,
            'is_tax_exclusive' => false,
        ]);
        $fiscalYear = $unit->createFiscalYear(2025);

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => '仕訳なし',
        ];

        $journalEntriesData = [];

        $fiscalYear->registerTransaction($transactionData, $journalEntriesData);
    }

    #[Test]
    public function 不正な取引データでバリデーション例外を投げる()
    {
        $this->expectException(ValidationException::class);

        [$fiscalYear, $sub] = $this->createSubAccount();

        $transactionData = [
            'date' => null,
            'description' => '',
        ];

        $journalEntriesData = [
            ['sub_account_id' => $sub->id, 'type' => 'debit',  'amount' => 500],
            ['sub_account_id' => $sub->id, 'type' => 'credit', 'amount' => 500],
        ];

        $fiscalYear->registerTransaction($transactionData, $journalEntriesData);
    }

    #[Test]
    public function 不正な仕訳データでバリデーション例外を投げる()
    {
        $this->expectException(ValidationException::class);

        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業体',
            'type' => 'general',
            'is_taxable' => false,
            'is_tax_exclusive' => false,
        ]);
        $fiscalYear = $unit->createFiscalYear(2025);

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => '仕訳バリデーション',
        ];

        $journalEntriesData = [
            ['sub_account_id' => null, 'type' => 'debit',  'amount' => 1000],
            ['sub_account_id' => null, 'type' => 'credit', 'amount' => 1000],
        ];

        $fiscalYear->registerTransaction($transactionData, $journalEntriesData);
    }

    #[Test]
    public function fiscal_year_idが自動的に取引に設定される()
    {
        [$fiscalYear, $sub] = $this->createSubAccount();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'fiscal_year_id自動設定',
        ];

        $journalEntriesData = [
            ['sub_account_id' => $sub->id, 'type' => 'debit',  'amount' => 1000],
            ['sub_account_id' => $sub->id, 'type' => 'credit', 'amount' => 1000],
        ];

        $transaction = $fiscalYear->registerTransaction($transactionData, $journalEntriesData);

        $this->assertEquals($fiscalYear->id, $transaction->fiscal_year_id);
    }

    #[Test]
    public function 仕訳が登録された取引に正しく紐づく()
    {
        [$fiscalYear, $sub] = $this->createSubAccount();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => '紐づき確認',
        ];

        $journalEntriesData = [
            ['sub_account_id' => $sub->id, 'type' => 'debit',  'amount' => 500],
            ['sub_account_id' => $sub->id, 'type' => 'credit', 'amount' => 500],
        ];

        $transaction = $fiscalYear->registerTransaction($transactionData, $journalEntriesData);

        $this->assertCount(2, $transaction->journalEntries);
        $this->assertTrue(
            $transaction->journalEntries->every(fn($entry) => $entry->transaction_id === $transaction->id)
        );
    }
}
