<?php

namespace Tests\Feature;

use App\Models\BusinessUnit;
use App\Models\SubAccount;
use App\Models\User;
use App\Services\TransactionRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FiscalYearBalanceTest extends TestCase
{
    use RefreshDatabase;

    private function subAccountByName(BusinessUnit $unit, string $accountName): ?SubAccount
    {
        return $unit->subAccounts()
            ->whereHas('account', fn ($query) => $query->where('name', $accountName))
            ->first();
    }

    /**
     * @param  array<int, array<string, mixed>>  $accounts
     * @return array<string, mixed>
     */
    private function accountByName(array $accounts, string $accountName): array
    {
        foreach ($accounts as $account) {
            if ($account['account_name'] === $accountName) {
                return $account;
            }
        }

        $this->fail("account {$accountName} が見つかりませんでした。");
    }

    #[Test]
    #[Group('mysql')]
    public function 資産負債資本の残高を勘定科目と補助科目単位で集計できる(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '残高テスト事業体']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $cash = $this->subAccountByName($unit, '現金');
        $deposit = $this->subAccountByName($unit, 'その他の預金');
        $ownerLoan = $this->subAccountByName($unit, '事業主借');
        $ownerDraw = $this->subAccountByName($unit, '事業主貸');

        $registrar = new TransactionRegistrar;

        $registrar->register($fiscalYear, [
            'date' => '2025-04-01',
            'description' => '現金の増加',
        ], [
            ['sub_account_id' => $cash->id, 'type' => 'debit', 'gross_amount' => 1100],
            ['sub_account_id' => $ownerLoan->id, 'type' => 'credit', 'gross_amount' => 1100],
        ]);

        $registrar->register($fiscalYear, [
            'date' => '2025-04-02',
            'description' => '預金の増加',
        ], [
            ['sub_account_id' => $deposit->id, 'type' => 'debit', 'gross_amount' => 2200],
            ['sub_account_id' => $ownerLoan->id, 'type' => 'credit', 'gross_amount' => 2200],
        ]);

        $registrar->register($fiscalYear, [
            'date' => '2025-04-03',
            'description' => '事業主貸の計上',
        ], [
            ['sub_account_id' => $ownerDraw->id, 'type' => 'debit', 'gross_amount' => 500],
            ['sub_account_id' => $cash->id, 'type' => 'credit', 'gross_amount' => 500],
        ]);

        $summary = $fiscalYear->calculateBalanceSummary();

        $cashAccount = $this->accountByName($summary['asset']['accounts'], '現金');
        $depositAccount = $this->accountByName($summary['asset']['accounts'], 'その他の預金');
        $ownerLoanAccount = $this->accountByName($summary['equity']['accounts'], '事業主借');
        $ownerDrawAccount = $this->accountByName($summary['equity']['accounts'], '事業主貸');

        $this->assertSame(2800, $summary['asset']['total_balance']);
        $this->assertSame(600, $cashAccount['balance']);
        $this->assertSame(600, $cashAccount['sub_accounts'][0]['balance']);
        $this->assertSame(2200, $depositAccount['balance']);
        $this->assertSame(2200, $depositAccount['sub_accounts'][0]['balance']);

        $this->assertSame(2800, $summary['equity']['total_balance']);
        $this->assertSame(3300, $ownerLoanAccount['balance']);
        $this->assertSame(3300, $ownerLoanAccount['sub_accounts'][0]['balance']);
        $this->assertSame(-500, $ownerDrawAccount['balance']);
        $this->assertSame(-500, $ownerDrawAccount['sub_accounts'][0]['balance']);

        $this->assertSame(0, $summary['liability']['total_balance']);
        $this->assertSame([], $summary['liability']['accounts']);
    }

    #[Test]
    #[Group('mysql')]
    public function 予定取引と無効化済み取引は残高集計に含まれない(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '除外テスト事業体']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $cash = $this->subAccountByName($unit, '現金');
        $ownerLoan = $this->subAccountByName($unit, '事業主借');
        $ownerDraw = $this->subAccountByName($unit, '事業主貸');

        $registrar = new TransactionRegistrar;

        $active = $registrar->register($fiscalYear, [
            'date' => '2025-04-01',
            'description' => '有効な現金増加',
        ], [
            ['sub_account_id' => $cash->id, 'type' => 'debit', 'gross_amount' => 1000],
            ['sub_account_id' => $ownerLoan->id, 'type' => 'credit', 'gross_amount' => 1000],
        ]);

        $planned = $registrar->register($fiscalYear, [
            'date' => '2025-04-02',
            'description' => '予定取引',
            'is_planned' => true,
        ], [
            ['sub_account_id' => $cash->id, 'type' => 'debit', 'gross_amount' => 2000],
            ['sub_account_id' => $ownerLoan->id, 'type' => 'credit', 'gross_amount' => 2000],
        ]);

        $inactive = $registrar->register($fiscalYear, [
            'date' => '2025-04-03',
            'description' => '無効化対象',
        ], [
            ['sub_account_id' => $ownerDraw->id, 'type' => 'debit', 'gross_amount' => 700],
            ['sub_account_id' => $cash->id, 'type' => 'credit', 'gross_amount' => 700],
        ]);

        $inactive->deactivate($user, '誤登録');

        $summary = $fiscalYear->calculateBalanceSummary();

        $cashAccount = $this->accountByName($summary['asset']['accounts'], '現金');
        $ownerLoanAccount = $this->accountByName($summary['equity']['accounts'], '事業主借');
        $this->assertSame(1000, $summary['asset']['total_balance']);
        $this->assertSame(1000, $cashAccount['balance']);
        $this->assertSame(1000, $cashAccount['sub_accounts'][0]['balance']);

        $this->assertSame(1000, $ownerLoanAccount['balance']);
        $this->assertSame(1000, $ownerLoanAccount['sub_accounts'][0]['balance']);
        $this->assertFalse(collect($summary['equity']['accounts'])->contains(fn (array $account): bool => $account['account_name'] === '事業主貸'));

        $this->assertTrue($active->fresh()->is_active);
        $this->assertTrue($planned->fresh()->is_planned);
        $this->assertFalse($inactive->fresh()->is_active);
    }

    #[Test]
    #[Group('mysql')]
    public function 期首仕訳の残高が集計に含まれる(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '期首テスト事業体']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $fiscalYear->registerOpeningEntry([
            ['account_name' => '現金', 'sub_account_name' => '現金', 'amount' => 100000],
            ['account_name' => 'その他の預金', 'sub_account_name' => '普通預金', 'amount' => 300000],
        ], $user);

        $summary = $fiscalYear->calculateBalanceSummary();

        $cashAccount = $this->accountByName($summary['asset']['accounts'], '現金');
        $depositAccount = $this->accountByName($summary['asset']['accounts'], 'その他の預金');
        $capitalAccount = $this->accountByName($summary['equity']['accounts'], '元入金');

        $this->assertSame(400000, $summary['asset']['total_balance']);
        $this->assertSame(100000, $cashAccount['balance']);
        $this->assertSame(300000, $depositAccount['balance']);
        $this->assertSame('普通預金', $depositAccount['sub_accounts'][0]['sub_account_name']);

        $this->assertSame(400000, $summary['equity']['total_balance']);
        $this->assertSame(400000, $capitalAccount['balance']);
    }

    #[Test]
    #[Group('mysql')]
    public function 負債の残高は貸方残高を正として返す(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '負債テスト事業体']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $cash = $this->subAccountByName($unit, '現金');
        $loan = $this->subAccountByName($unit, '借入金');

        $registrar = new TransactionRegistrar;

        $registrar->register($fiscalYear, [
            'date' => '2025-04-01',
            'description' => '借入による現金増加',
        ], [
            ['sub_account_id' => $cash->id, 'type' => 'debit', 'gross_amount' => 3000],
            ['sub_account_id' => $loan->id, 'type' => 'credit', 'gross_amount' => 3000],
        ]);

        $summary = $fiscalYear->calculateBalanceSummary();

        $loanAccount = $this->accountByName($summary['liability']['accounts'], '借入金');

        $this->assertSame(3000, $summary['liability']['total_balance']);
        $this->assertSame(3000, $loanAccount['balance']);
        $this->assertSame(3000, $loanAccount['sub_accounts'][0]['balance']);
        $this->assertSame(3000, $summary['asset']['total_balance']);
    }

    #[Test]
    #[Group('mysql')]
    public function 棚卸振替科目は貸借対照表に含まれず棚卸資産の残高のみ反映される(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '棚卸残高テスト事業体']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $openingInventory = $this->subAccountByName($unit, '期首商品（棚卸高）');
        $closingInventory = $this->subAccountByName($unit, '期末商品（棚卸高）');
        $inventoryAsset = $this->subAccountByName($unit, '棚卸資産');

        $registrar = new TransactionRegistrar;

        $registrar->register($fiscalYear, [
            'date' => '2025-01-01',
            'description' => '期首棚卸の振替',
        ], [
            ['sub_account_id' => $openingInventory->id, 'type' => 'debit', 'gross_amount' => 1000],
            ['sub_account_id' => $inventoryAsset->id, 'type' => 'credit', 'gross_amount' => 1000],
        ]);

        $registrar->register($fiscalYear, [
            'date' => '2025-12-31',
            'description' => '期末棚卸の振替',
        ], [
            ['sub_account_id' => $inventoryAsset->id, 'type' => 'debit', 'gross_amount' => 400],
            ['sub_account_id' => $closingInventory->id, 'type' => 'credit', 'gross_amount' => 400],
        ]);

        $summary = $fiscalYear->calculateBalanceSummary();

        $inventoryAssetAccount = $this->accountByName($summary['asset']['accounts'], '棚卸資産');

        $this->assertSame(-600, $summary['asset']['total_balance']);
        $this->assertSame(-600, $inventoryAssetAccount['balance']);
        $this->assertSame(-600, $inventoryAssetAccount['sub_accounts'][0]['balance']);

        $allAccountNames = collect($summary)
            ->flatMap(fn (array $typeSummary): array => $typeSummary['accounts'])
            ->pluck('account_name');

        $this->assertFalse($allAccountNames->contains('期首商品（棚卸高）'));
        $this->assertFalse($allAccountNames->contains('期末商品（棚卸高）'));
    }

    #[Test]
    #[Group('mysql')]
    public function 取引がない年度では全タイプが空で返る(): void
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '空データテスト事業体']);
        $fiscalYear = $unit->createFiscalYear(2025, $user);

        $summary = $fiscalYear->calculateBalanceSummary();

        $this->assertSame([
            'asset' => ['total_balance' => 0, 'accounts' => []],
            'liability' => ['total_balance' => 0, 'accounts' => []],
            'equity' => ['total_balance' => 0, 'accounts' => []],
        ], $summary);
    }
}
