<?php

namespace Tests\Feature;

use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\DepreciationService;
use App\Services\FiscalYearCloser;
use App\Services\TransactionRegistrar;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FiscalYearCloserTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 締め前チェックで残タスクを検出できる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => '締め前チェック事業体',
        ]);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $cash = $unit->getSubAccountByName('現金', '現金');
        $ownerLoan = $unit->getSubAccountByName('事業主借', '事業主借');

        (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-04-01',
            'description' => '予定取引',
            'is_planned' => true,
        ], [
            ['sub_account_id' => $cash->id, 'type' => JournalEntry::TYPE_DEBIT, 'net_amount' => 1000],
            ['sub_account_id' => $ownerLoan->id, 'type' => JournalEntry::TYPE_CREDIT, 'net_amount' => 1000],
        ], $fiscalYear->businessUnit->user);

        $fiscalYear->registerOpeningEntry([
            [
                'account_name' => '棚卸資産',
                'sub_account_name' => '棚卸資産',
                'amount' => 1000,
            ],
        ], $user);

        $assetAccount = $unit->getAccountByName('車両運搬具');

        FixedAsset::factory()->create([
            'business_unit_id' => $unit->id,
            'account_id' => $assetAccount->id,
            'name' => '未計上の固定資産',
            'acquisition_date' => '2025-03-01',
            'taxable_amount' => 1_200_000,
            'tax_amount' => 120_000,
            'useful_life' => 72,
        ]);

        app(DepreciationService::class)->prepareEntriesFor($fiscalYear);

        FixedAsset::factory()->create([
            'business_unit_id' => $unit->id,
            'account_id' => $assetAccount->id,
            'name' => '未準備の固定資産',
            'acquisition_date' => '2025-02-01',
            'taxable_amount' => 600_000,
            'tax_amount' => 60_000,
            'useful_life' => 72,
        ]);

        $result = app(FiscalYearCloser::class)->validate($fiscalYear);

        $this->assertFalse($result['closable']);
        $this->assertSame(3, count($result['errors']));
        $this->assertSame(1, count($result['warnings']));

        $errorKeys = collect($result['errors'])->pluck('key')->all();

        $this->assertContains('planned_transactions_remaining', $errorKeys);
        $this->assertContains('depreciation_entries_not_prepared', $errorKeys);
        $this->assertContains('depreciation_entries_unposted', $errorKeys);
        $this->assertSame('inventory_transfer_missing', $result['warnings'][0]['key']);
    }

    #[Test]
    public function 締めると監査情報を含めて決算済みになる()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => '締め実行事業体',
        ]);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $closedFiscalYear = app(FiscalYearCloser::class)->close($fiscalYear, $user);

        $this->assertTrue($closedFiscalYear->is_closed);
        $this->assertFalse($closedFiscalYear->is_active);
        $this->assertSame($user->id, $closedFiscalYear->closed_by);
        $this->assertNotNull($closedFiscalYear->closed_at);
        $this->assertDatabaseHas('fiscal_years', [
            'id' => $fiscalYear->id,
            'is_closed' => true,
            'is_active' => false,
            'closed_by' => $user->id,
        ]);
    }

    #[Test]
    public function 締め前チェックでエラーがあるとcloseできない()
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults([
            'name' => '締め失敗事業体',
        ]);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $cash = $unit->getSubAccountByName('現金', '現金');
        $ownerLoan = $unit->getSubAccountByName('事業主借', '事業主借');

        (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-04-01',
            'description' => '予定取引',
            'is_planned' => true,
        ], [
            ['sub_account_id' => $cash->id, 'type' => JournalEntry::TYPE_DEBIT, 'net_amount' => 1000],
            ['sub_account_id' => $ownerLoan->id, 'type' => JournalEntry::TYPE_CREDIT, 'net_amount' => 1000],
        ], $fiscalYear->businessUnit->user);

        $this->expectException(ValidationException::class);

        app(FiscalYearCloser::class)->close($fiscalYear, $user);
    }

    #[Test]
    public function 他ユーザーは会計年度を決算できない(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '決算認可テスト']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $this->expectException(AuthorizationException::class);

        app(FiscalYearCloser::class)->close($fiscalYear, $otherUser);
    }
}
