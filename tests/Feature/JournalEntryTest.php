<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use App\Validators\JournalEntryValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use App\Models\User;
use App\Services\TransactionRegistrar;

class JournalEntryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 正しいデータでバリデーションが通る()
    {
        $transaction = Transaction::factory()->create();
        $account = Account::factory()->create();

        $data = [
            'transaction_id' => $transaction->id,
            'account_id' => $account->id,
            'type' => 'debit',
            'amount' => 1000,
        ];

        $this->expectNotToPerformAssertions();
        JournalEntryValidator::validate($data, true);
    }

    #[Test]
    public function transaction_idがnullの場合はバリデーションエラー()
    {
        Account::factory()->create();

        $data = [
            'transaction_id' => null,
            'account_id' => 1,
            'type' => 'debit',
            'amount' => 1000,
        ];

        $this->expectException(ValidationException::class);
        JournalEntryValidator::validate($data, true);
    }

    #[Test]
    public function account_idがnullの場合はバリデーションエラー()
    {
        Transaction::factory()->create();

        $data = [
            'transaction_id' => 1,
            'account_id' => null,
            'type' => 'debit',
            'amount' => 1000,
        ];

        $this->expectException(ValidationException::class);
        JournalEntryValidator::validate($data, true);
    }

    #[Test]
    public function typeがnullの場合はバリデーションエラー()
    {
        $transaction = Transaction::factory()->create();
        $account = Account::factory()->create();

        $data = [
            'transaction_id' => $transaction->id,
            'account_id' => $account->id,
            'type' => null,
            'amount' => 1000,
        ];

        $this->expectException(ValidationException::class);
        JournalEntryValidator::validate($data, true);
    }

    #[Test]
    public function amountがnullの場合はバリデーションエラー()
    {
        $transaction = Transaction::factory()->create();
        $account = Account::factory()->create();

        $data = [
            'transaction_id' => $transaction->id,
            'account_id' => $account->id,
            'type' => 'debit',
            'amount' => null,
        ];

        $this->expectException(ValidationException::class);
        JournalEntryValidator::validate($data, true);
    }

    #[Test]
    public function typeが不正な値の場合はバリデーションエラー()
    {
        $transaction = Transaction::factory()->create();
        $account = Account::factory()->create();

        $data = [
            'transaction_id' => $transaction->id,
            'account_id' => $account->id,
            'type' => 'invalid',
            'amount' => 1000,
        ];

        $this->expectException(ValidationException::class);
        JournalEntryValidator::validate($data, true);
    }

    #[Test]
    public function amountが0円以下の場合はバリデーションエラー()
    {
        $transaction = Transaction::factory()->create();
        $account = Account::factory()->create();

        $data = [
            'transaction_id' => $transaction->id,
            'account_id' => $account->id,
            'type' => 'debit',
            'amount' => 0,
        ];

        $this->expectException(ValidationException::class);
        JournalEntryValidator::validate($data, true);
    }

    #[Test]
    public function tax_amountがマイナスの場合はバリデーションエラー()
    {
        $transaction = Transaction::factory()->create();
        $account = Account::factory()->create();

        $data = [
            'transaction_id' => $transaction->id,
            'account_id' => $account->id,
            'type' => 'debit',
            'amount' => 1000,
            'tax_amount' => -100,
        ];

        $this->expectException(ValidationException::class);
        JournalEntryValidator::validate($data, true);
    }

    #[Test]
    public function tax_typeが不正な値の場合はバリデーションエラー()
    {
        $transaction = Transaction::factory()->create();
        $account = Account::factory()->create();

        $data = [
            'transaction_id' => $transaction->id,
            'account_id' => $account->id,
            'type' => 'debit',
            'amount' => 1000,
            'tax_type' => 'wrong',
        ];

        $this->expectException(ValidationException::class);
        JournalEntryValidator::validate($data, true);
    }

    #[Test]
    public function is_effectiveに真偽値以外を渡すとバリデーションエラー()
    {
        $transaction = Transaction::factory()->create();
        $account = Account::factory()->create();

        $data = [
            'transaction_id' => $transaction->id,
            'account_id' => $account->id,
            'type' => 'debit',
            'amount' => 1000,
            'is_effective' => 'yes',
        ];

        $this->expectException(ValidationException::class);
        JournalEntryValidator::validate($data, true);
    }

    #[Test]
    public function sub_account_idがnullでもバリデーションが通る()
    {
        $transaction = Transaction::factory()->create();
        $account = Account::factory()->create();

        $data = [
            'transaction_id' => $transaction->id,
            'account_id' => $account->id,
            'type' => 'debit',
            'amount' => 1000,
            'sub_account_id' => null,
        ];

        $this->expectNotToPerformAssertions();
        JournalEntryValidator::validate($data, true);
    }

    #[Test]
    public function tax_amountがnullでもバリデーションが通る()
    {
        $transaction = Transaction::factory()->create();
        $account = Account::factory()->create();

        $data = [
            'transaction_id' => $transaction->id,
            'account_id' => $account->id,
            'type' => 'debit',
            'amount' => 1000,
            'tax_amount' => null,
        ];

        $this->expectNotToPerformAssertions();
        JournalEntryValidator::validate($data, true);
    }

    #[Test]
    public function tax_typeがnullでもバリデーションが通る()
    {
        $transaction = Transaction::factory()->create();
        $account = Account::factory()->create();

        $data = [
            'transaction_id' => $transaction->id,
            'account_id' => $account->id,
            'type' => 'debit',
            'amount' => 1000,
            'tax_type' => null,
        ];

        $this->expectNotToPerformAssertions();
        JournalEntryValidator::validate($data, true);
    }

    #[Test]
    public function is_effectiveが省略された場合でもバリデーションが通る()
    {
        $transaction = Transaction::factory()->create();
        $account = Account::factory()->create();

        $data = [
            'transaction_id' => $transaction->id,
            'account_id' => $account->id,
            'type' => 'debit',
            'amount' => 1000,
        ];

        $this->expectNotToPerformAssertions();
        JournalEntryValidator::validate($data, true);
    }


    #[Test]
    public function 同一勘定科目に複数の補助科目を使って取引を登録できる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'テスト事業']);
        $fiscalYear = $unit->createFiscalYear(2025);

        $account = $unit->accounts()
            ->where('name', 'その他の預金')
            ->firstOrFail();

        $subA = $account->createSubAccount(['name' => '口座A']);
        $subB = $account->createSubAccount(['name' => '口座B']);
        $subC = $account->createSubAccount(['name' => '口座C']);

        $equityAccount = $unit->accounts()->where('type', 'equity')->first();

        $registrar = app(TransactionRegistrar::class);

        $registrar->register(
            $fiscalYear,
            [
                'date' => '2025-03-01',
                'description' => '口座Aへの入金',
            ],
            [
                [
                    'account_id' => $account->id,
                    'sub_account_id' => $subA->id,
                    'type' => 'debit',
                    'amount' => 10000,
                    'tax_amount' => 0,
                    'tax_type' => 'non_taxable',
                ],
                [
                    'account_id' => $equityAccount->id,
                    'type' => 'credit',
                    'amount' => 10000,
                    'tax_amount' => 0,
                    'tax_type' => 'non_taxable',
                ],
            ]
        );

        $registrar->register(
            $fiscalYear,
            [
                'date' => '2025-03-02',
                'description' => '口座Bへの入金',
            ],
            [
                [
                    'account_id' => $account->id,
                    'sub_account_id' => $subB->id,
                    'type' => 'debit',
                    'amount' => 20000,
                    'tax_amount' => 0,
                    'tax_type' => 'non_taxable',
                ],
                [
                    'account_id' => $equityAccount->id,
                    'type' => 'credit',
                    'amount' => 20000,
                    'tax_amount' => 0,
                    'tax_type' => 'non_taxable',
                ],
            ]
        );

        $registrar->register(
            $fiscalYear,
            [
                'date' => '2025-03-03',
                'description' => '口座Cへの入金',
            ],
            [
                [
                    'account_id' => $account->id,
                    'sub_account_id' => $subC->id,
                    'type' => 'debit',
                    'amount' => 30000,
                    'tax_amount' => 0,
                    'tax_type' => 'non_taxable',
                ],
                [
                    'account_id' => $equityAccount->id,
                    'type' => 'credit',
                    'amount' => 30000,
                    'tax_amount' => 0,
                    'tax_type' => 'non_taxable',
                ],
            ]
        );

        $this->assertSame(3, $account->journalEntries()->count());

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $subA->id,
            'amount' => 10000,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $subB->id,
            'amount' => 20000,
        ]);

        $this->assertDatabaseHas('journal_entries', [
            'sub_account_id' => $subC->id,
            'amount' => 30000,
        ]);
    }
}
