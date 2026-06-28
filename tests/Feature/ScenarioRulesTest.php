<?php

namespace Tests\Feature;

use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\TransactionRegistrar;
use App\Setup\Initializers\GeneralBusinessInitializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScenarioRulesTest extends TestCase
{
    use RefreshDatabase;

    public static function unsupportedInitializationProfiles(): array
    {
        return [
            'non-taxable-exclusive' => [false, true],
            'taxable-exclusive' => [true, true],
        ];
    }

    #[Test]
    #[DataProvider('unsupportedInitializationProfiles')]
    public function シナリオ_初期セットアップは税別経理フラグを拒否する(bool $isTaxable, bool $isTaxExclusive): void
    {
        $user = User::factory()->create();
        $this->assertDatabaseCount('business_units', 0);
        $this->assertDatabaseCount('fiscal_years', 0);

        try {
            (new GeneralBusinessInitializer)->initialize($user, [
                'name' => 'シナリオ事業体',
                'type' => 'general',
                'year' => 2026,
                'is_taxable' => $isTaxable,
                'is_tax_exclusive' => $isTaxExclusive,
                'opening_entries' => [],
                'fixed_assets' => [],
                'recurring_templates' => [],
            ]);

            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('税抜経理は対応していません', $exception->getMessage());
        }

        $this->assertDatabaseCount('business_units', 0);
        $this->assertDatabaseCount('fiscal_years', 0);
    }

    #[Test]
    public function シナリオ_決算済み会計年度では新規取引を登録できない(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => '締め済み事業体',
        ]);
        $fiscalYear = $unit->createFiscalYear(2026);
        $fiscalYear->forceFill(['is_closed' => true])->save();

        $debitAccount = $unit->getSubAccountByName('現金', 'レジ現金');
        $creditAccount = $unit->getSubAccountByName('売上高', '一般売上');

        $this->assertNotNull($debitAccount);
        $this->assertNotNull($creditAccount);
        $this->assertDatabaseCount('transactions', 0);

        try {
            (new TransactionRegistrar)->register($fiscalYear, [
                'date' => now()->toDateString(),
                'description' => '決算済み年度の登録',
            ], [
                [
                    'sub_account_id' => $debitAccount->id,
                    'type' => JournalEntry::TYPE_DEBIT,
                    'net_amount' => 1000,
                    'tax_type' => JournalEntry::TAX_TYPE_NON_TAXABLE,
                ],
                [
                    'sub_account_id' => $creditAccount->id,
                    'type' => JournalEntry::TYPE_CREDIT,
                    'net_amount' => 1000,
                    'tax_type' => JournalEntry::TAX_TYPE_NON_TAXABLE,
                ],
            ]);

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('決算済みの会計年度には新規取引を登録できません。', $exception->getMessage());
        }

        $this->assertDatabaseCount('transactions', 0);
    }

    #[Test]
    public function シナリオ_課税事業者は見なし消費税区分の税込入力を登録できない(): void
    {
        $user = User::factory()->create();
        $unit = (new GeneralBusinessInitializer)->initialize($user, [
            'name' => '課税シナリオ事業体',
            'type' => 'general',
            'year' => 2026,
            'is_taxable' => true,
            'is_tax_exclusive' => false,
            'opening_entries' => [],
            'fixed_assets' => [],
            'recurring_templates' => [],
        ]);

        /** @var FiscalYear $fiscalYear */
        $fiscalYear = $unit->fiscalYears()->where('year', 2026)->firstOrFail();
        $expenseSubAccount = $unit->getSubAccountByName('消耗品費', '消耗品費');
        $cashSubAccount = $unit->getSubAccountByName('現金', 'レジ現金');

        $this->assertNotNull($expenseSubAccount);
        $this->assertNotNull($cashSubAccount);
        $this->assertDatabaseCount('transactions', 0);

        try {
            (new TransactionRegistrar)->register($fiscalYear, [
                'date' => '2026-04-01',
                'description' => '見なし税区分の税込入力',
            ], [
                [
                    'sub_account_id' => $expenseSubAccount->id,
                    'type' => JournalEntry::TYPE_DEBIT,
                    'gross_amount' => 1100,
                    'tax_type' => JournalEntry::TAX_TYPE_DEEMED_TAXABLE_PURCHASES_10,
                ],
                [
                    'sub_account_id' => $cashSubAccount->id,
                    'type' => JournalEntry::TYPE_CREDIT,
                    'gross_amount' => 1100,
                    'tax_type' => JournalEntry::TAX_TYPE_NON_TAXABLE,
                ],
            ]);

            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('課税事業者の会計年度では見なし消費税区分は使用できません。', $exception->getMessage());
        }

        $this->assertDatabaseCount('transactions', 0);
    }
}
