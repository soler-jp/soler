<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\PreviousFiscalYearRolloverPrompt;
use App\Models\BusinessUnit;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\FiscalYearRollover;
use App\Services\TransactionRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardPreviousFiscalYearRolloverPromptTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 前年度が未繰越ならダッシュボードにプロンプトを表示する(): void
    {
        [$user, $unit, $previousFiscalYear] = $this->createUserWithAdjacentFiscalYears();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSeeLivewire(PreviousFiscalYearRolloverPrompt::class)
            ->assertSee('2025年のデータを2026年の初期データとして読み込みますか？')
            ->assertSee('既に登録済みの売上・経費・仕入には影響しません。')
            ->assertDontSee('この内容で初期データを作成します。良いですか？');

        $this->assertNull($previousFiscalYear->fresh()->rollover_at);
    }

    #[Test]
    public function 前年度が締め済みなら最初は読込ボタンだけを表示する(): void
    {
        [$user, $unit, $previousFiscalYear] = $this->createUserWithAdjacentFiscalYears();
        $previousFiscalYear->close($user);

        Livewire::actingAs($user)
            ->test(PreviousFiscalYearRolloverPrompt::class, ['businessUnit' => $unit])
            ->assertSee('2025年のデータを2026年の初期データとして読み込む')
            ->assertDontSee('この内容で初期データを作成します。良いですか？');
    }

    #[Test]
    public function 前年度が未締めならボタンを無効化する案内と年度管理リンクを表示する(): void
    {
        [$user, $unit] = $this->createUserWithAdjacentFiscalYears();

        Livewire::actingAs($user)
            ->test(PreviousFiscalYearRolloverPrompt::class, ['businessUnit' => $unit])
            ->assertSee('2025年のデータが完了になっていないので初期データを作成できません。2025年のデータを締めてください。')
            ->assertSee(route('fiscal-years.index'))
            ->assertSee('年度管理で締め作業をする')
            ->assertDontSee('2025年のデータを2026年の初期データとして読み込む');
    }

    #[Test]
    public function 前年度が締め済みなら確認画面を経由して繰越を実行できる(): void
    {
        [$user, $unit, $previousFiscalYear, $currentFiscalYear] = $this->createUserWithAdjacentFiscalYears();

        $cash = $unit->getSubAccountByName('現金', '現金');
        $sales = $unit->getSubAccountByName('売上高', '売上高');

        $previousFiscalYear->registerOpeningEntry([
            [
                'account_name' => '現金',
                'sub_account_name' => '現金',
                'amount' => 100_000,
            ],
        ], $user);

        (new TransactionRegistrar)->register($previousFiscalYear, [
            'date' => '2025-04-10',
            'description' => '売上',
        ], [
            [
                'sub_account_id' => $cash->id,
                'type' => JournalEntry::TYPE_DEBIT,
                'net_amount' => 30_000,
            ],
            [
                'sub_account_id' => $sales->id,
                'type' => JournalEntry::TYPE_CREDIT,
                'net_amount' => 30_000,
            ],
        ], $user);

        $previousFiscalYear->close($user);

        Livewire::actingAs($user)
            ->test(PreviousFiscalYearRolloverPrompt::class, ['businessUnit' => $unit])
            ->assertDontSee('元入金')
            ->call('openConfirmation')
            ->assertSee('この内容で初期データを作成します。良いですか？')
            ->assertSee('元入金')
            ->call('start')
            ->assertRedirect(route('dashboard'));

        $this->assertNotNull($previousFiscalYear->fresh()->rollover_at);
        $this->assertTrue($currentFiscalYear->fresh()->transactions()->where('is_opening_entry', true)->exists());
    }

    #[Test]
    public function 前年度が繰越済みならダッシュボードにプロンプトを表示しない(): void
    {
        [$user, $unit, $previousFiscalYear, $currentFiscalYear] = $this->createUserWithAdjacentFiscalYears();

        $previousFiscalYear->registerOpeningEntry([
            [
                'account_name' => '現金',
                'sub_account_name' => '現金',
                'amount' => 50_000,
            ],
        ], $user);
        $previousFiscalYear->close($user);
        app(FiscalYearRollover::class)->rollover($previousFiscalYear, $currentFiscalYear, $user);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSeeLivewire(PreviousFiscalYearRolloverPrompt::class);
    }

    /**
     * @return array{0: User, 1: BusinessUnit, 2: FiscalYear, 3: FiscalYear}
     */
    private function createUserWithAdjacentFiscalYears(): array
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '繰越案内テスト事業体']);
        $previousFiscalYear = $unit->createFiscalYear(2025, $user);
        $currentFiscalYear = $unit->createFiscalYear(2026, $user);
        $unit->setCurrentFiscalYear($currentFiscalYear, $user);

        return [$user, $unit->fresh(), $previousFiscalYear->fresh(), $currentFiscalYear->fresh()];
    }
}
