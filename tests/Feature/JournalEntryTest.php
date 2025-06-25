<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use App\Validators\JournalEntryValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

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
        JournalEntryValidator::validate($data);
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
        JournalEntryValidator::validate($data);
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
        JournalEntryValidator::validate($data);
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
        JournalEntryValidator::validate($data);
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
        JournalEntryValidator::validate($data);
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
        JournalEntryValidator::validate($data);
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
        JournalEntryValidator::validate($data);
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
        JournalEntryValidator::validate($data);
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
        JournalEntryValidator::validate($data);
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
        JournalEntryValidator::validate($data);
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
        JournalEntryValidator::validate($data);
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
        JournalEntryValidator::validate($data);
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
        JournalEntryValidator::validate($data);
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
        JournalEntryValidator::validate($data);
    }
}
