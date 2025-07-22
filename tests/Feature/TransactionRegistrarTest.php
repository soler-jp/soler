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

        $subAccount = $account->subAccounts->first();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => '文房具を現金で購入',
        ];

        $journalEntriesData = [
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'debit',
                'amount' => 1000,
            ],
            [
                'sub_account_id' => $subAccount->id,
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
        $subAccount = $account->subAccounts->first();

        $registrar = new TransactionRegistrar();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'バランス崩れ',
        ];

        $journalEntriesData = [
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'debit',
                'amount' => 1000,
            ],
            [
                'sub_account_id' => $subAccount->id,
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
        $subAccount = $account->subAccounts->first();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => '仕訳1件のみ',
        ];

        $journalEntriesData = [
            ['sub_account_id' => $subAccount->id, 'type' => 'debit', 'amount' => 1000],
        ];

        (new TransactionRegistrar())->register($fiscalYear, $transactionData, $journalEntriesData);
    }

    #[Test]
    public function 借方と貸方の金額が一致しないと登録できない()
    {
        $this->expectException(\DomainException::class);

        $fiscalYear = FiscalYear::factory()->create();
        $account = Account::factory()->create();
        $subAccount = $account->subAccounts->first();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'バランス不一致',
        ];

        $journalEntriesData = [
            ['sub_account_id' => $subAccount->id, 'type' => 'debit', 'amount' => 1000],
            ['sub_account_id' => $subAccount->id, 'type' => 'credit', 'amount' => 900],
        ];

        (new TransactionRegistrar())->register($fiscalYear, $transactionData, $journalEntriesData);
    }

    #[Test]
    public function 日付が不正な形式だと登録できない()
    {
        $this->expectException(ValidationException::class);

        $fiscalYear = FiscalYear::factory()->create();
        $account = Account::factory()->create();
        $subAccount = $account->subAccounts->first();

        $transactionData = [
            'date' => 'invalid-date',
            'description' => '不正日付',
        ];

        $journalEntriesData = [
            ['sub_account_id' => $subAccount->id, 'type' => 'debit', 'amount' => 500],
            ['sub_account_id' => $subAccount->id, 'type' => 'credit', 'amount' => 500],
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
        $subAccount = $account->subAccounts->first();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => '不正type',
        ];

        $journalEntriesData = [
            ['sub_account_id' => $subAccount->id, 'type' => 'invalid', 'amount' => 1000],
            ['sub_account_id' => $subAccount->id, 'type' => 'credit', 'amount' => 1000],
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
        $subAccount = $account->subAccounts->first();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => '借方のみ',
        ];

        $journalEntriesData = [
            ['sub_account_id' => $subAccount->id, 'type' => 'debit', 'amount' => 1000],
            ['sub_account_id' => $subAccount->id, 'type' => 'debit', 'amount' => 1000],
        ];

        (new TransactionRegistrar())->register($fiscalYear, $transactionData, $journalEntriesData);
    }



    #[Test]
    public function tax_amount込みでもバランスが正しければ登録できる()
    {
        $fiscalYear = FiscalYear::factory()->create();
        $debitSubAccount = Account::factory()->create()->subAccounts->first();
        $creditSubAccount = Account::factory()->create()->subAccounts->first();

        $registrar = new TransactionRegistrar();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'tax_amount込みバランステスト',
        ];

        $journalEntriesData = [
            [
                'sub_account_id' => $debitSubAccount->id,
                'type' => 'debit',
                'amount' => 5000,
                'tax_amount' => 500,
                'tax_type' => 'taxable_purchases_10',
            ],
            [
                'sub_account_id' => $creditSubAccount->id,
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
        $debitSubAccount = Account::factory()->create()->subAccounts->first();
        $creditSubAccount = Account::factory()->create()->subAccounts->first();

        $registrar = new TransactionRegistrar();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'tax_amount不整合によるバランスエラー',
        ];

        $journalEntriesData = [
            [
                'sub_account_id' => $debitSubAccount->id,
                'type' => 'debit',
                'amount' => 5000,
                'tax_amount' => 500,
                'tax_type' => 'taxable_purchases_10',
            ],
            [
                'sub_account_id' => $creditSubAccount->id,
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
        $debitSubAccount = Account::factory()->create()->subAccounts->first();
        $creditSubAccount = Account::factory()->create()->subAccounts->first();

        $registrar = new TransactionRegistrar();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'マイナス税額',
        ];

        $journalEntriesData = [
            [
                'sub_account_id' => $debitSubAccount->id,
                'type' => 'debit',
                'amount' => 5000,
                'tax_amount' => -100,
                'tax_type' => 'taxable_purchases_10',
            ],
            [
                'sub_account_id' => $creditSubAccount->id,
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
        $debitSubAccount = Account::factory()->create()->subAccounts->first();
        $creditSubAccount = Account::factory()->create()->subAccounts->first();

        $registrar = new TransactionRegistrar();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'tax_amount null 許容',
        ];

        $journalEntriesData = [
            [
                'sub_account_id' => $debitSubAccount->id,
                'type' => 'debit',
                'amount' => 3000,
                'tax_amount' => null,
                'tax_type' => 'non_taxable',
            ],
            [
                'sub_account_id' => $creditSubAccount->id,
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
        $debitSubAccount = Account::factory()->create()->subAccounts->first();
        $creditSubAccount = Account::factory()->create()->subAccounts->first();

        $registrar = new TransactionRegistrar();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'tax_amount null 許容',
        ];

        $journalEntriesData = [
            [
                'sub_account_id' => $debitSubAccount->id,
                'type' => 'debit',
                'amount' => 3000,
                'tax_amount' => 0,
                'tax_type' => 'non_taxable',
            ],
            [
                'sub_account_id' => $creditSubAccount->id,
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
        $debitSubAccount = Account::factory()->create()->subAccounts->first();
        $creditSubAccount = Account::factory()->create()->subAccounts->first();

        $registrar = new TransactionRegistrar();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'tax_amount片側のみ',
        ];

        $journalEntriesData = [
            [
                'sub_account_id' => $debitSubAccount->id,
                'type' => 'debit',
                'amount' => 4500,
                'tax_amount' => 500,
                'tax_type' => 'taxable_purchases_10',
            ],
            [
                'sub_account_id' => $creditSubAccount->id,
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
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'Test帳簿']);
        $fiscalYear = $unit->createFiscalYear(2025);

        $account = $unit->accounts()->first();
        $subAccount = $account->subAccounts->first();

        $registrar = new TransactionRegistrar();

        $transaction = $registrar->register($fiscalYear, [
            'date' => now()->toDateString(),
            'description' => '予定取引テスト',
            'is_planned' => true,
        ], [
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'debit',
                'amount' => 1000,
            ],
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'credit',
                'amount' => 1000,
            ],
        ]);

        $this->assertTrue($transaction->is_planned);
    }

    #[Test]
    public function 予定取引を本登録に変更できる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear = $unit->createFiscalYear(2025);

        $expense = $unit->subAccounts()->whereHas('account', fn($q) => $q->where('name', '通信費'))->first();
        $asset = $unit->subAccounts()->whereHas('account', fn($q) => $q->where('name', '現金'))->first();

        $registrar = new TransactionRegistrar();

        $transaction = $registrar->register($fiscalYear, [
            'date' => '2025-04-01',
            'description' => '予定取引',
            'is_planned' => true,
        ], [
            [
                'sub_account_id' => $expense->id,
                'type' => 'debit',
                'amount' => 1000,
                'tax_type' => 'taxable_purchases_10',
                'tax_amount' => 100,
            ],
            [
                'sub_account_id' => $asset->id,
                'type' => 'credit',
                'amount' => 1100,
            ],
        ]);

        $transaction->description = '本登録済み';
        $transaction->date = '2025-04-02';

        $confirmed = $registrar->confirmPlanned($transaction);

        $this->assertFalse($confirmed->is_planned);
        $this->assertSame('本登録済み', $confirmed->description);
        $this->assertSame('2025-04-02', $confirmed->date->toDateString());
    }

    #[Test]
    public function 本登録時に仕訳の内容を変更できて元の仕訳は上書きされる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear = $unit->createFiscalYear(2025);

        $expense = $unit->subAccounts()->whereHas('account', fn($q) => $q->where('name', '通信費'))->first();
        $asset = $unit->subAccounts()->whereHas('account', fn($q) => $q->where('name', '現金'))->first();
        $liability = $unit->subAccounts()->whereHas('account', fn($q) => $q->where('name', '未払金'))->first();

        $registrar = new TransactionRegistrar();

        $transaction = $registrar->register($fiscalYear, [
            'date' => '2025-04-01',
            'description' => '予定取引',
            'is_planned' => true,
        ], [
            [
                'sub_account_id' => $expense->id,
                'type' => 'debit',
                'amount' => 1000,
                'tax_type' => 'taxable_purchases_10',
                'tax_amount' => 100,
            ],
            [
                'sub_account_id' => $asset->id,
                'type' => 'credit',
                'amount' => 1100,
            ],
        ]);

        $originalIds = $transaction->journalEntries->pluck('id')->toArray();

        $transaction->description = '本登録済み（仕訳変更）';
        $transaction->date = '2025-04-02';

        $debit = $transaction->journalEntries->firstWhere('type', 'debit');
        $credit = $transaction->journalEntries->firstWhere('type', 'credit');

        $debit->amount = 2000;
        $debit->tax_amount = 200;

        $credit->amount = 2200;
        $credit->sub_account_id = $liability->id;

        $confirmed = $registrar->confirmPlanned($transaction);

        $this->assertFalse($confirmed->is_planned);
        $this->assertSame('本登録済み（仕訳変更）', $confirmed->description);
        $this->assertSame('2025-04-02', $confirmed->date->toDateString());
        $this->assertCount(2, $confirmed->journalEntries);

        foreach ($confirmed->journalEntries as $entry) {
            $this->assertContains($entry->id, $originalIds);
        }

        $this->assertSame(2000, $confirmed->journalEntries->firstWhere('type', 'debit')->amount);
        $this->assertSame(2200, $confirmed->journalEntries->firstWhere('type', 'credit')->amount);
        $this->assertSame($liability->id, $confirmed->journalEntries->firstWhere('type', 'credit')->sub_account_id);
    }

    #[Test]
    public function 予定取引を0円の本登録に変換して取消できる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear = $unit->createFiscalYear(2025);

        $expense = $unit->subAccounts()->whereHas('account', fn($q) => $q->where('name', '通信費'))->first();
        $asset = $unit->subAccounts()->whereHas('account', fn($q) => $q->where('name', '現金'))->first();

        $registrar = new TransactionRegistrar();

        $transaction = $registrar->register($fiscalYear, [
            'date' => '2025-05-01',
            'description' => '取消予定取引',
            'is_planned' => true,
        ], [
            [
                'sub_account_id' => $expense->id,
                'type' => 'debit',
                'amount' => 1000,
                'tax_type' => 'taxable_purchases_10',
                'tax_amount' => 100,
            ],
            [
                'sub_account_id' => $asset->id,
                'type' => 'credit',
                'amount' => 1100,
            ],
        ]);

        $originalIds = $transaction->journalEntries->pluck('id')->toArray();

        foreach ($transaction->journalEntries as $entry) {
            $entry->amount = 0;
        }

        $transaction->description = '取消予定取引（取消）';

        $cancelled = $registrar->confirmPlanned($transaction);

        $this->assertFalse($cancelled->is_planned);
        $this->assertSame('取消予定取引（取消）', $cancelled->description);
        $this->assertCount(2, $cancelled->journalEntries);
        $this->assertTrue($cancelled->journalEntries->every(fn($e) => $e->amount === 0));

        foreach ($cancelled->journalEntries as $entry) {
            $this->assertContains($entry->id, $originalIds);
        }
    }

    #[Test]
    public function 本登録済みの取引は取消できない()
    {
        $this->expectException(\InvalidArgumentException::class);

        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear = $unit->createFiscalYear(2025);

        $expense = $unit->subAccounts()->whereHas('account', fn($q) => $q->where('name', '通信費'))->first();
        $asset = $unit->subAccounts()->whereHas('account', fn($q) => $q->where('name', '現金'))->first();

        $registrar = new TransactionRegistrar();

        $transaction = $registrar->register($fiscalYear, [
            'date' => '2025-05-01',
            'description' => '本登録取引',
            'is_planned' => false,
        ], [
            [
                'sub_account_id' => $expense->id,
                'type' => 'debit',
                'amount' => 1000,
            ],
            [
                'sub_account_id' => $asset->id,
                'type' => 'credit',
                'amount' => 1000,
            ],
        ]);

        $registrar->cancelPlanned($transaction);
    }
}
