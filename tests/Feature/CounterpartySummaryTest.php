<?php

namespace Tests\Feature;

use App\Models\Counterparty;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CounterpartySummaryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    #[Group('mysql')]
    public function 取引先の集計が支出と収入の形で取得できる(): void
    {
        [$counterparty, $fiscalYear2024, $fiscalYear2025, $expenseSubAccountA, $expenseSubAccountB, $cashSubAccount, $revenueSubAccount] = $this->createSummaryFixture();

        $purchase = Transaction::factory()->create([
            'fiscal_year_id' => $fiscalYear2024->id,
            'counterparty_id' => $counterparty->id,
            'date' => '2024-04-10',
            'is_active' => true,
        ]);

        $purchase->journalEntries()->createMany([
            [
                'sub_account_id' => $expenseSubAccountA->id,
                'type' => 'debit',
                'net_amount' => 1000,
                'tax_amount' => 100,
            ],
            [
                'sub_account_id' => $expenseSubAccountB->id,
                'type' => 'debit',
                'net_amount' => 2000,
                'tax_amount' => 200,
            ],
            [
                'sub_account_id' => $cashSubAccount->id,
                'type' => 'credit',
                'net_amount' => 3300,
                'tax_amount' => 0,
            ],
        ]);

        $sale = Transaction::factory()->create([
            'fiscal_year_id' => $fiscalYear2024->id,
            'counterparty_id' => $counterparty->id,
            'date' => '2024-05-15',
            'is_active' => true,
        ]);

        $sale->journalEntries()->createMany([
            [
                'sub_account_id' => $cashSubAccount->id,
                'type' => 'debit',
                'net_amount' => 4400,
                'tax_amount' => 0,
            ],
            [
                'sub_account_id' => $revenueSubAccount->id,
                'type' => 'credit',
                'net_amount' => 4000,
                'tax_amount' => 400,
            ],
        ]);

        $sale2025 = Transaction::factory()->create([
            'fiscal_year_id' => $fiscalYear2025->id,
            'counterparty_id' => $counterparty->id,
            'date' => '2025-05-15',
            'is_active' => true,
        ]);

        $sale2025->journalEntries()->createMany([
            [
                'sub_account_id' => $cashSubAccount->id,
                'type' => 'debit',
                'net_amount' => 5500,
                'tax_amount' => 0,
            ],
            [
                'sub_account_id' => $revenueSubAccount->id,
                'type' => 'credit',
                'net_amount' => 5000,
                'tax_amount' => 500,
            ],
        ]);

        $inactive = Transaction::factory()->create([
            'fiscal_year_id' => $fiscalYear2025->id,
            'counterparty_id' => $counterparty->id,
            'date' => '2025-06-01',
            'is_active' => false,
        ]);

        $inactive->journalEntries()->createMany([
            [
                'sub_account_id' => $expenseSubAccountA->id,
                'type' => 'debit',
                'net_amount' => 9999,
                'tax_amount' => 999,
            ],
            [
                'sub_account_id' => $cashSubAccount->id,
                'type' => 'credit',
                'net_amount' => 10998,
                'tax_amount' => 0,
            ],
        ]);

        $summary = $counterparty->calculateAmountSummary();

        $this->assertSame([
            'expense' => [
                'accounts' => [
                    [
                        'account_id' => $expenseSubAccountA->account_id,
                        'account_name' => '消耗品費',
                        'amount' => 1100,
                    ],
                    [
                        'account_id' => $expenseSubAccountB->account_id,
                        'account_name' => '通信費',
                        'amount' => 2200,
                    ],
                ],
                'total_amount' => 3300,
            ],
            'income' => [
                'accounts' => [
                    [
                        'account_id' => $revenueSubAccount->account_id,
                        'account_name' => '売上高',
                        'amount' => 9900,
                    ],
                ],
                'total_amount' => 9900,
            ],
        ], $summary['all']);

        $this->assertSame([
            2024 => [
                'expense' => [
                    'accounts' => [
                        [
                            'account_id' => $expenseSubAccountA->account_id,
                            'account_name' => '消耗品費',
                            'amount' => 1100,
                        ],
                        [
                            'account_id' => $expenseSubAccountB->account_id,
                            'account_name' => '通信費',
                            'amount' => 2200,
                        ],
                    ],
                    'total_amount' => 3300,
                ],
                'income' => [
                    'accounts' => [
                        [
                            'account_id' => $revenueSubAccount->account_id,
                            'account_name' => '売上高',
                            'amount' => 4400,
                        ],
                    ],
                    'total_amount' => 4400,
                ],
            ],
            2025 => [
                'expense' => [
                    'accounts' => [],
                    'total_amount' => 0,
                ],
                'income' => [
                    'accounts' => [
                        [
                            'account_id' => $revenueSubAccount->account_id,
                            'account_name' => '売上高',
                            'amount' => 5500,
                        ],
                    ],
                    'total_amount' => 5500,
                ],
            ],
        ], $summary['fiscal_years']);
    }

    #[Test]
    #[Group('mysql')]
    public function 年を指定するとその年だけの支出と収入を取得できる(): void
    {
        [$counterparty, $fiscalYear2024, $fiscalYear2025, $expenseSubAccountA, $expenseSubAccountB, $cashSubAccount, $revenueSubAccount] = $this->createSummaryFixture();

        $purchase = Transaction::factory()->create([
            'fiscal_year_id' => $fiscalYear2024->id,
            'counterparty_id' => $counterparty->id,
            'date' => '2024-04-10',
            'is_active' => true,
        ]);

        $purchase->journalEntries()->createMany([
            [
                'sub_account_id' => $expenseSubAccountA->id,
                'type' => 'debit',
                'net_amount' => 1000,
                'tax_amount' => 100,
            ],
            [
                'sub_account_id' => $expenseSubAccountB->id,
                'type' => 'debit',
                'net_amount' => 2000,
                'tax_amount' => 200,
            ],
            [
                'sub_account_id' => $cashSubAccount->id,
                'type' => 'credit',
                'net_amount' => 3300,
                'tax_amount' => 0,
            ],
        ]);

        $sale = Transaction::factory()->create([
            'fiscal_year_id' => $fiscalYear2024->id,
            'counterparty_id' => $counterparty->id,
            'date' => '2024-05-15',
            'is_active' => true,
        ]);

        $sale->journalEntries()->createMany([
            [
                'sub_account_id' => $cashSubAccount->id,
                'type' => 'debit',
                'net_amount' => 4400,
                'tax_amount' => 0,
            ],
            [
                'sub_account_id' => $revenueSubAccount->id,
                'type' => 'credit',
                'net_amount' => 4000,
                'tax_amount' => 400,
            ],
        ]);

        Transaction::factory()->create([
            'fiscal_year_id' => $fiscalYear2025->id,
            'counterparty_id' => $counterparty->id,
            'date' => '2025-05-15',
            'is_active' => true,
        ])->journalEntries()->createMany([
            [
                'sub_account_id' => $cashSubAccount->id,
                'type' => 'debit',
                'net_amount' => 5500,
                'tax_amount' => 0,
            ],
            [
                'sub_account_id' => $revenueSubAccount->id,
                'type' => 'credit',
                'net_amount' => 5000,
                'tax_amount' => 500,
            ],
        ]);

        $summary = $counterparty->calculateAmountSummaryForFiscalYear(2024);

        $this->assertSame([
            'expense' => [
                'accounts' => [
                    [
                        'account_id' => $expenseSubAccountA->account_id,
                        'account_name' => '消耗品費',
                        'amount' => 1100,
                    ],
                    [
                        'account_id' => $expenseSubAccountB->account_id,
                        'account_name' => '通信費',
                        'amount' => 2200,
                    ],
                ],
                'total_amount' => 3300,
            ],
            'income' => [
                'accounts' => [
                    [
                        'account_id' => $revenueSubAccount->account_id,
                        'account_name' => '売上高',
                        'amount' => 4400,
                    ],
                ],
                'total_amount' => 4400,
            ],
        ], $summary);
    }

    #[Test]
    #[Group('mysql')]
    public function 取引がなければ支出も収入も空になる(): void
    {
        $counterparty = Counterparty::factory()->create();

        $summary = $counterparty->calculateAmountSummary();

        $this->assertSame([
            'expense' => [
                'accounts' => [],
                'total_amount' => 0,
            ],
            'income' => [
                'accounts' => [],
                'total_amount' => 0,
            ],
        ], $summary['all']);

        $this->assertSame([], $summary['fiscal_years']);
    }

    /**
     * @return array{0: Counterparty, 1: mixed, 2: mixed, 3: mixed, 4: mixed, 5: mixed, 6: mixed}
     */
    protected function createSummaryFixture(): array
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '取引先集計テスト事業体']);

        $fiscalYear2024 = $unit->createFiscalYear(2024);
        $fiscalYear2025 = $unit->createFiscalYear(2025);

        $counterparty = Counterparty::factory()->create([
            'business_unit_id' => $unit->id,
            'name' => '集計商店',
        ]);

        return [
            $counterparty,
            $fiscalYear2024,
            $fiscalYear2025,
            $unit->getAccountByName('消耗品費')->subAccounts()->first(),
            $unit->getAccountByName('通信費')->subAccounts()->first(),
            $unit->getAccountByName('現金')->subAccounts()->first(),
            $unit->getAccountByName('売上高')->subAccounts()->first(),
        ];
    }
}
