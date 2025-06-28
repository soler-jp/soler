<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Services\TransactionRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Illuminate\Validation\ValidationException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TransactionRegistrarTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 単一仕訳で取引が登録できる()
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

        $registrar = new TransactionRegistrar();
        $transaction = $registrar->register($fiscalYear, $transactionData, $journalEntriesData);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'description' => '文房具を現金で購入',
        ]);

        $this->assertDatabaseCount('journal_entries', 2);
        $this->assertEquals($transaction->id, $transaction->journalEntries()->first()->transaction_id);
    }


    #[Test]
    public function fiscalYearがnullだと登録できない()
    {
        $this->expectException(ValidationException::class);

        $registrar = new TransactionRegistrar();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'テスト取引',
        ];

        $journalEntriesData = [
            [
                'account_id' => 1,
                'type' => 'debit',
                'amount' => 1000,
            ],
            [
                'account_id' => 1,
                'type' => 'credit',
                'amount' => 1000,
            ],
        ];
        $registrar->register(null, $transactionData, $journalEntriesData);
    }

    #[Test]
    public function 不正な仕訳データの場合は登録できない()
    {
        $fiscalYear = FiscalYear::factory()->create();
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $registrar = new TransactionRegistrar();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => '不正な仕訳テスト',
        ];

        $account = Account::factory()->create();

        $journalEntriesData = [
            [
                'account_id' => $account->id,
                'type' => 'debit',
                'amount' => 1000,
            ],
            [
                'account_id' => 99999, // 存在しないID
                'type' => 'credit',
                'amount' => 1000,
            ],
        ];

        $registrar->register($fiscalYear, $transactionData, $journalEntriesData);
    }

    #[Test]
    public function 仕訳の金額バランスが崩れている場合は登録できない()
    {
        $fiscalYear = FiscalYear::factory()->create();
        $account = Account::factory()->create();

        $registrar = new TransactionRegistrar();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'バランス崩れ',
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
                'amount' => 800, // バランスが崩れている
            ],
        ];

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('仕訳の金額がバランスしていません（借方: 1000 / 貸方: 800 / 差額: +200）');

        $registrar->register($fiscalYear, $transactionData, $journalEntriesData);
    }

    #[Test]
    public function journal_entryのaccount_idがnullだと登録できない()
    {
        $this->expectException(ValidationException::class);

        $fiscalYear = FiscalYear::factory()->create();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'エラー検証',
        ];

        $journalEntriesData = [
            ['account_id' => null, 'type' => 'debit', 'amount' => 1000],
            ['account_id' => 1, 'type' => 'credit', 'amount' => 1000],
        ];

        (new TransactionRegistrar())->register($fiscalYear, $transactionData, $journalEntriesData);
    }

    #[Test]
    public function 仕訳が1件しかないと登録できない()
    {
        $this->expectException(\DomainException::class);

        $fiscalYear = FiscalYear::factory()->create();
        $account = Account::factory()->create();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => '仕訳1件のみ',
        ];

        $journalEntriesData = [
            ['account_id' => $account->id, 'type' => 'debit', 'amount' => 1000],
        ];

        (new TransactionRegistrar())->register($fiscalYear, $transactionData, $journalEntriesData);
    }

    #[Test]
    public function 借方と貸方の金額が一致しないと登録できない()
    {
        $this->expectException(\DomainException::class);

        $fiscalYear = FiscalYear::factory()->create();
        $account = Account::factory()->create();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'バランス不一致',
        ];

        $journalEntriesData = [
            ['account_id' => $account->id, 'type' => 'debit', 'amount' => 1000],
            ['account_id' => $account->id, 'type' => 'credit', 'amount' => 900],
        ];

        (new TransactionRegistrar())->register($fiscalYear, $transactionData, $journalEntriesData);
    }

    #[Test]
    public function 日付が不正な形式だと登録できない()
    {
        $this->expectException(ValidationException::class);

        $fiscalYear = FiscalYear::factory()->create();
        $account = Account::factory()->create();

        $transactionData = [
            'date' => 'invalid-date',
            'description' => '不正日付',
        ];

        $journalEntriesData = [
            ['account_id' => $account->id, 'type' => 'debit', 'amount' => 500],
            ['account_id' => $account->id, 'type' => 'credit', 'amount' => 500],
        ];

        (new TransactionRegistrar())->register($fiscalYear, $transactionData, $journalEntriesData);
    }

    #[Test]
    public function 金額が0円だと登録できない()
    {
        $this->expectException(ValidationException::class);

        $fiscalYear = FiscalYear::factory()->create();
        $account = Account::factory()->create();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => '金額ゼロ',
        ];

        $journalEntriesData = [
            ['account_id' => $account->id, 'type' => 'debit', 'amount' => 0],
            ['account_id' => $account->id, 'type' => 'credit', 'amount' => 0],
        ];

        (new TransactionRegistrar())->register($fiscalYear, $transactionData, $journalEntriesData);
    }

    #[Test]
    public function typeが不正な値だと登録できない()
    {
        $this->expectException(ValidationException::class);

        $fiscalYear = FiscalYear::factory()->create();
        $account = Account::factory()->create();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => '不正type',
        ];

        $journalEntriesData = [
            ['account_id' => $account->id, 'type' => 'invalid', 'amount' => 1000],
            ['account_id' => $account->id, 'type' => 'credit', 'amount' => 1000],
        ];

        (new TransactionRegistrar())->register($fiscalYear, $transactionData, $journalEntriesData);
    }

    #[Test]
    public function journalEntriesが空配列だと登録できない()
    {
        $this->expectException(\InvalidArgumentException::class);

        $fiscalYear = FiscalYear::factory()->create();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => '仕訳なし',
        ];

        $journalEntriesData = [];

        (new TransactionRegistrar())->register($fiscalYear, $transactionData, $journalEntriesData);
    }

    #[Test]
    public function journalEntriesがすべてdebitだと登録できない()
    {
        $this->expectException(\DomainException::class);

        $fiscalYear = FiscalYear::factory()->create();
        $account = Account::factory()->create();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => '借方のみ',
        ];

        $journalEntriesData = [
            ['account_id' => $account->id, 'type' => 'debit', 'amount' => 1000],
            ['account_id' => $account->id, 'type' => 'debit', 'amount' => 1000],
        ];

        (new TransactionRegistrar())->register($fiscalYear, $transactionData, $journalEntriesData);
    }



    #[Test]
    public function tax_amount込みでもバランスが正しければ登録できる()
    {
        $fiscalYear = FiscalYear::factory()->create();
        $debitAccount = Account::factory()->create();
        $creditAccount = Account::factory()->create();

        $registrar = new TransactionRegistrar();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'tax_amount込みバランステスト',
        ];

        $journalEntriesData = [
            [
                'account_id' => $debitAccount->id,
                'type' => 'debit',
                'amount' => 5000,
                'tax_amount' => 500,
                'tax_type' => 'taxable_purchases_10',
            ],
            [
                'account_id' => $creditAccount->id,
                'type' => 'credit',
                'amount' => 5500,
                'tax_amount' => 0,
                'tax_type' => 'non_taxable',
            ],
        ];

        $transaction = $registrar->register($fiscalYear, $transactionData, $journalEntriesData);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'description' => 'tax_amount込みバランステスト',
        ]);
    }

    #[Test]
    public function tax_amountが不整合でバランスが崩れる場合は登録できない()
    {
        $fiscalYear = FiscalYear::factory()->create();
        $debitAccount = Account::factory()->create();
        $creditAccount = Account::factory()->create();

        $registrar = new TransactionRegistrar();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'tax_amount不整合によるバランスエラー',
        ];

        $journalEntriesData = [
            [
                'account_id' => $debitAccount->id,
                'type' => 'debit',
                'amount' => 5000,
                'tax_amount' => 500,
                'tax_type' => 'taxable_purchases_10',
            ],
            [
                'account_id' => $creditAccount->id,
                'type' => 'credit',
                'amount' => 5400,
                'tax_amount' => 0,
                'tax_type' => 'non_taxable',
            ],
        ];

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('仕訳の金額がバランスしていません');

        $registrar->register($fiscalYear, $transactionData, $journalEntriesData);
    }

    #[Test]
    public function tax_amountがマイナスだと登録できない()
    {
        $fiscalYear = FiscalYear::factory()->create();
        $debitAccount = Account::factory()->create();
        $creditAccount = Account::factory()->create();

        $registrar = new TransactionRegistrar();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'マイナス税額',
        ];

        $journalEntriesData = [
            [
                'account_id' => $debitAccount->id,
                'type' => 'debit',
                'amount' => 5000,
                'tax_amount' => -100,
                'tax_type' => 'taxable_purchases_10',
            ],
            [
                'account_id' => $creditAccount->id,
                'type' => 'credit',
                'amount' => 4900,
                'tax_amount' => 0,
                'tax_type' => 'non_taxable',
            ],
        ];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('消費税額');

        $registrar->register($fiscalYear, $transactionData, $journalEntriesData);
    }

    #[Test]
    public function tax_typeが記述していて、tax_amountがnullだと登録できない()
    {

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('消費税額');

        $fiscalYear = FiscalYear::factory()->create();
        $debitAccount = Account::factory()->create();
        $creditAccount = Account::factory()->create();

        $registrar = new TransactionRegistrar();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'tax_amount null 許容',
        ];

        $journalEntriesData = [
            [
                'account_id' => $debitAccount->id,
                'type' => 'debit',
                'amount' => 3000,
                'tax_amount' => null,
                'tax_type' => 'non_taxable',
            ],
            [
                'account_id' => $creditAccount->id,
                'type' => 'credit',
                'amount' => 3000,
                'tax_amount' => null,
                'tax_type' => 'non_taxable',
            ],
        ];

        $transaction = $registrar->register($fiscalYear, $transactionData, $journalEntriesData);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'description' => 'tax_amount null 許容',
        ]);
    }

    #[Test]
    public function tax_typeを記述していて、tax_amountが0だと登録できる()
    {
        $fiscalYear = FiscalYear::factory()->create();
        $debitAccount = Account::factory()->create();
        $creditAccount = Account::factory()->create();

        $registrar = new TransactionRegistrar();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'tax_amount null 許容',
        ];

        $journalEntriesData = [
            [
                'account_id' => $debitAccount->id,
                'type' => 'debit',
                'amount' => 3000,
                'tax_amount' => 0,
                'tax_type' => 'non_taxable',
            ],
            [
                'account_id' => $creditAccount->id,
                'type' => 'credit',
                'amount' => 3000,
                'tax_amount' => 0,
                'tax_type' => 'non_taxable',
            ],
        ];

        $transaction = $registrar->register($fiscalYear, $transactionData, $journalEntriesData);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'description' => 'tax_amount null 許容',
        ]);
    }

    #[Test]
    public function tax_amountが片方だけ指定された場合もバランスが取れていれば登録できる()
    {
        $fiscalYear = FiscalYear::factory()->create();
        $debitAccount = Account::factory()->create();
        $creditAccount = Account::factory()->create();

        $registrar = new TransactionRegistrar();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'tax_amount片側のみ',
        ];

        $journalEntriesData = [
            [
                'account_id' => $debitAccount->id,
                'type' => 'debit',
                'amount' => 4500,
                'tax_amount' => 500,
                'tax_type' => 'taxable_purchases_10',
            ],
            [
                'account_id' => $creditAccount->id,
                'type' => 'credit',
                'amount' => 5000,
            ],
        ];

        $transaction = $registrar->register($fiscalYear, $transactionData, $journalEntriesData);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'description' => 'tax_amount片側のみ',
        ]);
    }

    #[Test]
    public function is_plannedをtrueにして取引を登録できる()
    {
        $user = User::factory()->create();

        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'Test帳簿',
        ]);

        $fiscalYear = $unit->createFiscalYear(2025);

        $account = $unit->accounts()->first(); // デフォルトAccountを利用

        $registrar = new TransactionRegistrar();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => '予定取引テスト',
            'is_planned' => true,
        ];

        $journalEntries = [
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

        $transaction = $registrar->register($fiscalYear, $transactionData, $journalEntries);

        $this->assertTrue($transaction->is_planned);
    }
}
