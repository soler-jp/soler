<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TransactionRegistrar;
use App\Services\GeneralLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class GeneralLedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 単一の借方仕訳だけで残高が正しく集計される()
    {
        $user = User::factory()->create();

        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業',
        ]);

        $fiscalYear = $unit->createFiscalYear(2025);

        $debitSubAccount = $unit->getAccountByName('その他の預金')->subAccounts()->first();
        $creditSubAccount = $unit->getAccountByName('事業主借')->subAccounts()->first();

        $registrar = new TransactionRegistrar();

        $registrar->register(
            fiscalYear: $fiscalYear,
            transactionData: [
                'date' => '2025-01-10',
                'description' => '開業資金の預け入れ',
            ],
            journalEntriesData: [
                [
                    'sub_account_id' => $debitSubAccount->id,
                    'type' => 'debit',
                    'amount' => 100000,
                ],
                [
                    'sub_account_id' => $creditSubAccount->id,
                    'type' => 'credit',
                    'amount' => 100000,
                ],
            ]
        );

        $ledger = (new GeneralLedgerService())->generate($debitSubAccount->account, $fiscalYear);

        $this->assertCount(1, $ledger);
        $this->assertSame('2025-01-10', $ledger[0]['date']);
        $this->assertSame('開業資金の預け入れ', $ledger[0]['description']);
        $this->assertSame(100000, $ledger[0]['debit']);
        $this->assertNull($ledger[0]['credit']);
        $this->assertSame(100000, $ledger[0]['balance']);
    }


    #[Test]
    public function 借方と貸方の複数仕訳が時系列順に表示される()
    {
        $user = User::factory()->create();

        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業',
        ]);

        $fiscalYear = $unit->createFiscalYear(2025);

        $cashSubAccount = $unit->getAccountByName('その他の預金')->subAccounts()->first();
        $ownerSubAccount = $unit->getAccountByName('事業主借')->subAccounts()->first();

        $registrar = new TransactionRegistrar();

        // 1件目：入金（借方）
        $registrar->register(
            fiscalYear: $fiscalYear,
            transactionData: [
                'date' => '2025-01-10',
                'description' => '資本金の預け入れ',
            ],
            journalEntriesData: [
                [
                    'sub_account_id' => $cashSubAccount->id,
                    'type' => 'debit',
                    'amount' => 100000,
                ],
                [
                    'sub_account_id' => $ownerSubAccount->id,
                    'type' => 'credit',
                    'amount' => 100000,
                ],
            ]
        );

        // 2件目：支払い（貸方）
        $registrar->register(
            fiscalYear: $fiscalYear,
            transactionData: [
                'date' => '2025-01-15',
                'description' => '備品の購入',
            ],
            journalEntriesData: [
                [
                    'sub_account_id' => $cashSubAccount->id,
                    'type' => 'credit',
                    'amount' => 30000,
                ],
                [
                    'sub_account_id' => $ownerSubAccount->id,
                    'type' => 'debit',
                    'amount' => 30000,
                ],
            ]
        );

        // 3件目：再入金（借方）
        $registrar->register(
            fiscalYear: $fiscalYear,
            transactionData: [
                'date' => '2025-01-20',
                'description' => '追加出資',
            ],
            journalEntriesData: [
                [
                    'sub_account_id' => $cashSubAccount->id,
                    'type' => 'debit',
                    'amount' => 50000,
                ],
                [
                    'sub_account_id' => $ownerSubAccount->id,
                    'type' => 'credit',
                    'amount' => 50000,
                ],
            ]
        );

        $ledger = (new GeneralLedgerService())->generate($cashSubAccount->account, $fiscalYear);

        $this->assertCount(3, $ledger);

        $this->assertSame('2025-01-10', $ledger[0]['date']);
        $this->assertSame(100000, $ledger[0]['debit']);
        $this->assertNull($ledger[0]['credit']);
        $this->assertSame(100000, $ledger[0]['balance']);

        $this->assertSame('2025-01-15', $ledger[1]['date']);
        $this->assertNull($ledger[1]['debit']);
        $this->assertSame(30000, $ledger[1]['credit']);
        $this->assertSame(70000, $ledger[1]['balance']);

        $this->assertSame('2025-01-20', $ledger[2]['date']);
        $this->assertSame(50000, $ledger[2]['debit']);
        $this->assertNull($ledger[2]['credit']);
        $this->assertSame(120000, $ledger[2]['balance']);
    }



    #[Test]
    public function 異なる勘定科目の仕訳はLedgerに含まれない()
    {
        $user = User::factory()->create();

        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業',
        ]);

        $fiscalYear = $unit->createFiscalYear(2025);

        $cashSubAccount = $unit->getAccountByName('その他の預金')->subAccounts()->first();
        $ownerSubAccount = $unit->getAccountByName('事業主借')->subAccounts()->first();

        $registrar = new TransactionRegistrar();

        // 2件とも cash/owner を使った複式仕訳
        // 1件目
        $registrar->register(
            fiscalYear: $fiscalYear,
            transactionData: [
                'date' => '2025-01-05',
                'description' => '最初の出資',
            ],
            journalEntriesData: [
                [
                    'sub_account_id' => $cashSubAccount->id,
                    'type' => 'debit',
                    'amount' => 50000,
                ],
                [
                    'sub_account_id' => $ownerSubAccount->id,
                    'type' => 'credit',
                    'amount' => 50000,
                ],
            ]
        );

        // 2件目
        $registrar->register(
            fiscalYear: $fiscalYear,
            transactionData: [
                'date' => '2025-01-10',
                'description' => '追加出資',
            ],
            journalEntriesData: [
                [
                    'sub_account_id' => $cashSubAccount->id,
                    'type' => 'debit',
                    'amount' => 80000,
                ],
                [
                    'sub_account_id' => $ownerSubAccount->id,
                    'type' => 'credit',
                    'amount' => 80000,
                ],
            ]
        );

        // 事業主借のLedgerだけを生成
        $ledger = (new GeneralLedgerService())->generate($ownerSubAccount->account, $fiscalYear);

        $this->assertCount(2, $ledger);

        $this->assertSame('2025-01-05', $ledger[0]['date']);
        $this->assertNull($ledger[0]['debit']);
        $this->assertSame(50000, $ledger[0]['credit']);
        $this->assertSame(-50000, $ledger[0]['balance']);

        $this->assertSame('2025-01-10', $ledger[1]['date']);
        $this->assertNull($ledger[1]['debit']);
        $this->assertSame(80000, $ledger[1]['credit']);
        $this->assertSame(-130000, $ledger[1]['balance']);
    }


    #[Test]
    public function 指定年度外の仕訳はLedgerに含まれない()
    {
        $user = User::factory()->create();

        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業',
        ]);

        $fiscal2024 = $unit->createFiscalYear(2024);
        $fiscal2025 = $unit->createFiscalYear(2025);

        $cashSubAccount = $unit->getAccountByName('その他の預金')->subAccounts()->first();
        $ownerSubAccount = $unit->getAccountByName('事業主借')->subAccounts()->first();

        $registrar = new TransactionRegistrar();

        // 2024年度の取引（含まれないべき）
        $registrar->register(
            fiscalYear: $fiscal2024,
            transactionData: [
                'date' => '2024-12-31',
                'description' => '前年の入金',
            ],
            journalEntriesData: [
                [
                    'sub_account_id' => $cashSubAccount->id,
                    'type' => 'debit',
                    'amount' => 10000,
                ],
                [
                    'sub_account_id' => $ownerSubAccount->id,
                    'type' => 'credit',
                    'amount' => 10000,
                ],
            ]
        );

        // 2025年度の取引（含まれるべき）
        $registrar->register(
            fiscalYear: $fiscal2025,
            transactionData: [
                'date' => '2025-01-01',
                'description' => '今年の入金',
            ],
            journalEntriesData: [
                [
                    'sub_account_id' => $cashSubAccount->id,
                    'type' => 'debit',
                    'amount' => 50000,
                ],
                [
                    'sub_account_id' => $ownerSubAccount->id,
                    'type' => 'credit',
                    'amount' => 50000,
                ],
            ]
        );

        $ledger = (new GeneralLedgerService())->generate($cashSubAccount->account, $fiscal2025);

        $this->assertCount(1, $ledger);
        $this->assertSame('2025-01-01', $ledger[0]['date']);
        $this->assertSame('今年の入金', $ledger[0]['description']);
        $this->assertSame(50000, $ledger[0]['debit']);
        $this->assertNull($ledger[0]['credit']);
        $this->assertSame(50000, $ledger[0]['balance']);
    }


    #[Test]
    public function 仕訳が存在しない場合は空のLedgerを返す()
    {
        $user = User::factory()->create();

        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業',
        ]);

        $fiscalYear = $unit->createFiscalYear(2025);

        $subAccount = $unit->getAccountByName('その他の預金')->subAccounts()->first();

        $ledger = (new GeneralLedgerService())->generate($subAccount->account, $fiscalYear);

        $this->assertIsArray($ledger);
        $this->assertCount(0, $ledger);
    }


    #[Test]
    public function 補助科目に紐づく仕訳のみがLedgerに表示される()
    {
        $user = User::factory()->create();

        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業',
        ]);
        $fiscalYear = $unit->createFiscalYear(2025);

        $account = $unit->getAccountByName('現金');
        $subAccountA = $account->subAccounts()->create(['name' => 'レジ']);
        $subAccountB = $account->subAccounts()->create(['name' => '金庫']);

        $otherSubAccount = $unit->getAccountByName('事業主借')->subAccounts()->first();

        $registrar = new TransactionRegistrar();

        // レジ（対象）
        $registrar->register(
            fiscalYear: $fiscalYear,
            transactionData: [
                'date' => '2025-01-01',
                'description' => 'レジに入金',
            ],
            journalEntriesData: [
                [
                    'sub_account_id' => $subAccountA->id,
                    'type' => 'debit',
                    'amount' => 10000,
                ],
                [
                    'sub_account_id' => $otherSubAccount->id,
                    'type' => 'credit',
                    'amount' => 10000,
                ],
            ]
        );

        // 金庫（除外対象）
        $registrar->register(
            fiscalYear: $fiscalYear,
            transactionData: [
                'date' => '2025-01-02',
                'description' => '金庫に入金',
            ],
            journalEntriesData: [
                [
                    'sub_account_id' => $subAccountB->id,
                    'type' => 'debit',
                    'amount' => 5000,
                ],
                [
                    'sub_account_id' => $otherSubAccount->id,
                    'type' => 'credit',
                    'amount' => 5000,
                ],
            ]
        );

        $ledger = (new GeneralLedgerService())->generateForSubAccount($subAccountA, $fiscalYear);

        $this->assertCount(1, $ledger);
        $this->assertSame('2025-01-01', $ledger[0]['date']);
        $this->assertSame('レジに入金', $ledger[0]['description']);
        $this->assertSame(10000, $ledger[0]['debit']);
        $this->assertNull($ledger[0]['credit']);
        $this->assertSame(10000, $ledger[0]['balance']);
    }

    #[Test]
    public function 現金出納帳が現金勘定の仕訳のみを返す()
    {
        $user = User::factory()->create();

        $unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業',
        ]);

        $fiscalYear = $unit->createFiscalYear(2025);

        $cashAccount = $unit->getAccountByName('現金');
        $cashSubAccount = $cashAccount->subAccounts()->first();

        $otherSubAccount = $unit->getAccountByName('事業主借')->subAccounts()->first();

        $registrar = new TransactionRegistrar();

        // 1. 現金が借方
        $registrar->register(
            fiscalYear: $fiscalYear,
            transactionData: [
                'date' => '2025-01-05',
                'description' => '現金の出資',
            ],
            journalEntriesData: [
                [
                    'sub_account_id' => $cashSubAccount->id,
                    'type' => 'debit',
                    'amount' => 30000,
                ],
                [
                    'sub_account_id' => $otherSubAccount->id,
                    'type' => 'credit',
                    'amount' => 30000,
                ],
            ]
        );

        // 2. 現金が貸方
        $registrar->register(
            fiscalYear: $fiscalYear,
            transactionData: [
                'date' => '2025-01-10',
                'description' => '現金支出',
            ],
            journalEntriesData: [
                [
                    'sub_account_id' => $cashSubAccount->id,
                    'type' => 'credit',
                    'amount' => 10000,
                ],
                [
                    'sub_account_id' => $otherSubAccount->id,
                    'type' => 'debit',
                    'amount' => 10000,
                ],
            ]
        );

        $cashbook = (new GeneralLedgerService())->generateCashbook($fiscalYear);

        $this->assertCount(2, $cashbook);

        $this->assertSame('2025-01-05', $cashbook[0]['date']);
        $this->assertSame(30000, $cashbook[0]['debit']);
        $this->assertNull($cashbook[0]['credit']);
        $this->assertSame(30000, $cashbook[0]['balance']);

        $this->assertSame('2025-01-10', $cashbook[1]['date']);
        $this->assertNull($cashbook[1]['debit']);
        $this->assertSame(10000, $cashbook[1]['credit']);
        $this->assertSame(20000, $cashbook[1]['balance']);
    }
}
