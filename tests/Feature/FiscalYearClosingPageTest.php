<?php

namespace Tests\Feature;

use App\Livewire\FiscalYearClosing\InventoryClosingSection;
use App\Livewire\Pages\FiscalYearClosing;
use App\Models\BusinessUnit;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InventoryClosingService;
use App\Services\TransactionRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FiscalYearClosingPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: BusinessUnit, 2: FiscalYear}
     */
    private function createUserWithFiscalYear(int $year = 2025): array
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '期末処理テスト事業体']);
        $fiscalYear = $unit->createFiscalYear($year, $user);

        return [$user, $unit->refresh(), $fiscalYear];
    }

    private function registerPurchase(FiscalYear $fiscalYear, User $user, int $grossAmount): void
    {
        $unit = $fiscalYear->businessUnit;
        $purchase = $unit->getSubAccountByName('仕入金額', '仕入金額');
        $cash = $unit->getSubAccountByName('現金', '現金');

        (new TransactionRegistrar)->register($fiscalYear, [
            'date' => $fiscalYear->start_date->toDateString(),
            'description' => '仕入',
        ], [
            ['sub_account_id' => $purchase->id, 'type' => JournalEntry::TYPE_DEBIT, 'net_amount' => $grossAmount],
            ['sub_account_id' => $cash->id, 'type' => JournalEntry::TYPE_CREDIT, 'net_amount' => $grossAmount],
        ], $user);
    }

    #[Test]
    public function 期末処理ページに棚卸セクションが埋め込まれる(): void
    {
        [$user] = $this->createUserWithFiscalYear();

        $this->actingAs($user)
            ->get(route('fiscal-year-closing'))
            ->assertOk()
            ->assertSeeLivewire(FiscalYearClosing::class)
            ->assertSeeLivewire(InventoryClosingSection::class)
            ->assertSee('2025年度 期末処理')
            ->assertSee('棚卸');
    }

    #[Test]
    public function 棚卸セクションに期首残高と仕入金額を織り込んだ説明文が表示される(): void
    {
        [$user, , $fiscalYear] = $this->createUserWithFiscalYear();

        $fiscalYear->registerOpeningEntry([
            [
                'account_name' => '棚卸資産',
                'sub_account_name' => '棚卸資産',
                'amount' => 12_345,
            ],
        ], $user);

        $this->registerPurchase($fiscalYear, $user, 67_890);

        Livewire::actingAs($user)
            ->test(InventoryClosingSection::class, ['fiscalYearId' => $fiscalYear->id])
            ->assertSee('年度初めに 12,345 円分の在庫があり、今年は 67,890 円の仕入を行いました。2025年末時点での在庫の残りの金額を入力してください。');
    }

    #[Test]
    public function 期末棚卸高を登録すると決算整理仕訳が作られる(): void
    {
        [$user, $unit, $fiscalYear] = $this->createUserWithFiscalYear();

        $fiscalYear->registerOpeningEntry([
            [
                'account_name' => '棚卸資産',
                'sub_account_name' => '棚卸資産',
                'amount' => 400,
            ],
        ], $user);

        $subAccountId = $unit->getSubAccountByName('棚卸資産', '棚卸資産')->id;

        Livewire::actingAs($user)
            ->test(InventoryClosingSection::class, ['fiscalYearId' => $fiscalYear->id])
            ->set("closingAmounts.$subAccountId", 500)
            ->call('register')
            ->assertSet('errorMessage', null)
            ->assertSet('noticeMessage', __('fiscal_year_closing.inventory.success'));

        $this->assertTrue(
            $fiscalYear->transactions()
                ->where('adjusting_entry_type', Transaction::ADJUSTING_ENTRY_TYPE_INVENTORY_CLOSING)
                ->exists(),
            '棚卸の決算整理仕訳が登録されていること',
        );
    }

    #[Test]
    public function 他ユーザーの会計年度は棚卸セクションでも読み込めない(): void
    {
        [$owner, , $ownerFiscalYear] = $this->createUserWithFiscalYear();

        $attacker = User::factory()->create();
        $attacker->createBusinessUnitWithDefaults(['name' => '攻撃者事業体']);

        Livewire::actingAs($attacker)
            ->test(InventoryClosingSection::class, ['fiscalYearId' => $ownerFiscalYear->id])
            ->assertStatus(403);
    }

    #[Test]
    public function 数値でない期末棚卸高はエラーメッセージで拒否される(): void
    {
        [$user, $unit, $fiscalYear] = $this->createUserWithFiscalYear();

        $subAccountId = $unit->getSubAccountByName('棚卸資産', '棚卸資産')->id;

        Livewire::actingAs($user)
            ->test(InventoryClosingSection::class, ['fiscalYearId' => $fiscalYear->id])
            ->set("closingAmounts.$subAccountId", '12.5')
            ->call('register')
            ->assertSet('noticeMessage', null)
            ->assertNotSet('errorMessage', null);

        $this->assertFalse(
            $fiscalYear->transactions()
                ->where('adjusting_entry_type', Transaction::ADJUSTING_ENTRY_TYPE_INVENTORY_CLOSING)
                ->exists(),
        );
    }

    #[Test]
    public function 期首も期末もゼロなら仕訳を作らず不要メッセージを出す(): void
    {
        [$user, , $fiscalYear] = $this->createUserWithFiscalYear();

        Livewire::actingAs($user)
            ->test(InventoryClosingSection::class, ['fiscalYearId' => $fiscalYear->id])
            ->call('register')
            ->assertSet('errorMessage', null)
            ->assertSet('noticeMessage', __('fiscal_year_closing.inventory.noop'));

        $this->assertFalse(
            $fiscalYear->transactions()
                ->where('adjusting_entry_type', Transaction::ADJUSTING_ENTRY_TYPE_INVENTORY_CLOSING)
                ->exists(),
        );
    }

    #[Test]
    public function 既に登録済みなら登録済み表示が出てフォームは出ない(): void
    {
        [$user, $unit, $fiscalYear] = $this->createUserWithFiscalYear();

        $fiscalYear->registerOpeningEntry([
            [
                'account_name' => '棚卸資産',
                'sub_account_name' => '棚卸資産',
                'amount' => 400,
            ],
        ], $user);

        $subAccountId = $unit->getSubAccountByName('棚卸資産', '棚卸資産')->id;

        app(InventoryClosingService::class)->registerFor(
            $fiscalYear,
            [$subAccountId => 500],
            $user,
        );

        Livewire::actingAs($user)
            ->test(InventoryClosingSection::class, ['fiscalYearId' => $fiscalYear->id])
            ->assertSee(__('fiscal_year_closing.inventory.already_registered'))
            ->assertDontSee(__('fiscal_year_closing.inventory.register_button'));
    }
}
