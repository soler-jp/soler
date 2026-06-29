<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\CreditCard;
use App\Models\CreditCardImportBatch;
use App\Models\CreditCardStatement;
use App\Models\CreditCardStatementLine;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreditCardModelsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 事業体に紐づくクレジットカードを作成できる(): void
    {
        $businessUnit = BusinessUnit::factory()->create();

        $creditCard = CreditCard::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'issuer_name' => 'Orico',
            'network' => 'visa',
            'last_four' => '8765',
        ]);

        $this->assertTrue($businessUnit->creditCards->contains($creditCard));
        $this->assertTrue($creditCard->requiresFullRegistration());
        $this->assertSame('Orico VISA ****8765', $creditCard->display_label);
    }

    #[Test]
    public function 個人カード明細行は私用扱いで保持できる(): void
    {
        $businessUnit = BusinessUnit::factory()->create();

        $creditCard = CreditCard::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'ownership_type' => CreditCard::OWNERSHIP_TYPE_PERSONAL,
        ]);

        $statement = CreditCardStatement::factory()->create([
            'credit_card_id' => $creditCard->id,
        ]);

        $line = CreditCardStatementLine::factory()->create([
            'credit_card_statement_id' => $statement->id,
            'status' => CreditCardStatementLine::STATUS_PRIVATE,
        ]);

        $this->assertFalse($line->isRegistered());
        $this->assertFalse($line->isReviewPending());
        $this->assertTrue($line->isResolved());
    }

    #[Test]
    public function 未レビュー行が残る明細は完了できない(): void
    {
        $businessUnit = BusinessUnit::factory()->create();
        $creditCard = CreditCard::factory()->create([
            'business_unit_id' => $businessUnit->id,
            'ownership_type' => CreditCard::OWNERSHIP_TYPE_BUSINESS,
        ]);

        $statement = CreditCardStatement::factory()->create([
            'credit_card_id' => $creditCard->id,
        ]);

        CreditCardStatementLine::factory()->create([
            'credit_card_statement_id' => $statement->id,
            'status' => CreditCardStatementLine::STATUS_REGISTERED,
        ]);

        CreditCardStatementLine::factory()->create([
            'credit_card_statement_id' => $statement->id,
            'status' => CreditCardStatementLine::STATUS_UNREVIEWED,
        ]);

        $this->assertFalse($statement->canBeCompleted());
        $this->assertSame(CreditCardStatement::STATUS_REVIEWING, $statement->fresh()->computed_status);
    }

    #[Test]
    public function 明細の進捗状態を明細行から自動算出できる(): void
    {
        $statement = CreditCardStatement::factory()->create();

        $this->assertSame(CreditCardStatement::STATUS_EMPTY, $statement->computed_status);

        CreditCardStatementLine::factory()->create([
            'credit_card_statement_id' => $statement->id,
            'status' => CreditCardStatementLine::STATUS_UNREVIEWED,
        ]);

        $this->assertSame(CreditCardStatement::STATUS_IMPORTED, $statement->fresh()->computed_status);

        CreditCardStatementLine::factory()->create([
            'credit_card_statement_id' => $statement->id,
            'status' => CreditCardStatementLine::STATUS_REGISTERED,
        ]);

        $this->assertSame(CreditCardStatement::STATUS_REVIEWING, $statement->fresh()->computed_status);

        CreditCardStatementLine::query()
            ->where('credit_card_statement_id', $statement->id)
            ->where('status', CreditCardStatementLine::STATUS_UNREVIEWED)
            ->update(['status' => CreditCardStatementLine::STATUS_PRIVATE]);

        $this->assertSame(CreditCardStatement::STATUS_COMPLETED, $statement->fresh()->computed_status);
    }

    #[Test]
    public function 取込バッチと登録済み取引を明細行に関連付けできる(): void
    {
        $user = User::factory()->create();
        $businessUnit = BusinessUnit::factory()->create([
            'user_id' => $user->id,
        ]);
        $creditCard = CreditCard::factory()->create([
            'business_unit_id' => $businessUnit->id,
        ]);
        $statement = CreditCardStatement::factory()->create([
            'credit_card_id' => $creditCard->id,
        ]);
        $batch = CreditCardImportBatch::factory()->create([
            'credit_card_statement_id' => $statement->id,
            'uploaded_by' => $user->id,
        ]);
        $transaction = Transaction::factory()->create([
            'credit_card_import_batch_id' => $batch->id,
        ]);

        $line = CreditCardStatementLine::factory()->create([
            'credit_card_statement_id' => $statement->id,
            'credit_card_import_batch_id' => $batch->id,
            'transaction_id' => $transaction->id,
            'reviewed_by' => $user->id,
            'status' => CreditCardStatementLine::STATUS_REGISTERED,
        ]);

        $this->assertTrue($batch->isFinished());
        $this->assertTrue($line->isResolved());
        $this->assertTrue($batch->lines->contains($line));
        $this->assertTrue($transaction->creditCardStatementLines->contains($line));
    }

    #[Test]
    public function バッチを無効化すると関連明細行と取引も無効化される(): void
    {
        $user = User::factory()->create();
        $statement = CreditCardStatement::factory()->create();
        $batch = CreditCardImportBatch::factory()->create([
            'credit_card_statement_id' => $statement->id,
        ]);
        $transaction = Transaction::factory()->create([
            'credit_card_import_batch_id' => $batch->id,
        ]);
        $line = CreditCardStatementLine::factory()->create([
            'credit_card_statement_id' => $statement->id,
            'credit_card_import_batch_id' => $batch->id,
            'transaction_id' => $transaction->id,
            'status' => CreditCardStatementLine::STATUS_REGISTERED,
        ]);

        $batch->deactivate($user, 'CSV修正版を再アップロード');

        $this->assertFalse($batch->fresh()->is_active);
        $this->assertFalse($line->fresh()->is_active);
        $this->assertFalse($transaction->fresh()->is_active);
        $this->assertSame($user->id, $batch->fresh()->deactivated_by);
        $this->assertSame('CSV修正版を再アップロード', $transaction->fresh()->deactivation_reason);
    }

    #[Test]
    public function 明細の進捗計算はactiveな明細行だけを見る(): void
    {
        $statement = CreditCardStatement::factory()->create();

        CreditCardStatementLine::factory()->create([
            'credit_card_statement_id' => $statement->id,
            'status' => CreditCardStatementLine::STATUS_UNREVIEWED,
            'is_active' => false,
        ]);

        $this->assertSame(CreditCardStatement::STATUS_EMPTY, $statement->fresh()->computed_status);
    }
}
