<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use DomainException;
use InvalidArgumentException;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class FiscalYearTransactionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 取引と仕訳を正常に登録できる()
    {
        $fiscalYear = FiscalYear::factory()->create();
        $account = Account::factory()->create();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => '文房具を現金で購入',
        ];

        $journalEntriesData = [
            [
                'account_id' => $account->id,
                'type' => 'debit',
                'amount' => 1000,
            ],
            [
                'account_id' => $account->id,
                'type' => 'credit',
                'amount' => 1000,
            ],
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

        $fiscalYear = FiscalYear::factory()->create();
        $account = Account::factory()->create();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'バランスエラー',
        ];

        $journalEntriesData = [
            [
                'account_id' => $account->id,
                'type' => 'debit',
                'amount' => 1000,
            ],
            [
                'account_id' => $account->id,
                'type' => 'credit',
                'amount' => 900,
            ],
        ];

        $fiscalYear->registerTransaction($transactionData, $journalEntriesData);
    }

    #[Test]
    public function 仕訳が空だと例外を投げる()
    {
        $this->expectException(InvalidArgumentException::class);

        $fiscalYear = FiscalYear::factory()->create();

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

        $fiscalYear = FiscalYear::factory()->create();
        $account = Account::factory()->create();

        $transactionData = [
            'date' => null,
            'description' => '',
        ];

        $journalEntriesData = [
            [
                'account_id' => $account->id,
                'type' => 'debit',
                'amount' => 500,
            ],
            [
                'account_id' => $account->id,
                'type' => 'credit',
                'amount' => 500,
            ],
        ];

        $fiscalYear->registerTransaction($transactionData, $journalEntriesData);
    }

    #[Test]
    public function 不正な仕訳データでバリデーション例外を投げる()
    {
        $this->expectException(ValidationException::class);

        $fiscalYear = FiscalYear::factory()->create();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => '仕訳バリデーション',
        ];

        $journalEntriesData = [
            [
                'account_id' => null,
                'type' => 'debit',
                'amount' => 1000,
            ],
            [
                'account_id' => null,
                'type' => 'credit',
                'amount' => 1000,
            ],
        ];

        $fiscalYear->registerTransaction($transactionData, $journalEntriesData);
    }

    #[Test]
    public function fiscal_year_idが自動的に取引に設定される()
    {
        $fiscalYear = FiscalYear::factory()->create();
        $account = Account::factory()->create();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'fiscal_year_id自動設定',
        ];

        $journalEntriesData = [
            [
                'account_id' => $account->id,
                'type' => 'debit',
                'amount' => 1000,
            ],
            [
                'account_id' => $account->id,
                'type' => 'credit',
                'amount' => 1000,
            ],
        ];

        $transaction = $fiscalYear->registerTransaction($transactionData, $journalEntriesData);

        $this->assertEquals($fiscalYear->id, $transaction->fiscal_year_id);
    }

    #[Test]
    public function 仕訳が登録された取引に正しく紐づく()
    {
        $fiscalYear = FiscalYear::factory()->create();
        $account = Account::factory()->create();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => '紐づき確認',
        ];

        $journalEntriesData = [
            [
                'account_id' => $account->id,
                'type' => 'debit',
                'amount' => 500,
            ],
            [
                'account_id' => $account->id,
                'type' => 'credit',
                'amount' => 500,
            ],
        ];

        $transaction = $fiscalYear->registerTransaction($transactionData, $journalEntriesData);

        $this->assertCount(2, $transaction->journalEntries);
        $this->assertTrue(
            $transaction->journalEntries->every(fn($entry) => $entry->transaction_id === $transaction->id)
        );
    }
}
