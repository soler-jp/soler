<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\SubAccount;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class JournalEntryTest extends TestCase
{
    private function getValidator(array $data)
    {
        return Validator::make($data, [
            'transaction_id' => ['required', 'exists:transactions,id'],
            'account_id' => ['required', 'exists:accounts,id'],
            'sub_account_id' => ['nullable', 'exists:sub_accounts,id'],
            'type' => ['required', 'in:debit,credit'],
            'amount' => ['required', 'integer', 'min:1'],
            'tax_amount' => ['nullable', 'integer', 'min:0'],
            'tax_type' => ['nullable', 'in:taxable_sales_10,taxable_sales_8,taxable_purchases_10,non_taxable,tax_free'],
            'is_effective' => ['boolean'],
        ]);
    }

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
        $this->assertTrue($this->getValidator($data)->passes());
    }

    #[Test]
    public function transaction_idがnullの場合はバリデーションエラー()
    {
        Account::factory()->create();
        $data = ['transaction_id' => null, 'account_id' => 1, 'type' => 'debit', 'amount' => 1000];
        $this->assertTrue($this->getValidator($data)->fails());
    }

    #[Test]
    public function account_idがnullの場合はバリデーションエラー()
    {
        Transaction::factory()->create();
        $data = ['transaction_id' => 1, 'account_id' => null, 'type' => 'debit', 'amount' => 1000];
        $this->assertTrue($this->getValidator($data)->fails());
    }

    #[Test]
    public function typeがnullの場合はバリデーションエラー()
    {
        $transaction = Transaction::factory()->create();
        $account = Account::factory()->create();
        $data = ['transaction_id' => $transaction->id, 'account_id' => $account->id, 'type' => null, 'amount' => 1000];
        $this->assertTrue($this->getValidator($data)->fails());
    }

    #[Test]
    public function amountがnullの場合はバリデーションエラー()
    {
        $transaction = Transaction::factory()->create();
        $account = Account::factory()->create();
        $data = ['transaction_id' => $transaction->id, 'account_id' => $account->id, 'type' => 'debit', 'amount' => null];
        $this->assertTrue($this->getValidator($data)->fails());
    }

    #[Test]
    public function typeが不正な値の場合はバリデーションエラー()
    {
        $transaction = Transaction::factory()->create();
        $account = Account::factory()->create();
        $data = ['transaction_id' => $transaction->id, 'account_id' => $account->id, 'type' => 'invalid', 'amount' => 1000];
        $this->assertTrue($this->getValidator($data)->fails());
    }

    #[Test]
    public function amountが0円以下の場合はバリデーションエラー()
    {
        $transaction = Transaction::factory()->create();
        $account = Account::factory()->create();
        $data = ['transaction_id' => $transaction->id, 'account_id' => $account->id, 'type' => 'debit', 'amount' => 0];
        $this->assertTrue($this->getValidator($data)->fails());
    }

    #[Test]
    public function tax_amountがマイナスの場合はバリデーションエラー()
    {
        $transaction = Transaction::factory()->create();
        $account = Account::factory()->create();
        $data = ['transaction_id' => $transaction->id, 'account_id' => $account->id, 'type' => 'debit', 'amount' => 1000, 'tax_amount' => -100];
        $this->assertTrue($this->getValidator($data)->fails());
    }

    #[Test]
    public function tax_typeが不正な値の場合はバリデーションエラー()
    {
        $transaction = Transaction::factory()->create();
        $account = Account::factory()->create();
        $data = ['transaction_id' => $transaction->id, 'account_id' => $account->id, 'type' => 'debit', 'amount' => 1000, 'tax_type' => 'wrong'];
        $this->assertTrue($this->getValidator($data)->fails());
    }

    #[Test]
    public function is_effectiveに真偽値以外を渡すとバリデーションエラー()
    {
        $transaction = Transaction::factory()->create();
        $account = Account::factory()->create();
        $data = ['transaction_id' => $transaction->id, 'account_id' => $account->id, 'type' => 'debit', 'amount' => 1000, 'is_effective' => 'yes'];
        $this->assertTrue($this->getValidator($data)->fails());
    }

    #[Test]
    public function sub_account_idがnullでもバリデーションが通る()
    {
        $transaction = Transaction::factory()->create();
        $account = Account::factory()->create();
        $data = ['transaction_id' => $transaction->id, 'account_id' => $account->id, 'type' => 'debit', 'amount' => 1000, 'sub_account_id' => null];
        $this->assertTrue($this->getValidator($data)->passes());
    }

    #[Test]
    public function tax_amountがnullでもバリデーションが通る()
    {
        $transaction = Transaction::factory()->create();
        $account = Account::factory()->create();
        $data = ['transaction_id' => $transaction->id, 'account_id' => $account->id, 'type' => 'debit', 'amount' => 1000, 'tax_amount' => null];
        $this->assertTrue($this->getValidator($data)->passes());
    }

    #[Test]
    public function tax_typeがnullでもバリデーションが通る()
    {
        $transaction = Transaction::factory()->create();
        $account = Account::factory()->create();
        $data = ['transaction_id' => $transaction->id, 'account_id' => $account->id, 'type' => 'debit', 'amount' => 1000, 'tax_type' => null];
        $this->assertTrue($this->getValidator($data)->passes());
    }

    #[Test]
    public function is_effectiveが省略された場合でもバリデーションが通る()
    {
        $transaction = Transaction::factory()->create();
        $account = Account::factory()->create();
        $data = ['transaction_id' => $transaction->id, 'account_id' => $account->id, 'type' => 'debit', 'amount' => 1000];
        $this->assertTrue($this->getValidator($data)->passes());
    }
}
