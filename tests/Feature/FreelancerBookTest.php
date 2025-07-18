<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TransactionRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FreelancerBookTest extends TestCase
{
    use RefreshDatabase;

    protected \App\Models\BusinessUnit $unit;
    protected \App\Models\FiscalYear $fiscalYear;
    protected \Illuminate\Support\Collection $accounts;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();

        // 本番同様、BusinessUnit作成時にAccountが自動生成される
        $this->unit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業所',
        ]);

        $this->fiscalYear = $this->unit->createFiscalYear(2024);

        $names = [
            'bank' => '当座預金',
            'cash' => '現金',
            'sales' => '売上高',
            'consumables' => '消耗品費',
            'telecom' => '通信費',
            'transport' => '旅費交通費',
            'rent' => '地代家賃',
            'entertainment' => '接待交際費',
            'books' => '雑費',
            'owner_contribution' => '事業主借',
            'owner_draw' => '事業主貸',
        ];

        $this->accounts = collect($names)->mapWithKeys(function ($name, $key) {
            return [
                $key => $this->unit->accounts()->where('name', $name)->firstOrFail(),
            ];
        });
    }

    protected function subId(string $key): int
    {
        return $this->accounts[$key]->subAccounts->first()->id;
    }

    #[Test]
    public function 青色申告用の取引サンプルを8件登録できる()
    {
        $registrar = new TransactionRegistrar();

        $samples = [
            [
                'date' => '2024-01-05',
                'description' => 'Amazonで備品購入',
                'debit' => ['consumables', 5000, 500, 'taxable_purchases_10'],
                'credit' => ['bank', 5500, 0, 'non_taxable'],
            ],
            [
                'date' => '2024-01-10',
                'description' => '売上入金（ココナラ）',
                'debit' => ['bank', 11000, 0, 'non_taxable'],
                'credit' => ['sales', 10000, 1000, 'taxable_sales_10'],
            ],
            [
                'date' => '2024-01-15',
                'description' => '携帯代支払',
                'debit' => ['telecom', 3000, 300, 'taxable_purchases_10'],
                'credit' => ['bank', 3300, 0, 'non_taxable'],
            ],
            [
                'date' => '2024-01-25',
                'description' => '電車代',
                'debit' => ['transport', 1500, 0, 'non_taxable'],
                'credit' => ['cash', 1500, 0, 'non_taxable'],
            ],
            [
                'date' => '2024-02-01',
                'description' => '2月家賃支払',
                'debit' => ['rent', 54545, 5455, 'taxable_purchases_10'],
                'credit' => ['bank', 60000, 0, 'non_taxable'],
            ],
            [
                'date' => '2024-02-07',
                'description' => '売上入金（直接振込）',
                'debit' => ['bank', 55000, 0, 'non_taxable'],
                'credit' => ['sales', 50000, 5000, 'taxable_sales_10'],
            ],
            [
                'date' => '2024-02-18',
                'description' => '打ち合わせ時のコーヒー代',
                'debit' => ['entertainment', 728, 72, 'taxable_purchases_10'],
                'credit' => ['cash', 800, 0, 'non_taxable'],
            ],
            [
                'date' => '2024-02-25',
                'description' => 'ソフト購入（Adobe）',
                'debit' => ['books', 2980, 298, 'taxable_purchases_10'],
                'credit' => ['owner_contribution', 3278, 0, 'non_taxable'],
            ],
        ];

        foreach ($samples as $sample) {
            $transactionData = [
                'date' => $sample['date'],
                'description' => $sample['description'],
            ];

            $journalEntriesData = [
                [
                    'sub_account_id' => $this->subId($sample['debit'][0]),
                    'type' => 'debit',
                    'amount' => $sample['debit'][1],
                    'tax_amount' => $sample['debit'][2],
                    'tax_type' => $sample['debit'][3],
                ],
                [
                    'sub_account_id' => $this->subId($sample['credit'][0]),
                    'type' => 'credit',
                    'amount' => $sample['credit'][1],
                    'tax_amount' => $sample['credit'][2],
                    'tax_type' => $sample['credit'][3],
                ],
            ];

            $transaction = $registrar->register($this->fiscalYear, $transactionData, $journalEntriesData);

            $this->assertDatabaseHas('transactions', [
                'id' => $transaction->id,
                'description' => $sample['description'],
            ]);
        }

        $this->assertDatabaseCount('transactions', 8);
        $this->assertDatabaseCount('journal_entries', 16);
    }

    #[Test]
    public function 金額がバランスしていない場合は例外が発生する()
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('仕訳の金額がバランスしていません');

        $registrar = new TransactionRegistrar();

        $transactionData = [
            'fiscal_year_id' => $this->fiscalYear->id,
            'date' => '2024-03-01',
            'description' => 'バランスしないテスト',
        ];

        $journalEntriesData = [
            [
                'sub_account_id' => $this->subId('consumables'),
                'type' => 'debit',
                'amount' => 1000,
                'tax_amount' => 0,
                'tax_type' => 'non_taxable',
            ],
            [
                'sub_account_id' => $this->subId('bank'),
                'type' => 'credit',
                'amount' => 2000,
                'tax_amount' => 0,
                'tax_type' => 'non_taxable',
            ],
        ];

        $registrar->register($this->fiscalYear, $transactionData, $journalEntriesData);
    }
}
