<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Counterparty;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionRegistrar;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionRegistrarTest extends TestCase
{
    use RefreshDatabase;

    private function createSubAccountForFiscalYear(FiscalYear $fiscalYear): array
    {
        $account = Account::factory()->create([
            'business_unit_id' => $fiscalYear->business_unit_id,
        ]);

        return [$account, $account->subAccounts->first()];
    }

    private function createTwoSubAccountsForFiscalYear(FiscalYear $fiscalYear): array
    {
        [, $debitSubAccount] = $this->createSubAccountForFiscalYear($fiscalYear);
        [, $creditSubAccount] = $this->createSubAccountForFiscalYear($fiscalYear);

        return [$debitSubAccount, $creditSubAccount];
    }

    private function createBusinessUnitFiscalYear(array $attributes = []): FiscalYear
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(array_merge([
            'name' => 'テスト事業体',
        ], $attributes));

        return $unit->createFiscalYear(2025);
    }

    #[Test]
    public function 単一仕訳で取引が登録できる()
    {
        $fiscalYear = FiscalYear::factory()->create();
        [, $subAccount] = $this->createSubAccountForFiscalYear($fiscalYear);

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => '文房具を現金で購入',
        ];

        $journalEntriesData = [
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'debit',
                'net_amount' => 1000,
            ],
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'credit',
                'net_amount' => 1000,
            ],
        ];

        $registrar = new TransactionRegistrar;
        $transaction = $registrar->register($fiscalYear, $transactionData, $journalEntriesData);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'description' => '文房具を現金で購入',
        ]);

        $this->assertDatabaseCount('journal_entries', 2);
        $this->assertEquals($transaction->id, $transaction->journalEntries()->first()->transaction_id);
    }

    #[Test]
    public function net_amountを使って取引が登録できる()
    {
        $fiscalYear = FiscalYear::factory()->create();
        [, $subAccount] = $this->createSubAccountForFiscalYear($fiscalYear);

        $transaction = (new TransactionRegistrar)->register($fiscalYear, [
            'date' => now()->toDateString(),
            'description' => 'net_amountで登録',
        ], [
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'debit',
                'net_amount' => 1000,
            ],
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'credit',
                'net_amount' => 1000,
            ],
        ]);

        $this->assertSame(1000, $transaction->journalEntries()->where('type', 'debit')->first()->net_amount);
        $this->assertDatabaseHas('journal_entries', [
            'transaction_id' => $transaction->id,
            'net_amount' => 1000,
        ]);
    }

    #[Test]
    public function counterparty_nameを指定すると取引先が自動作成される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '取引先自動作成事業体']);
        $fiscalYear = $unit->createFiscalYear(2025);
        [, $subAccount] = $this->createSubAccountForFiscalYear($fiscalYear);

        $transaction = (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-06-15',
            'description' => '取引先自動作成',
            'counterparty_name' => '  ABC商店  ',
            'counterparty_registration_number' => ' 1234567890123 ',
        ], [
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'debit',
                'net_amount' => 1000,
            ],
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'credit',
                'net_amount' => 1000,
            ],
        ]);

        $this->assertSame('ABC商店', $transaction->counterparty->name);
        $this->assertSame('T1234567890123', $transaction->counterparty->registration_number);
        $this->assertSame($unit->id, $transaction->counterparty->business_unit_id);
    }

    #[Test]
    public function counterparty_registration_numberに_t付きの値を指定するとそのまま保存される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '取引先自動作成事業体']);
        $fiscalYear = $unit->createFiscalYear(2025);
        [, $subAccount] = $this->createSubAccountForFiscalYear($fiscalYear);

        $transaction = (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-06-15',
            'description' => 'T付き登録番号',
            'counterparty_name' => 'ABC商店',
            'counterparty_registration_number' => ' T1234567890123 ',
        ], [
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'debit',
                'net_amount' => 1000,
            ],
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'credit',
                'net_amount' => 1000,
            ],
        ]);

        $this->assertSame('ABC商店', $transaction->counterparty->name);
        $this->assertSame('T1234567890123', $transaction->counterparty->registration_number);
    }

    #[Test]
    public function counterparty_nameだけを指定すると取引先が自動作成され登録番号はnullのままになる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '取引先自動作成事業体']);
        $fiscalYear = $unit->createFiscalYear(2025);
        [, $subAccount] = $this->createSubAccountForFiscalYear($fiscalYear);

        $transaction = (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-06-15',
            'description' => '取引先名のみ',
            'counterparty_name' => 'XYZストア',
        ], [
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'debit',
                'net_amount' => 1000,
            ],
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'credit',
                'net_amount' => 1000,
            ],
        ]);

        $this->assertSame('XYZストア', $transaction->counterparty->name);
        $this->assertNull($transaction->counterparty->registration_number);
        $this->assertSame($unit->id, $transaction->counterparty->business_unit_id);
    }

    #[Test]
    public function 同じcounterparty_nameを指定すると既存の取引先が再利用される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '取引先再利用事業体']);
        $fiscalYear = $unit->createFiscalYear(2025);
        [, $subAccount] = $this->createSubAccountForFiscalYear($fiscalYear);

        $first = (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-06-15',
            'description' => '1回目',
            'counterparty_name' => 'ABC商店',
        ], [
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'debit',
                'net_amount' => 1000,
            ],
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'credit',
                'net_amount' => 1000,
            ],
        ]);

        $second = (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-06-15',
            'description' => '2回目',
            'counterparty_name' => 'ABC商店',
        ], [
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'debit',
                'net_amount' => 1000,
            ],
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'credit',
                'net_amount' => 1000,
            ],
        ]);

        $this->assertSame($first->counterparty_id, $second->counterparty_id);
        $this->assertDatabaseCount('counterparties', 1);
    }

    #[Test]
    public function counterparty_registration_numberの形式が不正だと登録できない()
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('適格請求書発行事業者登録番号の形式が正しくありません。');

        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '取引先エラー事業体']);
        $fiscalYear = $unit->createFiscalYear(2025);
        [, $subAccount] = $this->createSubAccountForFiscalYear($fiscalYear);

        (new TransactionRegistrar)->register($fiscalYear, [
            'date' => now()->toDateString(),
            'description' => '登録番号フォーマットエラー',
            'counterparty_name' => 'ABC商店',
            'counterparty_registration_number' => 'ABC123',
        ], [
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'debit',
                'net_amount' => 1000,
            ],
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'credit',
                'net_amount' => 1000,
            ],
        ]);
    }

    #[Test]
    public function 存在しないcounterparty_idは登録できない()
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('選択中の事業体に属する取引先を指定してください。');

        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '取引先エラー事業体']);
        $fiscalYear = $unit->createFiscalYear(2025);
        [, $subAccount] = $this->createSubAccountForFiscalYear($fiscalYear);

        (new TransactionRegistrar)->register($fiscalYear, [
            'date' => now()->toDateString(),
            'description' => '存在しない取引先ID',
            'counterparty_id' => 999999,
        ], [
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'debit',
                'net_amount' => 1000,
            ],
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'credit',
                'net_amount' => 1000,
            ],
        ]);
    }

    #[Test]
    public function 他事業体のcounterparty_idは登録できない()
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('選択中の事業体に属する取引先を指定してください。');

        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '自分の事業体']);
        $otherUnit = $otherUser->createBusinessUnitWithDefaults(['name' => '他人の事業体']);
        $fiscalYear = $unit->createFiscalYear(2025);
        [, $subAccount] = $this->createSubAccountForFiscalYear($fiscalYear);

        $counterparty = Counterparty::factory()->create([
            'business_unit_id' => $otherUnit->id,
            'name' => '外部の取引先',
        ]);

        (new TransactionRegistrar)->register($fiscalYear, [
            'date' => now()->toDateString(),
            'description' => '他事業体の取引先ID',
            'counterparty_id' => $counterparty->id,
        ], [
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'debit',
                'net_amount' => 1000,
            ],
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'credit',
                'net_amount' => 1000,
            ],
        ]);
    }

    #[Test]
    public function counterparty_nameが空白だけなら取引先は未設定になる()
    {
        $fiscalYear = FiscalYear::factory()->create();
        [, $subAccount] = $this->createSubAccountForFiscalYear($fiscalYear);

        $transaction = (new TransactionRegistrar)->register($fiscalYear, [
            'date' => now()->toDateString(),
            'description' => '取引先未設定',
            'counterparty_name' => '   ',
            'counterparty_registration_number' => '   ',
        ], [
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'debit',
                'net_amount' => 1000,
            ],
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'credit',
                'net_amount' => 1000,
            ],
        ]);

        $this->assertNull($transaction->counterparty_id);
    }

    #[Test]
    public function counterparty_idを指定して既存取引先を紐づけられる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '取引先紐づけ事業体']);
        $fiscalYear = $unit->createFiscalYear(2025);
        [, $subAccount] = $this->createSubAccountForFiscalYear($fiscalYear);

        $counterparty = Counterparty::factory()->create([
            'business_unit_id' => $unit->id,
            'name' => 'XYZストア',
            'registration_number' => 'T1234567890123',
        ]);

        $transaction = (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-06-15',
            'description' => '既存取引先を紐づけ',
            'counterparty_id' => $counterparty->id,
        ], [
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'debit',
                'net_amount' => 1000,
            ],
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'credit',
                'net_amount' => 1000,
            ],
        ]);

        $this->assertTrue($transaction->counterparty->is($counterparty));
        $this->assertSame('XYZストア', $transaction->counterparty->name);
        $this->assertSame('T1234567890123', $transaction->counterparty->registration_number);
    }

    #[Test]
    public function counterparty_idとcounterparty_nameを同時指定すると登録できない()
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('取引先IDと取引先名は同時に指定できません。');

        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '取引先矛盾事業体']);
        $fiscalYear = $unit->createFiscalYear(2025);
        [, $subAccount] = $this->createSubAccountForFiscalYear($fiscalYear);

        $counterparty = Counterparty::factory()->create([
            'business_unit_id' => $unit->id,
            'name' => '既存取引先',
        ]);

        (new TransactionRegistrar)->register($fiscalYear, [
            'date' => now()->toDateString(),
            'description' => '矛盾する入力',
            'counterparty_id' => $counterparty->id,
            'counterparty_name' => '新規取引先',
        ], [
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'debit',
                'net_amount' => 1000,
            ],
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'credit',
                'net_amount' => 1000,
            ],
        ]);
    }

    #[Test]
    public function fiscal_yearがnullだと登録できない()
    {
        $this->expectException(ValidationException::class);

        $fiscalYear = FiscalYear::factory()->create();
        [, $subAccount] = $this->createSubAccountForFiscalYear($fiscalYear);
        $registrar = new TransactionRegistrar;

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'テスト取引',
        ];

        $journalEntriesData = [
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'debit',
                'net_amount' => 1000,
            ],
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'credit',
                'net_amount' => 1000,
            ],
        ];
        $registrar->register(null, $transactionData, $journalEntriesData);
    }

    #[Test]
    public function 不正な仕訳データの場合は登録できない()
    {
        $fiscalYear = FiscalYear::factory()->create();
        $this->expectException(ValidationException::class);

        $registrar = new TransactionRegistrar;

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => '不正な仕訳テスト',
        ];

        [, $validSubAccount] = $this->createSubAccountForFiscalYear($fiscalYear);

        $journalEntriesData = [
            [
                'sub_account_id' => $validSubAccount->id,
                'type' => 'debit',
                'net_amount' => 1000,
            ],
            [
                'sub_account_id' => 99999,
                'type' => 'credit',
                'net_amount' => 1000,
            ],
        ];

        $registrar->register($fiscalYear, $transactionData, $journalEntriesData);
    }

    #[Test]
    public function journal_entryにaccount_idを渡すと登録できない()
    {
        $fiscalYear = FiscalYear::factory()->create();
        [$debitSubAccount, $creditSubAccount] = $this->createTwoSubAccountsForFiscalYear($fiscalYear);

        $this->expectException(ValidationException::class);

        (new TransactionRegistrar)->register($fiscalYear, [
            'date' => now()->toDateString(),
            'description' => 'account_id混入テスト',
        ], [
            [
                'account_id' => $debitSubAccount->account_id,
                'sub_account_id' => $debitSubAccount->id,
                'type' => 'debit',
                'net_amount' => 1000,
            ],
            [
                'sub_account_id' => $creditSubAccount->id,
                'type' => 'credit',
                'net_amount' => 1000,
            ],
        ]);
    }

    #[Test]
    public function 仕訳の金額バランスが崩れている場合は登録できない()
    {
        $fiscalYear = FiscalYear::factory()->create();
        [, $subAccount] = $this->createSubAccountForFiscalYear($fiscalYear);

        $registrar = new TransactionRegistrar;

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'バランス崩れ',
        ];

        $journalEntriesData = [
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'debit',
                'net_amount' => 1000,
            ],
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'credit',
                'net_amount' => 800, // バランスが崩れている
            ],
        ];

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('仕訳の金額がバランスしていません（借方: 1000 / 貸方: 800 / 差額: +200）');

        $registrar->register($fiscalYear, $transactionData, $journalEntriesData);
    }

    #[Test]
    public function journal_entryのsub_account_idがnullだと登録できない()
    {
        $this->expectException(ValidationException::class);

        $fiscalYear = FiscalYear::factory()->create();
        [, $subAccount] = $this->createSubAccountForFiscalYear($fiscalYear);

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'エラー検証',
        ];

        $journalEntriesData = [
            ['sub_account_id' => null, 'type' => 'debit', 'net_amount' => 1000],
            ['sub_account_id' => $subAccount->id, 'type' => 'credit', 'net_amount' => 1000],
        ];

        (new TransactionRegistrar)->register($fiscalYear, $transactionData, $journalEntriesData);
    }

    #[Test]
    public function 仕訳が1件しかないと登録できない()
    {
        $this->expectException(\DomainException::class);

        $fiscalYear = FiscalYear::factory()->create();
        [, $subAccount] = $this->createSubAccountForFiscalYear($fiscalYear);

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => '仕訳1件のみ',
        ];

        $journalEntriesData = [
            ['sub_account_id' => $subAccount->id, 'type' => 'debit', 'net_amount' => 1000],
        ];

        (new TransactionRegistrar)->register($fiscalYear, $transactionData, $journalEntriesData);
    }

    #[Test]
    public function 借方と貸方の金額が一致しないと登録できない()
    {
        $this->expectException(\DomainException::class);

        $fiscalYear = FiscalYear::factory()->create();
        [, $subAccount] = $this->createSubAccountForFiscalYear($fiscalYear);

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'バランス不一致',
        ];

        $journalEntriesData = [
            ['sub_account_id' => $subAccount->id, 'type' => 'debit', 'net_amount' => 1000],
            ['sub_account_id' => $subAccount->id, 'type' => 'credit', 'net_amount' => 900],
        ];

        (new TransactionRegistrar)->register($fiscalYear, $transactionData, $journalEntriesData);
    }

    #[Test]
    public function 日付が不正な形式だと登録できない()
    {
        $this->expectException(ValidationException::class);

        $fiscalYear = FiscalYear::factory()->create();
        [, $subAccount] = $this->createSubAccountForFiscalYear($fiscalYear);

        $transactionData = [
            'date' => 'invalid-date',
            'description' => '不正日付',
        ];

        $journalEntriesData = [
            ['sub_account_id' => $subAccount->id, 'type' => 'debit', 'net_amount' => 500],
            ['sub_account_id' => $subAccount->id, 'type' => 'credit', 'net_amount' => 500],
        ];

        (new TransactionRegistrar)->register($fiscalYear, $transactionData, $journalEntriesData);
    }

    #[Test]
    public function 金額が0円だと登録できない()
    {
        $this->expectException(ValidationException::class);

        $fiscalYear = FiscalYear::factory()->create();
        [, $subAccount] = $this->createSubAccountForFiscalYear($fiscalYear);

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => '金額ゼロ',
        ];

        $journalEntriesData = [
            ['sub_account_id' => $subAccount->id, 'type' => 'debit', 'net_amount' => 0],
            ['sub_account_id' => $subAccount->id, 'type' => 'credit', 'net_amount' => 0],
        ];

        (new TransactionRegistrar)->register($fiscalYear, $transactionData, $journalEntriesData);
    }

    #[Test]
    public function typeが不正な値だと登録できない()
    {
        $this->expectException(ValidationException::class);

        $fiscalYear = FiscalYear::factory()->create();
        [, $subAccount] = $this->createSubAccountForFiscalYear($fiscalYear);

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => '不正type',
        ];

        $journalEntriesData = [
            ['sub_account_id' => $subAccount->id, 'type' => 'invalid', 'net_amount' => 1000],
            ['sub_account_id' => $subAccount->id, 'type' => 'credit', 'net_amount' => 1000],
        ];

        (new TransactionRegistrar)->register($fiscalYear, $transactionData, $journalEntriesData);
    }

    #[Test]
    public function journal_entriesが空配列だと登録できない()
    {
        $this->expectException(\InvalidArgumentException::class);

        $fiscalYear = FiscalYear::factory()->create();

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => '仕訳なし',
        ];

        $journalEntriesData = [];

        (new TransactionRegistrar)->register($fiscalYear, $transactionData, $journalEntriesData);
    }

    #[Test]
    public function journal_entriesがすべてdebitだと登録できない()
    {
        $this->expectException(\DomainException::class);

        $fiscalYear = FiscalYear::factory()->create();
        [, $subAccount] = $this->createSubAccountForFiscalYear($fiscalYear);

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => '借方のみ',
        ];

        $journalEntriesData = [
            ['sub_account_id' => $subAccount->id, 'type' => 'debit', 'net_amount' => 1000],
            ['sub_account_id' => $subAccount->id, 'type' => 'debit', 'net_amount' => 1000],
        ];

        (new TransactionRegistrar)->register($fiscalYear, $transactionData, $journalEntriesData);
    }

    #[Test]
    public function tax_amount込みでもバランスが正しければ登録できる()
    {
        $fiscalYear = FiscalYear::factory()->create();
        [$debitSubAccount, $creditSubAccount] = $this->createTwoSubAccountsForFiscalYear($fiscalYear);

        $registrar = new TransactionRegistrar;

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'tax_amount込みバランステスト',
        ];

        $journalEntriesData = [
            [
                'sub_account_id' => $debitSubAccount->id,
                'type' => 'debit',
                'net_amount' => 5000,
                'tax_amount' => 500,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            ],
            [
                'sub_account_id' => $creditSubAccount->id,
                'type' => 'credit',
                'net_amount' => 5500,
                'tax_amount' => 0,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
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
        [$debitSubAccount, $creditSubAccount] = $this->createTwoSubAccountsForFiscalYear($fiscalYear);

        $registrar = new TransactionRegistrar;

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'tax_amount不整合によるバランスエラー',
        ];

        $journalEntriesData = [
            [
                'sub_account_id' => $debitSubAccount->id,
                'type' => 'debit',
                'net_amount' => 5000,
                'tax_amount' => 500,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            ],
            [
                'sub_account_id' => $creditSubAccount->id,
                'type' => 'credit',
                'net_amount' => 5400,
                'tax_amount' => 0,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
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
        [$debitSubAccount, $creditSubAccount] = $this->createTwoSubAccountsForFiscalYear($fiscalYear);

        $registrar = new TransactionRegistrar;

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'マイナス税額',
        ];

        $journalEntriesData = [
            [
                'sub_account_id' => $debitSubAccount->id,
                'type' => 'debit',
                'net_amount' => 5000,
                'tax_amount' => -100,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            ],
            [
                'sub_account_id' => $creditSubAccount->id,
                'type' => 'credit',
                'net_amount' => 4900,
                'tax_amount' => 0,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
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
        [$debitSubAccount, $creditSubAccount] = $this->createTwoSubAccountsForFiscalYear($fiscalYear);

        $registrar = new TransactionRegistrar;

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'tax_amount null 許容',
        ];

        $journalEntriesData = [
            [
                'sub_account_id' => $debitSubAccount->id,
                'type' => 'debit',
                'net_amount' => 3000,
                'tax_amount' => null,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $creditSubAccount->id,
                'type' => 'credit',
                'net_amount' => 3000,
                'tax_amount' => null,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
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
        [$debitSubAccount, $creditSubAccount] = $this->createTwoSubAccountsForFiscalYear($fiscalYear);

        $registrar = new TransactionRegistrar;

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'tax_amount null 許容',
        ];

        $journalEntriesData = [
            [
                'sub_account_id' => $debitSubAccount->id,
                'type' => 'debit',
                'net_amount' => 3000,
                'tax_amount' => 0,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $creditSubAccount->id,
                'type' => 'credit',
                'net_amount' => 3000,
                'tax_amount' => 0,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
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
        [$debitSubAccount, $creditSubAccount] = $this->createTwoSubAccountsForFiscalYear($fiscalYear);

        $registrar = new TransactionRegistrar;

        $transactionData = [
            'date' => now()->toDateString(),
            'description' => 'tax_amount片側のみ',
        ];

        $journalEntriesData = [
            [
                'sub_account_id' => $debitSubAccount->id,
                'type' => 'debit',
                'net_amount' => 4500,
                'tax_amount' => 500,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            ],
            [
                'sub_account_id' => $creditSubAccount->id,
                'type' => 'credit',
                'net_amount' => 5000,
            ],
        ];

        $transaction = $registrar->register($fiscalYear, $transactionData, $journalEntriesData);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'description' => 'tax_amount片側のみ',
        ]);
    }

    #[Test]
    public function tax_typeがありtax_amountキーが未指定なら0で補完される()
    {
        $fiscalYear = FiscalYear::factory()->create();
        [$debitSubAccount, $creditSubAccount] = $this->createTwoSubAccountsForFiscalYear($fiscalYear);

        $transaction = (new TransactionRegistrar)->register($fiscalYear, [
            'date' => now()->toDateString(),
            'description' => 'tax_amount自動補完',
        ], [
            [
                'sub_account_id' => $debitSubAccount->id,
                'type' => 'debit',
                'net_amount' => 3000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $creditSubAccount->id,
                'type' => 'credit',
                'net_amount' => 3000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ]);

        $this->assertSame(0, $transaction->journalEntries()->where('type', 'debit')->first()->tax_amount);
        $this->assertSame(0, $transaction->journalEntries()->where('type', 'credit')->first()->tax_amount);
    }

    #[Test]
    public function 明示したtax_amountはuser_inputとして保存される()
    {
        $fiscalYear = FiscalYear::factory()->create();
        [$debitSubAccount, $creditSubAccount] = $this->createTwoSubAccountsForFiscalYear($fiscalYear);

        $transaction = (new TransactionRegistrar)->register($fiscalYear, [
            'date' => now()->toDateString(),
            'description' => 'tax_amount_source 明示入力',
        ], [
            [
                'sub_account_id' => $debitSubAccount->id,
                'type' => 'debit',
                'net_amount' => 3000,
                'tax_amount' => 0,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $creditSubAccount->id,
                'type' => 'credit',
                'net_amount' => 3000,
                'tax_amount' => 0,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ]);

        $this->assertSame(
            JournalEntry::TAX_AMOUNT_SOURCE_USER_INPUT,
            $transaction->journalEntries()->where('type', 'debit')->firstOrFail()->tax_amount_source
        );
        $this->assertSame(
            JournalEntry::TAX_AMOUNT_SOURCE_USER_INPUT,
            $transaction->journalEntries()->where('type', 'credit')->firstOrFail()->tax_amount_source
        );
    }

    #[Test]
    public function 免税事業者はgross_amount入力を見なし10パーセントで分解して保存できる()
    {
        $fiscalYear = FiscalYear::factory()->create([
            'is_taxable' => false,
            'is_tax_exclusive' => false,
        ]);
        [$debitSubAccount, $creditSubAccount] = $this->createTwoSubAccountsForFiscalYear($fiscalYear);

        $transaction = (new TransactionRegistrar)->register($fiscalYear, [
            'date' => now()->toDateString(),
            'description' => '免税事業者の税込入力',
        ], [
            [
                'sub_account_id' => $debitSubAccount->id,
                'type' => 'debit',
                'gross_amount' => 1100,
            ],
            [
                'sub_account_id' => $creditSubAccount->id,
                'type' => 'credit',
                'gross_amount' => 1100,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ]);

        $debit = $transaction->journalEntries()->where('type', 'debit')->firstOrFail();
        $credit = $transaction->journalEntries()->where('type', 'credit')->firstOrFail();

        $this->assertSame(1000, $debit->net_amount);
        $this->assertSame(100, $debit->tax_amount);
        $this->assertSame(1100, $debit->gross_amount);
        $this->assertSame(JournalEntry::TAX_TYPE_DEEMED_TAXABLE_PURCHASES_10, $debit->tax_type);
        $this->assertSame(JournalEntry::TAX_AMOUNT_SOURCE_COMPUTED_FROM_GROSS, $debit->tax_amount_source);

        $this->assertSame(1100, $credit->net_amount);
        $this->assertSame(0, $credit->tax_amount);
        $this->assertSame(1100, $credit->gross_amount);
        $this->assertSame(JournalEntry::TAX_TYPE_OUT_OF_SCOPE, $credit->tax_type);
        $this->assertSame(JournalEntry::TAX_AMOUNT_SOURCE_COMPUTED_FROM_GROSS, $credit->tax_amount_source);
    }

    #[Test]
    public function 免税事業者は非課税取引のgross_amount入力を税額ゼロで保存できる()
    {
        $fiscalYear = FiscalYear::factory()->create([
            'is_taxable' => false,
            'is_tax_exclusive' => false,
        ]);
        [$debitSubAccount, $creditSubAccount] = $this->createTwoSubAccountsForFiscalYear($fiscalYear);

        $transaction = (new TransactionRegistrar)->register($fiscalYear, [
            'date' => now()->toDateString(),
            'description' => '免税事業者の非課税取引',
        ], [
            [
                'sub_account_id' => $debitSubAccount->id,
                'type' => 'debit',
                'gross_amount' => 1000,
                'tax_type' => JournalEntry::TAX_TYPE_EXEMPT,
            ],
            [
                'sub_account_id' => $creditSubAccount->id,
                'type' => 'credit',
                'gross_amount' => 1000,
                'tax_type' => JournalEntry::TAX_TYPE_EXEMPT,
            ],
        ]);

        $this->assertTrue($transaction->journalEntries->every(function ($entry) {
            return $entry->net_amount === 1000
                && $entry->tax_amount === 0
                && $entry->gross_amount === 1000
                && $entry->tax_type === JournalEntry::TAX_TYPE_EXEMPT;
        }));
    }

    #[Test]
    public function gross_amount入力に事業割合を指定すると事業分と家事分に分割される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '家事按分テスト事業体']);
        $fiscalYear = $unit->createFiscalYear(2025)->refresh();
        $expenseSubAccount = $unit->getSubAccountByName('通信費', '通信費');
        $creditSubAccount = $unit->getSubAccountByName('現金', '現金');
        $householdSubAccount = $unit->getSubAccountByName('事業主貸', '家事按分');

        $transaction = (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-06-01',
            'description' => '家事按分テスト',
        ], [
            [
                'sub_account_id' => $expenseSubAccount->id,
                'type' => 'debit',
                'gross_amount' => 10000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                'business_ratio' => 60,
            ],
            [
                'sub_account_id' => $creditSubAccount->id,
                'type' => 'credit',
                'gross_amount' => 10000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ]);

        $this->assertCount(3, $transaction->journalEntries);
        $this->assertSame(60, $transaction->business_ratio);

        $businessEntry = $transaction->journalEntries->firstWhere('business_ratio', 60);
        $householdSubAccount = $unit->getSubAccountByName('事業主貸', '家事按分');
        $householdEntry = $transaction->journalEntries->firstWhere('sub_account_id', $householdSubAccount->id);

        $this->assertNotNull($businessEntry);
        $this->assertNotNull($householdSubAccount);
        $this->assertNotNull($householdEntry);
        $this->assertSame(6000, $businessEntry->net_amount);
        $this->assertSame(4000, $householdEntry->net_amount);
        $this->assertSame($businessEntry->allocation_group_id, $householdEntry->allocation_group_id);
        $this->assertSame($householdSubAccount->id, $householdEntry->sub_account_id);
        $this->assertNull($householdEntry->business_ratio);
    }

    #[Test]
    public function gross_amount入力で事業割合を未指定なら100として保存される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '事業割合未指定テスト事業体']);
        $fiscalYear = $unit->createFiscalYear(2025)->refresh();
        $expenseSubAccount = $unit->getSubAccountByName('通信費', '通信費');
        $creditSubAccount = $unit->getSubAccountByName('現金', '現金');

        $transaction = (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-06-01',
            'description' => '事業割合未指定テスト',
        ], [
            [
                'sub_account_id' => $expenseSubAccount->id,
                'type' => 'debit',
                'gross_amount' => 10000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $creditSubAccount->id,
                'type' => 'credit',
                'gross_amount' => 10000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ]);

        $this->assertCount(2, $transaction->journalEntries);
        $this->assertSame(100, $transaction->business_ratio);

        $debitEntry = $transaction->journalEntries->firstWhere('type', 'debit');

        $this->assertSame(10000, $debitEntry->net_amount);
        $this->assertSame(10000, $debitEntry->gross_amount);
        $this->assertSame(100, $debitEntry->business_ratio);
        $this->assertNull($debitEntry->allocation_group_id);
    }

    #[Test]
    public function gross_amount入力で事業割合が100なら分割されない()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '事業割合100テスト事業体']);
        $fiscalYear = $unit->createFiscalYear(2025)->refresh();
        $expenseSubAccount = $unit->getSubAccountByName('通信費', '通信費');
        $creditSubAccount = $unit->getSubAccountByName('現金', '現金');

        $transaction = (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-06-01',
            'description' => '事業割合100テスト',
        ], [
            [
                'sub_account_id' => $expenseSubAccount->id,
                'type' => 'debit',
                'gross_amount' => 10000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                'business_ratio' => 100,
            ],
            [
                'sub_account_id' => $creditSubAccount->id,
                'type' => 'credit',
                'gross_amount' => 10000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ]);

        $this->assertCount(2, $transaction->journalEntries);

        $debitEntry = $transaction->journalEntries->firstWhere('type', 'debit');

        $this->assertSame(10000, $debitEntry->net_amount);
        $this->assertSame(100, $debitEntry->business_ratio);
        $this->assertNull($debitEntry->allocation_group_id);
    }

    #[Test]
    public function gross_amount入力で借方費用科目以外に事業割合を指定すると登録できない()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '事業割合エラーテスト事業体']);
        $fiscalYear = $unit->createFiscalYear(2025)->refresh();
        $expenseSubAccount = $unit->getSubAccountByName('通信費', '通信費');
        $creditSubAccount = $unit->getSubAccountByName('現金', '現金');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('事業割合は借方の費用科目でのみ指定できます。');

        (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-06-01',
            'description' => '事業割合エラーテスト',
        ], [
            [
                'sub_account_id' => $creditSubAccount->id,
                'type' => 'credit',
                'gross_amount' => 10000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                'business_ratio' => 60,
            ],
            [
                'sub_account_id' => $expenseSubAccount->id,
                'type' => 'debit',
                'gross_amount' => 10000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ]);
    }

    #[Test]
    public function gross_amount入力で事業割合が範囲外なら登録できない()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '事業割合範囲外テスト事業体']);
        $fiscalYear = $unit->createFiscalYear(2025)->refresh();
        $expenseSubAccount = $unit->getSubAccountByName('通信費', '通信費');
        $creditSubAccount = $unit->getSubAccountByName('現金', '現金');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('事業割合は1〜100の範囲で指定してください。');

        (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-06-01',
            'description' => '事業割合範囲外テスト',
        ], [
            [
                'sub_account_id' => $expenseSubAccount->id,
                'type' => 'debit',
                'gross_amount' => 10000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                'business_ratio' => 101,
            ],
            [
                'sub_account_id' => $creditSubAccount->id,
                'type' => 'credit',
                'gross_amount' => 10000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ]);
    }

    #[Test]
    public function 課税事業者はgross_amountとtax_type入力を正しく分解して保存できる()
    {
        $fiscalYear = FiscalYear::factory()->create([
            'is_taxable' => true,
            'is_tax_exclusive' => false,
        ]);
        [$debitSubAccount, $creditSubAccount] = $this->createTwoSubAccountsForFiscalYear($fiscalYear);

        $transaction = (new TransactionRegistrar)->register($fiscalYear, [
            'date' => now()->toDateString(),
            'description' => '課税事業者の税込入力',
        ], [
            [
                'sub_account_id' => $debitSubAccount->id,
                'type' => 'debit',
                'gross_amount' => 1100,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $creditSubAccount->id,
                'type' => 'credit',
                'gross_amount' => 1100,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_SALES_10,
            ],
        ]);

        $debit = $transaction->journalEntries()->where('type', 'debit')->firstOrFail();
        $credit = $transaction->journalEntries()->where('type', 'credit')->firstOrFail();

        $this->assertSame(1100, $debit->net_amount);
        $this->assertSame(0, $debit->tax_amount);
        $this->assertSame(1100, $debit->gross_amount);
        $this->assertSame(JournalEntry::TAX_TYPE_OUT_OF_SCOPE, $debit->tax_type);

        $this->assertSame(1000, $credit->net_amount);
        $this->assertSame(100, $credit->tax_amount);
        $this->assertSame(1100, $credit->gross_amount);
        $this->assertSame(JournalEntry::TAX_TYPE_TAXABLE_SALES_10, $credit->tax_type);
    }

    #[Test]
    public function 決算済み会計年度には新規取引を登録できない()
    {
        $fiscalYear = FiscalYear::factory()->create([
            'is_closed' => true,
        ]);
        [$debitSubAccount, $creditSubAccount] = $this->createTwoSubAccountsForFiscalYear($fiscalYear);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('決算済みの会計年度には新規取引を登録できません。');

        (new TransactionRegistrar)->register($fiscalYear, [
            'date' => now()->toDateString(),
            'description' => '決算済み年度テスト',
        ], [
            [
                'sub_account_id' => $debitSubAccount->id,
                'type' => 'debit',
                'net_amount' => 1000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $creditSubAccount->id,
                'type' => 'credit',
                'net_amount' => 1000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ]);
    }

    #[Test]
    public function 課税事業者は見なし消費税区分を指定すると登録できない()
    {
        $fiscalYear = FiscalYear::factory()->create([
            'is_taxable' => true,
            'is_tax_exclusive' => false,
        ]);
        [$debitSubAccount, $creditSubAccount] = $this->createTwoSubAccountsForFiscalYear($fiscalYear);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('課税事業者の会計年度では見なし消費税区分は使用できません。');

        (new TransactionRegistrar)->register($fiscalYear, [
            'date' => now()->toDateString(),
            'description' => '見なし税区分拒否',
        ], [
            [
                'sub_account_id' => $debitSubAccount->id,
                'type' => 'debit',
                'gross_amount' => 1100,
                'tax_type' => JournalEntry::TAX_TYPE_DEEMED_TAXABLE_PURCHASES_10,
            ],
            [
                'sub_account_id' => $creditSubAccount->id,
                'type' => 'credit',
                'gross_amount' => 1100,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ]);
    }

    #[Test]
    public function 課税事業者はexemptのgross_amount入力を税額ゼロで保存できる()
    {
        $fiscalYear = FiscalYear::factory()->create([
            'is_taxable' => true,
            'is_tax_exclusive' => false,
        ]);
        [$debitSubAccount, $creditSubAccount] = $this->createTwoSubAccountsForFiscalYear($fiscalYear);

        $transaction = (new TransactionRegistrar)->register($fiscalYear, [
            'date' => now()->toDateString(),
            'description' => '課税事業者の非課税取引',
        ], [
            [
                'sub_account_id' => $debitSubAccount->id,
                'type' => 'debit',
                'gross_amount' => 1000,
                'tax_type' => JournalEntry::TAX_TYPE_EXEMPT,
            ],
            [
                'sub_account_id' => $creditSubAccount->id,
                'type' => 'credit',
                'gross_amount' => 1000,
                'tax_type' => JournalEntry::TAX_TYPE_EXEMPT,
            ],
        ]);

        $this->assertTrue($transaction->journalEntries->every(function ($entry) {
            return $entry->net_amount === 1000
                && $entry->tax_amount === 0
                && $entry->gross_amount === 1000
                && $entry->tax_type === JournalEntry::TAX_TYPE_EXEMPT;
        }));
    }

    #[Test]
    public function 課税事業者はzero_ratedのgross_amount入力を税額ゼロで保存できる()
    {
        $fiscalYear = FiscalYear::factory()->create([
            'is_taxable' => true,
            'is_tax_exclusive' => false,
        ]);
        [$debitSubAccount, $creditSubAccount] = $this->createTwoSubAccountsForFiscalYear($fiscalYear);

        $transaction = (new TransactionRegistrar)->register($fiscalYear, [
            'date' => now()->toDateString(),
            'description' => '課税事業者の免税売上',
        ], [
            [
                'sub_account_id' => $debitSubAccount->id,
                'type' => 'debit',
                'gross_amount' => 1000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $creditSubAccount->id,
                'type' => 'credit',
                'gross_amount' => 1000,
                'tax_type' => JournalEntry::TAX_TYPE_ZERO_RATED,
            ],
        ]);

        $credit = $transaction->journalEntries()->where('type', 'credit')->firstOrFail();

        $this->assertSame(1000, $credit->net_amount);
        $this->assertSame(0, $credit->tax_amount);
        $this->assertSame(1000, $credit->gross_amount);
        $this->assertSame(JournalEntry::TAX_TYPE_ZERO_RATED, $credit->tax_type);
    }

    #[Test]
    public function 免税事業者が税込2200円の売上をレジ現金に保存できる()
    {
        $fiscalYear = $this->createBusinessUnitFiscalYear();
        $fiscalYear->update([
            'is_taxable' => false,
            'is_tax_exclusive' => false,
        ]);

        $unit = $fiscalYear->businessUnit;
        $cashSubAccount = $unit->getSubAccountByName('現金', '現金');
        $salesSubAccount = $unit->getSubAccountByName('売上高', '売上高');

        $transaction = (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-04-01',
            'description' => '免税事業者の現金売上',
        ], [
            [
                'sub_account_id' => $cashSubAccount->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'gross_amount' => 2200,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $salesSubAccount->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'gross_amount' => 2200,
            ],
        ]);

        $cashEntry = $transaction->journalEntries()->where('sub_account_id', $cashSubAccount->id)->firstOrFail();
        $salesEntry = $transaction->journalEntries()->where('sub_account_id', $salesSubAccount->id)->firstOrFail();

        $this->assertSame(2200, $cashEntry->net_amount);
        $this->assertSame(0, $cashEntry->tax_amount);
        $this->assertSame(JournalEntry::TAX_TYPE_OUT_OF_SCOPE, $cashEntry->tax_type);

        $this->assertSame(2000, $salesEntry->net_amount);
        $this->assertSame(200, $salesEntry->tax_amount);
        $this->assertSame(JournalEntry::TAX_TYPE_DEEMED_TAXABLE_SALES_10, $salesEntry->tax_type);
    }

    #[Test]
    public function 免税事業者が税込1100円の通信費をレジ現金で保存できる()
    {
        $fiscalYear = $this->createBusinessUnitFiscalYear();
        $fiscalYear->update([
            'is_taxable' => false,
            'is_tax_exclusive' => false,
        ]);

        $unit = $fiscalYear->businessUnit;
        $expenseSubAccount = $unit->getSubAccountByName('通信費', '通信費');
        $cashSubAccount = $unit->getSubAccountByName('現金', '現金');

        $transaction = (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-04-02',
            'description' => '免税事業者の通信費',
        ], [
            [
                'sub_account_id' => $expenseSubAccount->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'gross_amount' => 1100,
            ],
            [
                'sub_account_id' => $cashSubAccount->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'gross_amount' => 1100,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ]);

        $expenseEntry = $transaction->journalEntries()->where('sub_account_id', $expenseSubAccount->id)->firstOrFail();
        $cashEntry = $transaction->journalEntries()->where('sub_account_id', $cashSubAccount->id)->firstOrFail();

        $this->assertSame(1000, $expenseEntry->net_amount);
        $this->assertSame(100, $expenseEntry->tax_amount);
        $this->assertSame(JournalEntry::TAX_TYPE_DEEMED_TAXABLE_PURCHASES_10, $expenseEntry->tax_type);

        $this->assertSame(1100, $cashEntry->net_amount);
        $this->assertSame(0, $cashEntry->tax_amount);
        $this->assertSame(JournalEntry::TAX_TYPE_OUT_OF_SCOPE, $cashEntry->tax_type);
    }

    #[Test]
    public function 課税事業者が税込2200円の売上をレジ現金に保存できる()
    {
        $fiscalYear = $this->createBusinessUnitFiscalYear();
        $fiscalYear->update([
            'is_taxable' => true,
            'is_tax_exclusive' => false,
        ]);

        $unit = $fiscalYear->businessUnit;
        $cashSubAccount = $unit->getSubAccountByName('現金', '現金');
        $salesSubAccount = $unit->getSubAccountByName('売上高', '売上高');

        $transaction = (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-04-03',
            'description' => '課税事業者のレジ売上',
        ], [
            [
                'sub_account_id' => $cashSubAccount->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'gross_amount' => 2200,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $salesSubAccount->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'gross_amount' => 2200,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_SALES_10,
            ],
        ]);

        $salesEntry = $transaction->journalEntries()->where('sub_account_id', $salesSubAccount->id)->firstOrFail();

        $this->assertSame(2000, $salesEntry->net_amount);
        $this->assertSame(200, $salesEntry->tax_amount);
        $this->assertSame(JournalEntry::TAX_TYPE_TAXABLE_SALES_10, $salesEntry->tax_type);
    }

    #[Test]
    public function 課税事業者が税込1100円の通信費をレジ現金で保存できる()
    {
        $fiscalYear = $this->createBusinessUnitFiscalYear();
        $fiscalYear->update([
            'is_taxable' => true,
            'is_tax_exclusive' => false,
        ]);

        $unit = $fiscalYear->businessUnit;
        $expenseSubAccount = $unit->getSubAccountByName('通信費', '通信費');
        $cashSubAccount = $unit->getSubAccountByName('現金', '現金');

        $transaction = (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-04-04',
            'description' => '課税事業者の通信費',
        ], [
            [
                'sub_account_id' => $expenseSubAccount->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'gross_amount' => 1100,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            ],
            [
                'sub_account_id' => $cashSubAccount->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'gross_amount' => 1100,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ]);

        $expenseEntry = $transaction->journalEntries()->where('sub_account_id', $expenseSubAccount->id)->firstOrFail();

        $this->assertSame(1000, $expenseEntry->net_amount);
        $this->assertSame(100, $expenseEntry->tax_amount);
        $this->assertSame(JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10, $expenseEntry->tax_type);
    }

    #[Test]
    public function 課税事業者が税込1080円の軽減税率経費をレジ現金で保存できる()
    {
        $fiscalYear = $this->createBusinessUnitFiscalYear();
        $fiscalYear->update([
            'is_taxable' => true,
            'is_tax_exclusive' => false,
        ]);

        $unit = $fiscalYear->businessUnit;
        $expenseSubAccount = $unit->getSubAccountByName('雑費', '雑費');
        $cashSubAccount = $unit->getSubAccountByName('現金', '現金');

        $transaction = (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-04-05',
            'description' => '課税事業者の軽減税率経費',
        ], [
            [
                'sub_account_id' => $expenseSubAccount->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'gross_amount' => 1080,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_8,
            ],
            [
                'sub_account_id' => $cashSubAccount->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'gross_amount' => 1080,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ]);

        $expenseEntry = $transaction->journalEntries()->where('sub_account_id', $expenseSubAccount->id)->firstOrFail();

        $this->assertSame(1000, $expenseEntry->net_amount);
        $this->assertSame(80, $expenseEntry->tax_amount);
        $this->assertSame(JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_8, $expenseEntry->tax_type);
    }

    #[Test]
    public function 課税事業者が軽減税率8パーセントと10パーセントが混在する仕入れを保存できる()
    {
        $fiscalYear = $this->createBusinessUnitFiscalYear();
        $fiscalYear->update([
            'is_taxable' => true,
            'is_tax_exclusive' => false,
        ]);

        $unit = $fiscalYear->businessUnit;
        $purchaseSubAccount = $unit->getSubAccountByName('仕入金額', '仕入金額');
        $cashSubAccount = $unit->getSubAccountByName('現金', '現金');

        $transaction = (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-04-06',
            'description' => 'スーパーで魚とビールを仕入れ',
        ], [
            [
                'sub_account_id' => $purchaseSubAccount->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'gross_amount' => 2160,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_8,
            ],
            [
                'sub_account_id' => $purchaseSubAccount->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'gross_amount' => 5500,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            ],
            [
                'sub_account_id' => $cashSubAccount->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'gross_amount' => 7660,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ]);

        $reducedRateEntry = $transaction->journalEntries()
            ->where('tax_type', JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_8)
            ->firstOrFail();
        $standardRateEntry = $transaction->journalEntries()
            ->where('tax_type', JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10)
            ->firstOrFail();
        $cashEntry = $transaction->journalEntries()
            ->where('sub_account_id', $cashSubAccount->id)
            ->firstOrFail();

        $this->assertSame(2000, $reducedRateEntry->net_amount);
        $this->assertSame(160, $reducedRateEntry->tax_amount);

        $this->assertSame(5000, $standardRateEntry->net_amount);
        $this->assertSame(500, $standardRateEntry->tax_amount);

        $this->assertSame(7660, $cashEntry->net_amount);
        $this->assertSame(0, $cashEntry->tax_amount);
        $this->assertSame(JournalEntry::TAX_TYPE_OUT_OF_SCOPE, $cashEntry->tax_type);
    }

    #[Test]
    public function 課税事業者が税込1000円の非課税経費をレジ現金で保存できる()
    {
        $fiscalYear = $this->createBusinessUnitFiscalYear();
        $fiscalYear->update([
            'is_taxable' => true,
            'is_tax_exclusive' => false,
        ]);

        $unit = $fiscalYear->businessUnit;
        $expenseSubAccount = $unit->getSubAccountByName('地代家賃', '地代家賃');
        $cashSubAccount = $unit->getSubAccountByName('現金', '現金');

        $transaction = (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-04-07',
            'description' => '課税事業者の非課税経費',
        ], [
            [
                'sub_account_id' => $expenseSubAccount->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'gross_amount' => 1000,
                'tax_type' => JournalEntry::TAX_TYPE_EXEMPT,
            ],
            [
                'sub_account_id' => $cashSubAccount->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'gross_amount' => 1000,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ]);

        $expenseEntry = $transaction->journalEntries()->where('sub_account_id', $expenseSubAccount->id)->firstOrFail();

        $this->assertSame(1000, $expenseEntry->net_amount);
        $this->assertSame(0, $expenseEntry->tax_amount);
        $this->assertSame(JournalEntry::TAX_TYPE_EXEMPT, $expenseEntry->tax_type);
    }

    #[Test]
    public function 課税仕入れ区分が貸方だとregisterで拒否される()
    {
        $fiscalYear = FiscalYear::factory()->create([
            'is_taxable' => true,
            'is_tax_exclusive' => false,
        ]);
        [$debitSubAccount, $creditSubAccount] = $this->createTwoSubAccountsForFiscalYear($fiscalYear);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('仕入・経費の消費税区分は借方でのみ使用できます。');

        (new TransactionRegistrar)->register($fiscalYear, [
            'date' => now()->toDateString(),
            'description' => '課税仕入れ区分の方向エラー',
        ], [
            [
                'sub_account_id' => $debitSubAccount->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'gross_amount' => 1100,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
            [
                'sub_account_id' => $creditSubAccount->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'gross_amount' => 1100,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            ],
        ]);
    }

    #[Test]
    public function 課税売上区分が借方だとregisterで拒否される()
    {
        $fiscalYear = FiscalYear::factory()->create([
            'is_taxable' => true,
            'is_tax_exclusive' => false,
        ]);
        [$debitSubAccount, $creditSubAccount] = $this->createTwoSubAccountsForFiscalYear($fiscalYear);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('売上の消費税区分は貸方でのみ使用できます。');

        (new TransactionRegistrar)->register($fiscalYear, [
            'date' => now()->toDateString(),
            'description' => '課税売上区分の方向エラー',
        ], [
            [
                'sub_account_id' => $debitSubAccount->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'gross_amount' => 1100,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_SALES_10,
            ],
            [
                'sub_account_id' => $creditSubAccount->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'gross_amount' => 1100,
                'tax_type' => JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ],
        ]);
    }

    #[Test]
    public function 他事業体の補助科目はregisterで拒否される()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '自分の事業体']);
        $otherUnit = $otherUser->createBusinessUnitWithDefaults(['name' => '他人の事業体']);
        $fiscalYear = $unit->createFiscalYear(2025);

        $ownExpense = $unit->subAccounts()->whereHas('account', fn ($q) => $q->where('name', '通信費'))->first();
        $foreignAsset = $otherUnit->subAccounts()->whereHas('account', fn ($q) => $q->where('name', '現金'))->first();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('選択中の事業体に属する補助科目を指定してください。');

        (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-04-01',
            'description' => '他事業体の補助科目テスト',
        ], [
            [
                'sub_account_id' => $ownExpense->id,
                'type' => 'debit',
                'net_amount' => 1000,
            ],
            [
                'sub_account_id' => $foreignAsset->id,
                'type' => 'credit',
                'net_amount' => 1000,
            ],
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

        $registrar = new TransactionRegistrar;

        $transaction = $registrar->register($fiscalYear, [
            'date' => '2025-06-15',
            'description' => '予定取引テスト',
            'is_planned' => true,
        ], [
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'debit',
                'net_amount' => 1000,
            ],
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'credit',
                'net_amount' => 1000,
            ],
        ]);

        $this->assertTrue($transaction->is_planned);
    }

    #[Test]
    public function 予定取引を本登録に変更できる()
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear = $unit->createFiscalYear(2025);

        $expense = $unit->subAccounts()->whereHas('account', fn ($q) => $q->where('name', '通信費'))->first();
        $asset = $unit->subAccounts()->whereHas('account', fn ($q) => $q->where('name', '現金'))->first();

        $registrar = new TransactionRegistrar;

        $transaction = $registrar->register($fiscalYear, [
            'date' => '2025-04-01',
            'description' => '予定取引',
            'is_planned' => true,
        ], [
            [
                'sub_account_id' => $expense->id,
                'type' => 'debit',
                'net_amount' => 1000,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
                'tax_amount' => 100,
            ],
            [
                'sub_account_id' => $asset->id,
                'type' => 'credit',
                'net_amount' => 1100,
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
        $this->actingAs($user);
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear = $unit->createFiscalYear(2025);

        $expense = $unit->subAccounts()->whereHas('account', fn ($q) => $q->where('name', '通信費'))->first();
        $asset = $unit->subAccounts()->whereHas('account', fn ($q) => $q->where('name', '現金'))->first();
        $liability = $unit->subAccounts()->whereHas('account', fn ($q) => $q->where('name', '未払金'))->first();

        $registrar = new TransactionRegistrar;

        $transaction = $registrar->register($fiscalYear, [
            'date' => '2025-04-01',
            'description' => '予定取引',
            'is_planned' => true,
        ], [
            [
                'sub_account_id' => $expense->id,
                'type' => 'debit',
                'net_amount' => 1000,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
                'tax_amount' => 100,
            ],
            [
                'sub_account_id' => $asset->id,
                'type' => 'credit',
                'net_amount' => 1100,
            ],
        ]);

        $transaction->description = '本登録済み（仕訳変更）';
        $transaction->date = '2025-04-02';

        $debit = $transaction->journalEntries->firstWhere('type', 'debit');
        $credit = $transaction->journalEntries->firstWhere('type', 'credit');

        $debit->net_amount = 2000;
        $debit->tax_amount = 200;

        $credit->net_amount = 2200;
        $credit->sub_account_id = $liability->id;

        $confirmed = $registrar->confirmPlanned($transaction);

        $this->assertFalse($confirmed->is_planned);
        $this->assertSame('本登録済み（仕訳変更）', $confirmed->description);
        $this->assertSame('2025-04-02', $confirmed->date->toDateString());
        $this->assertCount(2, $confirmed->journalEntries);

        $this->assertSame(2000, $confirmed->journalEntries->firstWhere('type', 'debit')->net_amount);
        $this->assertSame(2200, $confirmed->journalEntries->firstWhere('type', 'credit')->net_amount);
        $this->assertSame($liability->id, $confirmed->journalEntries->firstWhere('type', 'credit')->sub_account_id);
    }

    #[Test]
    public function 予定取引を0円の本登録に変換して取消できる()
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear = $unit->createFiscalYear(2025);

        $expense = $unit->subAccounts()->whereHas('account', fn ($q) => $q->where('name', '通信費'))->first();
        $asset = $unit->subAccounts()->whereHas('account', fn ($q) => $q->where('name', '現金'))->first();

        $registrar = new TransactionRegistrar;

        $transaction = $registrar->register($fiscalYear, [
            'date' => '2025-05-01',
            'description' => '取消予定取引',
            'is_planned' => true,
        ], [
            [
                'sub_account_id' => $expense->id,
                'type' => 'debit',
                'net_amount' => 1000,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
                'tax_amount' => 100,
            ],
            [
                'sub_account_id' => $asset->id,
                'type' => 'credit',
                'net_amount' => 1100,
            ],
        ]);

        foreach ($transaction->journalEntries as $entry) {
            $entry->net_amount = 0;
        }

        $transaction->description = '取消予定取引（取消）';

        $cancelled = $registrar->confirmPlanned($transaction);

        $this->assertFalse($cancelled->is_planned);
        $this->assertSame('取消予定取引（取消）', $cancelled->description);
        $this->assertCount(2, $cancelled->journalEntries);
        $this->assertTrue($cancelled->journalEntries->every(fn ($e) => $e->net_amount === 0));
    }

    #[Test]
    public function 予定取引をcancel_plannedで取消すると非アクティブ化される()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear = $unit->createFiscalYear(2025);

        $expense = $unit->subAccounts()->whereHas('account', fn ($q) => $q->where('name', '通信費'))->first();
        $asset = $unit->subAccounts()->whereHas('account', fn ($q) => $q->where('name', '現金'))->first();

        $registrar = new TransactionRegistrar;

        $transaction = $registrar->register($fiscalYear, [
            'date' => '2025-05-01',
            'description' => '取消予定取引',
            'is_planned' => true,
        ], [
            [
                'sub_account_id' => $expense->id,
                'type' => 'debit',
                'net_amount' => 1000,
                'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
                'tax_amount' => 100,
            ],
            [
                'sub_account_id' => $asset->id,
                'type' => 'credit',
                'net_amount' => 1100,
            ],
        ]);

        $cancelled = $registrar->cancelPlanned($transaction, $user);

        $this->assertFalse($cancelled->is_active);
        $this->assertNotNull($cancelled->deactivated_at);
        $this->assertSame($user->id, $cancelled->deactivated_by);
        $this->assertSame('予定取消', $cancelled->deactivation_reason);

        // 予測の取消なので確定仕訳にはせず、予定のまま残す（再生成ブロックのため）
        $this->assertTrue($cancelled->is_planned);
        $this->assertSame('取消予定取引', $cancelled->description);
        $this->assertSame(1000, $cancelled->journalEntries->firstWhere('type', 'debit')->net_amount);
    }

    #[Test]
    public function 取消済みの予定取引がある日付には定期取引が再生成されない()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear = $unit->createFiscalYear(2025);

        $expense = $unit->subAccounts()->whereHas('account', fn ($q) => $q->where('name', '通信費'))->first();
        $asset = $unit->subAccounts()->whereHas('account', fn ($q) => $q->where('name', '現金'))->first();

        $plan = $unit->createRecurringTransactionPlan([
            'name' => 'インターネット回線',
            'interval' => 'monthly',
            'day_of_month' => 10,
            'debit_sub_account_id' => $expense->id,
            'credit_sub_account_id' => $asset->id,
            'amount' => 5000,
            'tax_amount' => 500,
            'tax_type' => 'taxable_purchases_10',
            'is_income' => false,
        ]);

        $registrar = new TransactionRegistrar;

        $generated = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user);
        $this->assertCount(12, $generated);

        $registrar->cancelPlanned($generated->first(), $user);

        // 存在チェックは is_active を見ないため、取消済みでも再生成されない
        $regenerated = $unit->generatePlannedTransactionsForPlan($plan, $fiscalYear, $user);
        $this->assertCount(0, $regenerated);
    }

    #[Test]
    public function 本登録済みの取引は取消できない()
    {
        $this->expectException(\InvalidArgumentException::class);

        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業体']);
        $fiscalYear = $unit->createFiscalYear(2025);

        $expense = $unit->subAccounts()->whereHas('account', fn ($q) => $q->where('name', '通信費'))->first();
        $asset = $unit->subAccounts()->whereHas('account', fn ($q) => $q->where('name', '現金'))->first();

        $registrar = new TransactionRegistrar;

        $transaction = $registrar->register($fiscalYear, [
            'date' => '2025-05-01',
            'description' => '本登録取引',
            'is_planned' => false,
        ], [
            [
                'sub_account_id' => $expense->id,
                'type' => 'debit',
                'net_amount' => 1000,
            ],
            [
                'sub_account_id' => $asset->id,
                'type' => 'credit',
                'net_amount' => 1000,
            ],
        ]);

        $registrar->cancelPlanned($transaction, $user);
    }

    private function registerWithDate(FiscalYear $fiscalYear, string $date): Transaction
    {
        [, $subAccount] = $this->createSubAccountForFiscalYear($fiscalYear);

        return (new TransactionRegistrar)->register($fiscalYear, [
            'date' => $date,
            'description' => '年度境界テスト',
        ], [
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'debit',
                'net_amount' => 1000,
            ],
            [
                'sub_account_id' => $subAccount->id,
                'type' => 'credit',
                'net_amount' => 1000,
            ],
        ]);
    }

    #[Test]
    public function 会計年度の開始日当日の取引は登録できる()
    {
        $fiscalYear = $this->createBusinessUnitFiscalYear();

        $transaction = $this->registerWithDate($fiscalYear, '2025-01-01');

        $this->assertSame('2025-01-01', $transaction->date->toDateString());
    }

    #[Test]
    public function 会計年度の終了日当日の取引は登録できる()
    {
        $fiscalYear = $this->createBusinessUnitFiscalYear();

        $transaction = $this->registerWithDate($fiscalYear, '2025-12-31');

        $this->assertSame('2025-12-31', $transaction->date->toDateString());
    }

    #[Test]
    public function 会計年度の開始日より前の取引は登録できない()
    {
        $fiscalYear = $this->createBusinessUnitFiscalYear();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('取引日は会計年度の期間内（2025-01-01〜2025-12-31）で指定してください。');

        $this->registerWithDate($fiscalYear, '2024-12-31');
    }

    #[Test]
    public function 会計年度の終了日より後の取引は登録できない()
    {
        $fiscalYear = $this->createBusinessUnitFiscalYear();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('取引日は会計年度の期間内（2025-01-01〜2025-12-31）で指定してください。');

        $this->registerWithDate($fiscalYear, '2026-01-01');
    }

    #[Test]
    public function 他ユーザーは予定取引をcancel_plannedで取消できない(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '取消認可テスト']);
        $fiscalYear = $unit->createFiscalYear(2025);

        $debit = $unit->getAccountByName('通信費')->subAccounts()->firstOrFail();
        $credit = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();

        $registrar = new TransactionRegistrar;
        $transaction = $registrar->register($fiscalYear, [
            'date' => '2025-05-01',
            'description' => '取消認可テスト予定',
            'is_planned' => true,
        ], [
            ['sub_account_id' => $debit->id, 'type' => JournalEntry::TYPE_DEBIT, 'net_amount' => 1000],
            ['sub_account_id' => $credit->id, 'type' => JournalEntry::TYPE_CREDIT, 'net_amount' => 1000],
        ]);

        $this->expectException(AuthorizationException::class);

        $registrar->cancelPlanned($transaction, $otherUser);
    }
}
