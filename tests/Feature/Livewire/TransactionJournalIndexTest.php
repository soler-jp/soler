<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Pages\TransactionJournalIndex;
use App\Models\BusinessUnit;
use App\Models\User;
use App\Services\TransactionRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionJournalIndexTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 仕訳帳一覧ページを表示できる(): void
    {
        [$user] = $this->createInitializedUser();

        $this->actingAs($user);

        $response = $this->get(route('transactions.journal'));

        $response->assertOk();
        $response->assertSee('仕訳帳一覧');
        $response->assertSeeLivewire(TransactionJournalIndex::class);
    }

    #[Test]
    public function 初期状態では借方貸方勘定科目トグルは未選択である(): void
    {
        [$user] = $this->createInitializedUser();

        Livewire::actingAs($user)
            ->test(TransactionJournalIndex::class)
            ->assertSet('debitAccountNames', [])
            ->assertSet('creditAccountNames', [])
            ->assertSet('sortBy', 'date')
            ->assertSet('sortDirection', 'asc')
            ->assertSee('未選択なら借方条件なし')
            ->assertSee('未選択なら貸方条件なし');
    }

    #[Test]
    public function 勘定科目フィルタを更新すると該当transactionだけ表示される(): void
    {
        [$user, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;

        $cash = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $sales = $unit->getAccountByName('売上高')->subAccounts()->firstOrFail();
        $supplies = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();

        (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-01-10',
            'description' => '売上入金',
        ], [
            ['sub_account_id' => $cash->id, 'type' => 'debit', 'net_amount' => 10000],
            ['sub_account_id' => $sales->id, 'type' => 'credit', 'net_amount' => 10000],
        ]);

        (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-01-12',
            'description' => '備品購入',
        ], [
            ['sub_account_id' => $supplies->id, 'type' => 'debit', 'net_amount' => 3000],
            ['sub_account_id' => $cash->id, 'type' => 'credit', 'net_amount' => 3000],
        ]);

        Livewire::actingAs($user)
            ->test(TransactionJournalIndex::class)
            ->set('creditAccountNames', ['売上高'])
            ->assertSee('売上入金')
            ->assertDontSee('備品購入');
    }

    #[Test]
    public function ヘッダクリックでソート項目と方向を切り替えられる(): void
    {
        [$user] = $this->createInitializedUser();

        Livewire::actingAs($user)
            ->test(TransactionJournalIndex::class)
            ->call('sort', 'amount')
            ->assertSet('sortBy', 'amount')
            ->assertSet('sortDirection', 'asc')
            ->call('sort', 'amount')
            ->assertSet('sortDirection', 'desc')
            ->call('sort', 'description')
            ->assertSet('sortBy', 'description')
            ->assertSet('sortDirection', 'asc');
    }

    #[Test]
    public function 借方選択に応じて貸方候補件数が絞り込まれる(): void
    {
        [$user, $unit] = $this->createInitializedUser();
        $fiscalYear = $unit->currentFiscalYear;

        $cash = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $sales = $unit->getAccountByName('売上高')->subAccounts()->firstOrFail();
        $supplies = $unit->getAccountByName('消耗品費')->subAccounts()->firstOrFail();

        (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-01-10',
            'description' => '売上入金',
        ], [
            ['sub_account_id' => $cash->id, 'type' => 'debit', 'net_amount' => 10000],
            ['sub_account_id' => $sales->id, 'type' => 'credit', 'net_amount' => 10000],
        ]);

        (new TransactionRegistrar)->register($fiscalYear, [
            'date' => '2025-01-12',
            'description' => '備品購入',
        ], [
            ['sub_account_id' => $supplies->id, 'type' => 'debit', 'net_amount' => 3000],
            ['sub_account_id' => $cash->id, 'type' => 'credit', 'net_amount' => 3000],
        ]);

        Livewire::actingAs($user)
            ->test(TransactionJournalIndex::class)
            ->set('debitAccountNames', ['現金'])
            ->assertSet('creditAccountOptionCounts', ['売上高' => 1]);
    }

    /**
     * @return array{0: User, 1: BusinessUnit}
     */
    private function createInitializedUser(): array
    {
        $user = User::factory()->create();
        $unit = $user->createBusinessUnitWithDefaults(['name' => '仕訳帳UIテスト']);
        $unit->createFiscalYear(2025);
        $unit->refresh();

        return [$user, $unit];
    }
}
