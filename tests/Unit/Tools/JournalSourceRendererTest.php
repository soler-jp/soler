<?php

namespace Tests\Unit\Tools;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Tools\JournalSourceRenderer;

class JournalSourceRendererTest extends TestCase
{
    #[Test]
    public function 仕訳登録の文だけを1行スタイルに置き換えたフルソースを返す(): void
    {
        $source = <<<'PHP'
        <?php

        class SampleTest
        {
            public function 消耗品を現金で購入できる(): void
            {
                $cash = $businessUnit->getSubAccountByName('現金', '現金');
                $expense = $businessUnit->getSubAccountByName('消耗品費', '消耗品費');

                $fiscalYear->registerOpeningEntry([
                    [
                        'account_name' => '現金',
                        'sub_account_name' => '現金',
                        'amount' => 100_000,
                    ],
                ]);

                (new TransactionRegistrar)->register($fiscalYear, [
                    'date' => '2025-06-10',
                    'description' => '消耗品購入',
                ], [
                    [
                        'sub_account_id' => $expense->id,
                        'type' => JournalEntry::TYPE_DEBIT,
                        'net_amount' => 10_000,
                    ],
                    [
                        'sub_account_id' => $cash->id,
                        'type' => JournalEntry::TYPE_CREDIT,
                        'net_amount' => 10_000,
                    ],
                ]);

                $this->assertTrue(true);
            }
        }
        PHP;

        $expected = <<<'PHP'
        <?php

        class SampleTest
        {
            public function 消耗品を現金で購入できる(): void
            {
                $cash = $businessUnit->getSubAccountByName('現金', '現金');
                $expense = $businessUnit->getSubAccountByName('消耗品費', '消耗品費');

                ▶ 期首残高   | 現金     100,000 /             |

                ▶ 2025-06-10 | 消耗品費  10,000 / 現金 10,000 | 消耗品購入

                $this->assertTrue(true);
            }
        }
        PHP;

        $this->assertSame($expected, (new JournalSourceRenderer)->renderSource($source));
    }

    #[Test]
    public function 代入先と取引先などの追加項目や税込入力も表示される(): void
    {
        $source = <<<'PHP'
        <?php

        class SampleTest
        {
            public function 税込入力で登録できる(): void
            {
                $cash = $businessUnit->getSubAccountByName('現金', '現金');
                $expense = $businessUnit->getSubAccountByName('消耗品費', '消耗品費');

                $transaction = (new TransactionRegistrar)->register($fiscalYear, [
                    'date' => '2025-06-15',
                    'description' => 'T付き登録番号',
                    'counterparty_name' => 'ABC商店',
                    'counterparty_registration_number' => ' T1234567890123 ',
                ], [
                    [
                        'sub_account_id' => $expense->id,
                        'type' => 'debit',
                        'gross_amount' => 11_000,
                        'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
                    ],
                    [
                        'sub_account_id' => $cash->id,
                        'type' => 'credit',
                        'net_amount' => 11_000,
                    ],
                ]);
            }
        }
        PHP;

        $expected = <<<'PHP'
        <?php

        class SampleTest
        {
            public function 税込入力で登録できる(): void
            {
                $cash = $businessUnit->getSubAccountByName('現金', '現金');
                $expense = $businessUnit->getSubAccountByName('消耗品費', '消耗品費');

                ▶ $transaction = 2025-06-15 | 消耗品費 (tax_type: TAX_TYPE_TAXABLE_PURCHASES_10) 税込11,000 / 現金 11,000 | T付き登録番号   # counterparty_name: 'ABC商店', counterparty_registration_number: ' T1234567890123 '
            }
        }
        PHP;

        $this->assertSame($expected, (new JournalSourceRenderer)->renderSource($source));
    }

    #[Test]
    public function 複合仕訳は桁を揃えた複数の行に置き換える(): void
    {
        $source = <<<'PHP'
        <?php

        class SampleTest
        {
            public function 複合仕訳を登録できる(): void
            {
                $cash = $businessUnit->getSubAccountByName('現金', '現金');
                $purchase = $businessUnit->getSubAccountByName('仕入高', '仕入高');
                $tax = $businessUnit->getSubAccountByName('仮払消費税', '仮払消費税');

                (new TransactionRegistrar)->register($fiscalYear, [
                    'date' => '2025-07-01',
                    'description' => '商品仕入',
                ], [
                    [
                        'sub_account_id' => $purchase->id,
                        'type' => JournalEntry::TYPE_DEBIT,
                        'net_amount' => 10_000,
                    ],
                    [
                        'sub_account_id' => $tax->id,
                        'type' => JournalEntry::TYPE_DEBIT,
                        'net_amount' => 1_000,
                    ],
                    [
                        'sub_account_id' => $cash->id,
                        'type' => JournalEntry::TYPE_CREDIT,
                        'net_amount' => 11_000,
                    ],
                ]);
            }
        }
        PHP;

        $expected = <<<'PHP'
        <?php

        class SampleTest
        {
            public function 複合仕訳を登録できる(): void
            {
                $cash = $businessUnit->getSubAccountByName('現金', '現金');
                $purchase = $businessUnit->getSubAccountByName('仕入高', '仕入高');
                $tax = $businessUnit->getSubAccountByName('仮払消費税', '仮払消費税');

                ▶ 2025-07-01 | 仕入高     10,000 / 現金 11,000 | 商品仕入
                ▶            | 仮払消費税  1,000 /             |
            }
        }
        PHP;

        $this->assertSame($expected, (new JournalSourceRenderer)->renderSource($source));
    }

