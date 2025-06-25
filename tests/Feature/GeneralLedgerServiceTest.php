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

        $debitAccount = $unit->getAccountByName('その他の預金');
        $creditAccount = $unit->getAccountByName('事業主借');

        $registrar = new TransactionRegistrar();

        $registrar->register(
            fiscalYear: $fiscalYear,
            transactionData: [
                'date' => '2025-01-10',
                'description' => '開業資金の預け入れ',
            ],
            journalEntriesData: [
                [
                    'account_id' => $debitAccount->id,
                    'type' => 'debit',
                    'amount' => 100000,
                ],
                [
                    'account_id' => $creditAccount->id,
                    'type' => 'credit',
                    'amount' => 100000,
                ],
            ]
        );

        $ledger = (new GeneralLedgerService())->generate($debitAccount, $fiscalYear);

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

        $cash = $unit->getAccountByName('その他の預金');
        $owner = $unit->getAccountByName('事業主借');

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
                    'account_id' => $cash->id,
                    'type' => 'debit',
                    'amount' => 100000,
                ],
                [
                    'account_id' => $owner->id,
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
                    'account_id' => $cash->id,
                    'type' => 'credit',
                    'amount' => 30000,
                ],
                [
                    'account_id' => $owner->id,
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
                    'account_id' => $cash->id,
                    'type' => 'debit',
                    'amount' => 50000,
                ],
                [
                    'account_id' => $owner->id,
                    'type' => 'credit',
                    'amount' => 50000,
                ],
            ]
        );

        $ledger = (new GeneralLedgerService())->generate($cash, $fiscalYear);

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

        $cash = $unit->getAccountByName('その他の預金');
        $owner = $unit->getAccountByName('事業主借');

        $registrar = new TransactionRegistrar();

        // 2件とも cash/owner を使った複式仕訳
        $registrar->register(
            fiscalYear: $fiscalYear,
            transactionData: [
                'date' => '2025-01-05',
                'description' => '最初の出資',
            ],
            journalEntriesData: [
                [
                    'account_id' => $cash->id,
                    'type' => 'debit',
                    'amount' => 50000,
                ],
                [
                    'account_id' => $owner->id,
                    'type' => 'credit',
                    'amount' => 50000,
                ],
            ]
        );

        $registrar->register(
            fiscalYear: $fiscalYear,
            transactionData: [
                'date' => '2025-01-10',
                'description' => '追加出資',
            ],
            journalEntriesData: [
                [
                    'account_id' => $cash->id,
                    'type' => 'debit',
                    'amount' => 80000,
                ],
                [
                    'account_id' => $owner->id,
                    'type' => 'credit',
                    'amount' => 80000,
                ],
            ]
        );

        // 事業主借のLedgerだけを生成
        $ledger = (new GeneralLedgerService())->generate($owner, $fiscalYear);

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

        $cash = $unit->getAccountByName('その他の預金');
        $owner = $unit->getAccountByName('事業主借');

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
                    'account_id' => $cash->id,
                    'type' => 'debit',
                    'amount' => 10000,
                ],
                [
                    'account_id' => $owner->id,
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
                    'account_id' => $cash->id,
                    'type' => 'debit',
                    'amount' => 50000,
                ],
                [
                    'account_id' => $owner->id,
                    'type' => 'credit',
                    'amount' => 50000,
                ],
            ]
        );

        $ledger = (new GeneralLedgerService())->generate($cash, $fiscal2025);

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

        $cash = $unit->getAccountByName('その他の預金');

        $ledger = (new GeneralLedgerService())->generate($cash, $fiscalYear);

        $this->assertIsArray($ledger);
        $this->assertCount(0, $ledger);
    }
}
