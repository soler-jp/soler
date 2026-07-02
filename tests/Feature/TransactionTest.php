<?php

namespace Tests\Feature;

use App\Models\FiscalYear;
use App\Models\Transaction;
use App\Models\User;
use App\Validators\TransactionValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionTest extends TestCase
{
    use RefreshDatabase;

    // ////////
    // Factory のテスト
    // ////////

    #[Test]
    public function factoryで_transactionをmakeできる()
    {
        $transaction = Transaction::factory()->make();

        $this->assertInstanceOf(Transaction::class, $transaction);
        $this->assertNotNull($transaction->fiscal_year_id);
        $this->assertNotNull($transaction->created_by);
    }

    #[Test]
    public function factoryで_transactionをcreateできる()
    {
        $transaction = Transaction::factory()->create();

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
        ]);
    }

    #[Test]
    public function transactionはactiveフラグをbooleanとして扱える()
    {
        $transaction = Transaction::factory()->create([
            'is_active' => false,
        ]);

        $this->assertFalse($transaction->is_active);
    }

    #[Test]
    public function transactionをdeactivateできる()
    {
        $user = User::factory()->create();
        $transaction = Transaction::factory()->create();

        $transaction->deactivate($user, '誤登録のため無効化');

        $transaction->refresh();

        $this->assertFalse($transaction->is_active);
        $this->assertNotNull($transaction->deactivated_at);
        $this->assertSame($user->id, $transaction->deactivated_by);
        $this->assertSame('誤登録のため無効化', $transaction->deactivation_reason);
    }

    #[Test]
    public function 既に無効化済みのtransactionを再度deactivateしても記録は上書きされない()
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $transaction = Transaction::factory()->create();

        $transaction->deactivate($firstUser, '初回の無効化');
        $firstDeactivatedAt = $transaction->fresh()->deactivated_at;

        $transaction->fresh()->deactivate($secondUser, '再無効化');

        $transaction->refresh();

        $this->assertFalse($transaction->is_active);
        $this->assertSame($firstUser->id, $transaction->deactivated_by);
        $this->assertSame('初回の無効化', $transaction->deactivation_reason);
        $this->assertTrue($transaction->deactivated_at?->eq($firstDeactivatedAt));
    }

    // /////////////////////////

    #[Test]
    public function 正しいデータでバリデーションが通る()
    {
        $fiscalYear = FiscalYear::factory()->create();
        $user = User::factory()->create();

        $data = [
            'fiscal_year_id' => $fiscalYear->id,
            'date' => '2025-06-24',
            'description' => '備品購入',
            'remarks' => 'Amazonでプリンタ購入',
            'tax_type' => 'taxable_purchases_10',
            'is_adjusting_entry' => true,
            'created_by' => $user->id,
        ];

        $validated = TransactionValidator::validate($data);

        $this->assertSame($data['description'], $validated['description']);
    }

    #[Test]
    public function fiscal_year_idが無ければバリデーションエラー()
    {
        $this->expectException(ValidationException::class);

        TransactionValidator::validate([
            'date' => '2025-06-24',
            'description' => 'テスト',
        ]);
    }

    #[Test]
    public function created_byはnullでもバリデーションが通る()
    {
        $fy = FiscalYear::factory()->create();

        $validated = TransactionValidator::validate([
            'fiscal_year_id' => $fy->id,
            'date' => now()->toDateString(),
            'description' => '登録者無しの取引',
            'created_by' => null,
        ]);

        $this->assertArrayHasKey('created_by', $validated);
        $this->assertNull($validated['created_by']);
    }

    #[Test]
    #[Group('mysql')]
    public function entry_numberは年度ごとに連番で採番される()
    {
        $fy = FiscalYear::factory()->create();
        $user = User::factory()->create();

        $t1 = Transaction::create([
            'fiscal_year_id' => $fy->id,
            'date' => '2025-06-24',
            'description' => 'A',
            'created_by' => $user->id,
        ]);

        $t2 = Transaction::create([
            'fiscal_year_id' => $fy->id,
            'date' => '2025-06-25',
            'description' => 'B',
            'created_by' => $user->id,
        ]);

        $this->assertEquals(1, $t1->entry_number);
        $this->assertEquals(2, $t2->entry_number);
    }

    #[Test]
    #[Group('mysql')]
    public function entry_numberは別の年度ではリセットされる()
    {
        $user = User::factory()->create();
        $fy1 = FiscalYear::factory()->create(['year' => 2024]);
        $fy2 = FiscalYear::factory()->create(['year' => 2025]);

        $t1 = Transaction::create([
            'fiscal_year_id' => $fy1->id,
            'date' => '2024-06-24',
            'description' => '前年度',
            'created_by' => $user->id,
        ]);

        $t2 = Transaction::create([
            'fiscal_year_id' => $fy2->id,
            'date' => '2025-06-24',
            'description' => '今年度',
            'created_by' => $user->id,
        ]);

        $this->assertEquals(1, $t1->entry_number);
        $this->assertEquals(1, $t2->entry_number); // ← 年度またがり
    }

    #[Test]
    public function display_numberは年度と連番を組み合わせた形式になる()
    {
        $fy = FiscalYear::factory()->create(['year' => 2025]);
        $user = User::factory()->create();

        $t = Transaction::create([
            'fiscal_year_id' => $fy->id,
            'date' => '2025-06-24',
            'description' => '表示番号テスト',
            'created_by' => $user->id,
        ]);

        $this->assertEquals('2025-0001', $t->display_number);
    }
}
