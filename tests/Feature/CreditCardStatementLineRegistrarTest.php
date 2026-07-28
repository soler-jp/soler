<?php

namespace Tests\Feature;

use App\Models\CreditCard;
use App\Models\CreditCardImportBatch;
use App\Models\CreditCardStatement;
use App\Models\CreditCardStatementLine;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreditCardStatementLineRegistrarTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 未レビューの明細から取引を登録できる(): void
    {
        [$user, $line, $expenseSubAccount, $creditSubAccount] = $this->createRegisterableLine();

        $transaction = $line->registerTransaction([
            'debit_sub_account_id' => $expenseSubAccount->id,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            'description' => 'Amazonで文房具購入',
        ], $user);

        $this->assertSame(CreditCardStatementLine::STATUS_REGISTERED, $line->fresh()->status);
        $this->assertSame($transaction->id, $line->fresh()->transaction_id);
        $this->assertSame($user->id, $line->fresh()->reviewed_by);
        $this->assertSame('Amazonで文房具購入', $transaction->description);
        $this->assertSame($line->credit_card_import_batch_id, $transaction->credit_card_import_batch_id);
        $this->assertSame($user->id, $transaction->created_by);

        $debitEntry = $transaction->journalEntries->firstWhere('type', JournalEntry::TYPE_DEBIT);
        $creditEntry = $transaction->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT);

        $this->assertSame($expenseSubAccount->id, $debitEntry->sub_account_id);
        $this->assertSame($creditSubAccount->id, $creditEntry->sub_account_id);
        $this->assertStringContainsString('カード明細:', $transaction->remarks ?? '');
    }

    #[Test]
    public function used_onがない明細は登録できない(): void
    {
        [$user, $line, $expenseSubAccount] = $this->createRegisterableLine([
            'used_on' => null,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('利用日が設定された明細行だけを登録できます。');

        $line->registerTransaction([
            'debit_sub_account_id' => $expenseSubAccount->id,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
        ], $user);
    }

    #[Test]
    public function 決算済み年度の明細は登録できない(): void
    {
        [$user, $line, $expenseSubAccount] = $this->createRegisterableLine([
            'used_on' => '2025-06-10',
        ]);

        $line->statement->creditCard->businessUnit->fiscalYears()
            ->where('year', 2025)
            ->update(['is_closed' => true]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('決算済みの会計年度に属する明細行は登録できません。');

        $line->registerTransaction([
            'debit_sub_account_id' => $expenseSubAccount->id,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
        ], $user);
    }

    #[Test]
    public function 個人カードではowner_draw_sub_account_idを貸方に使う(): void
    {
        [$user, $line, $expenseSubAccount] = $this->createRegisterableLine([], CreditCard::OWNERSHIP_TYPE_PERSONAL);

        $creditCard = $line->statement->creditCard;
        $businessUnit = $creditCard->businessUnit;
        $liabilitySubAccount = $businessUnit->getAccountByName('未払金')->subAccounts()->firstOrFail();
        $ownerDrawSubAccount = $businessUnit->getAccountByName('事業主借')->subAccounts()->firstOrFail();

        $creditCard->forceFill([
            'liability_sub_account_id' => $liabilitySubAccount->id,
            'owner_draw_sub_account_id' => $ownerDrawSubAccount->id,
        ])->save();

        $transaction = $line->registerTransaction([
            'debit_sub_account_id' => $expenseSubAccount->id,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
        ], $user);

        $creditEntry = $transaction->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT);

        $this->assertSame($ownerDrawSubAccount->id, $creditEntry->sub_account_id);
        $this->assertNotSame($liabilitySubAccount->id, $creditEntry->sub_account_id);
    }

    #[Test]
    public function 事業用カードではliability_sub_account_idを貸方に使う(): void
    {
        [$user, $line, $expenseSubAccount] = $this->createRegisterableLine();

        $creditCard = $line->statement->creditCard;
        $businessUnit = $creditCard->businessUnit;
        $liabilitySubAccount = $businessUnit->getAccountByName('未払金')->subAccounts()->firstOrFail();
        $ownerDrawSubAccount = $businessUnit->getAccountByName('事業主借')->subAccounts()->firstOrFail();

        $creditCard->forceFill([
            'liability_sub_account_id' => $liabilitySubAccount->id,
            'owner_draw_sub_account_id' => $ownerDrawSubAccount->id,
        ])->save();

        $transaction = $line->registerTransaction([
            'debit_sub_account_id' => $expenseSubAccount->id,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
        ], $user);

        $creditEntry = $transaction->journalEntries->firstWhere('type', JournalEntry::TYPE_CREDIT);

        $this->assertSame($liabilitySubAccount->id, $creditEntry->sub_account_id);
        $this->assertNotSame($ownerDrawSubAccount->id, $creditEntry->sub_account_id);
    }

    #[Test]
    public function 事業割合が100未満なら家事按分行が追加される(): void
    {
        [$user, $line, $expenseSubAccount] = $this->createRegisterableLine([
            'amount' => 11_000,
        ]);

        $transaction = $line->registerTransaction([
            'debit_sub_account_id' => $expenseSubAccount->id,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            'business_ratio' => 60,
        ], $user);

        $businessEntry = $transaction->journalEntries->firstWhere('business_ratio', 60);
        $householdEntry = $transaction->journalEntries
            ->where('type', JournalEntry::TYPE_DEBIT)
            ->firstWhere('business_ratio', null);

        $this->assertNotNull($businessEntry);
        $this->assertNotNull($householdEntry);
        $this->assertSame(6_000, $businessEntry->net_amount);
        $this->assertSame(600, $businessEntry->tax_amount);
        $this->assertSame(4_400, $householdEntry->net_amount);
    }

    #[Test]
    public function 非費用科目に事業割合を指定すると登録できない(): void
    {
        [$user, $line] = $this->createRegisterableLine();
        $assetSubAccount = $line->statement->creditCard->businessUnit
            ->getAccountByName('現金')
            ->subAccounts()
            ->firstOrFail();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('事業割合は借方の費用科目でのみ指定できます。');

        $line->registerTransaction([
            'debit_sub_account_id' => $assetSubAccount->id,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            'business_ratio' => 60,
        ], $user);
    }

    #[Test]
    public function 取込バッチのない明細は登録できない(): void
    {
        [$user, $line, $expenseSubAccount] = $this->createRegisterableLine([
            'credit_card_import_batch_id' => null,
        ], mutateBatch: false);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('取込バッチに紐づかない明細行は登録できません。');

        $line->registerTransaction([
            'debit_sub_account_id' => $expenseSubAccount->id,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
        ], $user);
    }

    #[Test]
    public function 金額ゼロの明細は登録できない(): void
    {
        [$user, $line, $expenseSubAccount] = $this->createRegisterableLine([
            'amount' => 0,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('0円以下の明細行は登録できません。');

        $line->registerTransaction([
            'debit_sub_account_id' => $expenseSubAccount->id,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
        ], $user);
    }

    #[Test]
    public function 登録取消で明細を未レビューに戻し取引を無効化できる(): void
    {
        [$user, $line, $expenseSubAccount] = $this->createRegisterableLine();

        $transaction = $line->registerTransaction([
            'debit_sub_account_id' => $expenseSubAccount->id,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
        ], $user);

        $line->cancelTransactionRegistration($user, '誤登録のためやり直し');

        $this->assertSame(CreditCardStatementLine::STATUS_UNREVIEWED, $line->fresh()->status);
        $this->assertNull($line->fresh()->transaction_id);
        $this->assertSame('誤登録のためやり直し', $line->fresh()->memo);
        $this->assertFalse($transaction->fresh()->is_active);
        $this->assertSame('誤登録のためやり直し', $transaction->fresh()->deactivation_reason);
    }

    #[Test]
    #[Group('mysql')]
    public function 登録済み明細は二重登録できない(): void
    {
        [$user, $line, $expenseSubAccount] = $this->createRegisterableLine();

        $line->registerTransaction([
            'debit_sub_account_id' => $expenseSubAccount->id,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
        ], $user);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('未レビューの明細行だけを登録できます。');

        $line->registerTransaction([
            'debit_sub_account_id' => $expenseSubAccount->id,
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
        ], $user);
    }

    private function createRegisterableLine(
        array $lineOverrides = [],
        string $ownershipType = CreditCard::OWNERSHIP_TYPE_BUSINESS,
        bool $mutateBatch = true,
    ): array {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => 'カード登録テスト事業']);
        $unit->createFiscalYear(2025);
        $unit->createFiscalYear(2026);

        $cardSubAccount = $ownershipType === CreditCard::OWNERSHIP_TYPE_BUSINESS
            ? $unit->getAccountByName('未払金')->subAccounts()->firstOrFail()
            : $unit->getAccountByName('事業主借')->subAccounts()->firstOrFail();

        $creditCard = CreditCard::factory()->create([
            'business_unit_id' => $unit->id,
            'ownership_type' => $ownershipType,
            'liability_sub_account_id' => $ownershipType === CreditCard::OWNERSHIP_TYPE_BUSINESS ? $cardSubAccount->id : null,
            'owner_draw_sub_account_id' => $ownershipType === CreditCard::OWNERSHIP_TYPE_PERSONAL ? $cardSubAccount->id : null,
        ]);

        $statement = CreditCardStatement::factory()->create([
            'credit_card_id' => $creditCard->id,
            'statement_year' => 2025,
            'statement_month' => 6,
        ]);

        $batch = CreditCardImportBatch::factory()->create([
            'credit_card_statement_id' => $statement->id,
            'uploaded_by' => $user->id,
        ]);

        $line = CreditCardStatementLine::factory()->create(array_merge([
            'credit_card_statement_id' => $statement->id,
            'credit_card_import_batch_id' => $mutateBatch ? $batch->id : null,
            'used_on' => '2025-06-15',
            'merchant_name' => 'AMAZON',
            'description' => 'AMAZON MARKETPLACE',
            'amount' => 1_100,
            'status' => CreditCardStatementLine::STATUS_UNREVIEWED,
            'is_active' => true,
        ], $lineOverrides));

        $expenseSubAccount = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();

        return [$user, $line, $expenseSubAccount, $cardSubAccount];
    }
}