    #[Test]
    public function 解決できない式は原文のまま表示する(): void
    {
        $source = <<<'PHP'
        <?php

        class SampleTest
        {
            public function 変数を解決できない仕訳(): void
            {
                (new TransactionRegistrar)->register($fiscalYear, [
                    'date' => now()->toDateString(),
                    'description' => '解決不能',
                ], [
                    [
                        'sub_account_id' => $subAccount->id,
                        'type' => 'debit',
                        'net_amount' => $amount,
                    ],
                    [
                        'sub_account_id' => $subAccount->id,
                        'type' => 'credit',
                        'net_amount' => $amount,
                    ],
                ]);
            }
        }
        PHP;

        $expected = <<<'PHP'
        <?php

        class SampleTest
        {
            public function 変数を解決できない仕訳(): void
            {
                ▶ now()->toDateString() | $subAccount->id $amount / $subAccount->id $amount | 解決不能
            }
        }
        PHP;

        $this->assertSame($expected, (new JournalSourceRenderer)->renderSource($source));
    }

    #[Test]
    public function 仕訳明細のアサーションはチェックマーク付きの行に置き換える(): void
    {
        $source = <<<'PHP'
        <?php

        class SampleTest
        {
            public function 繰越データを検証できる(): void
            {
                $cash = $businessUnit->getSubAccountByName('現金', '現金');
                $loan = $businessUnit->getSubAccountByName('借入金', '借入金');

                (new TransactionRegistrar)->register($fiscalYear, [
                    'date' => '2025-04-10',
                    'description' => '借入',
                ], [
                    [
                        'sub_account_id' => $cash->id,
                        'type' => JournalEntry::TYPE_DEBIT,
                        'net_amount' => 20_000,
                    ],
                    [
                        'sub_account_id' => $loan->id,
                        'type' => JournalEntry::TYPE_CREDIT,
                        'net_amount' => 20_000,
                    ],
                ]);

                $this->assertSame(2026, $rolloverData['next_year']);
                $this->assertSame([
                    [
                        'account_name' => '現金',
                        'sub_account_name' => '現金',
                        'amount' => 140_000,
                        'type' => 'debit',
                    ],
                    [
                        'account_name' => '借入金',
                        'sub_account_name' => '借入金',
                        'amount' => 20_000,
                        'type' => 'credit',
                    ],
                ], $rolloverData['opening_entries']);
                $this->assertSame([
                    'account_name' => '元入金',
                    'sub_account_name' => '元入金',
                    'amount' => 120_000,
                    'type' => 'credit',
                ], $rolloverData['capital_entry']);
            }
        }
        PHP;

        $expected = <<<'PHP'
        <?php

        class SampleTest
        {
            public function 繰越データを検証できる(): void
            {
                $cash = $businessUnit->getSubAccountByName('現金', '現金');
                $loan = $businessUnit->getSubAccountByName('借入金', '借入金');

                ▶ 2025-04-10 | 現金 20,000 / 借入金 20,000 | 借入

                $this->assertSame(2026, $rolloverData['next_year']);
                ✓ 現金 140,000 / 借入金  20,000 | $rolloverData['opening_entries']
                ✓              / 元入金 120,000 | $rolloverData['capital_entry']
            }
        }
        PHP;

        $this->assertSame($expected, (new JournalSourceRenderer)->renderSource($source));
    }

    #[Test]
    public function sub_account_by_nameヘルパー経由の変数も科目名に解決できる(): void
    {
        $source = <<<'PHP'
        <?php

        class SampleTest
        {
            public function 残高を集計できる(): void
            {
                $cash = $this->subAccountByName($unit, '現金');
                $ownerLoan = $this->subAccountByName($unit, '事業主借');

                $registrar->register($fiscalYear, [
                    'date' => '2025-04-01',
                    'description' => '現金の増加',
                ], [
                    ['sub_account_id' => $cash->id, 'type' => 'debit', 'gross_amount' => 1100],
                    ['sub_account_id' => $ownerLoan->id, 'type' => 'credit', 'gross_amount' => 1100],
                ]);
            }
        }
        PHP;

        $expected = <<<'PHP'
        <?php

        class SampleTest
        {
            public function 残高を集計できる(): void
            {
                $cash = $this->subAccountByName($unit, '現金');
                $ownerLoan = $this->subAccountByName($unit, '事業主借');

                ▶ 2025-04-01 | 現金 税込1,100 / 事業主借 税込1,100 | 現金の増加
            }
        }
        PHP;

        $this->assertSame($expected, (new JournalSourceRenderer)->renderSource($source));
    }

    #[Test]
    public function 仕訳登録がないソースは空文字を返す(): void
    {
        $source = <<<'PHP'
        <?php

        class SampleTest
        {
            public function 仕訳を登録しないテスト(): void
            {
                $this->assertTrue(true);
            }
        }
        PHP;

        $this->assertSame('', (new JournalSourceRenderer)->renderSource($source));
    }
}
