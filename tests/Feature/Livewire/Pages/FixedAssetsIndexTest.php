<?php

namespace Tests\Feature\Livewire\Pages;

use App\Livewire\Pages\FixedAssetsIndex;
use App\Models\DepreciationEntry;
use App\Models\FixedAsset;
use App\Models\User;
use App\Setup\Initializers\GeneralBusinessInitializer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FixedAssetsIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function initializeUnit(User $user, int $year = 2025)
    {
        return (new GeneralBusinessInitializer)->initialize($user, [
            'name' => 'テスト事業体',
            'type' => 'general',
            'year' => $year,
            'is_taxable' => false,
            'is_tax_exclusive' => false,
            'cash_balance' => null,
            'bank_accounts' => [],
            'fixed_assets' => [],
            'recurring_templates' => [],
        ]);
    }

    #[Test]
    public function 固定資産ページが表示される(): void
    {
        $user = User::factory()->create();
        $this->initializeUnit($user);

        $this->actingAs($user);

        $this->get(route('fixed-assets.index'))
            ->assertOk()
            ->assertSeeLivewire(FixedAssetsIndex::class);
    }

    #[Test]
    public function ダッシュボードには固定資産パネルが表示されない(): void
    {
        $user = User::factory()->create();
        $this->initializeUnit($user);

        $this->actingAs($user);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSeeLivewire(FixedAssetsIndex::class);
    }

    #[Test]
    public function 車両プリセットで新車の普通車を登録できる(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $fiscalYear = $unit->currentFiscalYear;

        $paymentSub = $unit->getAccountByName('事業主借')->subAccounts()->firstOrFail();

        Livewire::actingAs($user)
            ->test(FixedAssetsIndex::class)
            ->call('setCategory', FixedAsset::ASSET_CATEGORY_NEW_STANDARD_CAR)
            ->set('car_name', 'テスト用PRIUS')
            ->set('car_acquisition_date', $fiscalYear->start_date->toDateString())
            ->set('car_gross_amount', 3_300_000)
            ->set('car_tax_amount', 300_000)
            ->set('car_payment_sub_account_id', $paymentSub->id)
            ->set('car_transaction_description', 'テスト用PRIUSを購入')
            ->call('confirmCarPreset')
            ->assertHasNoErrors()
            ->assertSet('confirming', true)
            ->call('submitCarPreset')
            ->assertHasNoErrors()
            ->assertSet('confirming', false);

        $this->assertDatabaseHas('fixed_assets', [
            'business_unit_id' => $unit->id,
            'name' => 'テスト用PRIUS',
            'asset_category' => FixedAsset::ASSET_CATEGORY_NEW_STANDARD_CAR,
            'useful_life' => 72,
            'depreciation_method' => FixedAsset::DEPRECIATION_METHOD_STRAIGHT_LINE,
            'taxable_amount' => 3_000_000,
            'tax_amount' => 300_000,
        ]);

        $this->assertDatabaseHas('transactions', [
            'fiscal_year_id' => $fiscalYear->id,
            'description' => 'テスト用PRIUSを購入',
        ]);
    }

    #[Test]
    public function 車両プリセットの確認前バリデーションで中古車の初年度登録日必須(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $fiscalYear = $unit->currentFiscalYear;

        $paymentSub = $unit->getAccountByName('事業主借')->subAccounts()->firstOrFail();

        Livewire::actingAs($user)
            ->test(FixedAssetsIndex::class)
            ->call('setCategory', FixedAsset::ASSET_CATEGORY_USED_STANDARD_CAR)
            ->set('car_name', '中古PRIUS')
            ->set('car_acquisition_date', $fiscalYear->start_date->toDateString())
            ->set('car_gross_amount', 500_000)
            ->set('car_payment_sub_account_id', $paymentSub->id)
            ->call('confirmCarPreset')
            ->assertHasErrors(['car_first_registration_date'])
            ->assertSet('confirming', false);
    }

    #[Test]
    public function 確認中に戻って修正すると入力フェーズに戻る(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $fiscalYear = $unit->currentFiscalYear;

        $paymentSub = $unit->getAccountByName('事業主借')->subAccounts()->firstOrFail();

        Livewire::actingAs($user)
            ->test(FixedAssetsIndex::class)
            ->call('setCategory', FixedAsset::ASSET_CATEGORY_NEW_STANDARD_CAR)
            ->set('car_name', '一旦戻す車')
            ->set('car_acquisition_date', $fiscalYear->start_date->toDateString())
            ->set('car_gross_amount', 1_100_000)
            ->set('car_tax_amount', 100_000)
            ->set('car_payment_sub_account_id', $paymentSub->id)
            ->call('confirmCarPreset')
            ->assertSet('confirming', true)
            ->call('cancelConfirm')
            ->assertSet('confirming', false);

        $this->assertDatabaseMissing('fixed_assets', [
            'name' => '一旦戻す車',
        ]);
    }

    #[Test]
    public function advancedモードで全項目を指定して固定資産を登録できる(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $fiscalYear = $unit->currentFiscalYear;

        $assetSub = $unit->getAccountByName('工具器具備品')->subAccounts()->firstOrFail();
        $paymentSub = $unit->getAccountByName('事業主借')->subAccounts()->firstOrFail();

        Livewire::actingAs($user)
            ->test(FixedAssetsIndex::class)
            ->call('setCategory', FixedAssetsIndex::CATEGORY_ADVANCED)
            ->set('adv_asset_sub_account_id', $assetSub->id)
            ->set('adv_payment_sub_account_id', $paymentSub->id)
            ->set('adv_name', 'ノートPC')
            ->set('adv_acquisition_date', $fiscalYear->start_date->toDateString())
            ->set('adv_gross_amount', 275_000)
            ->set('adv_tax_amount', 25_000)
            ->set('adv_useful_life', 48)
            ->set('adv_transaction_description', 'ノートPCを購入')
            ->call('confirmAdvanced')
            ->assertHasNoErrors()
            ->assertSet('confirming', true)
            ->call('submitAdvanced')
            ->assertHasNoErrors()
            ->assertSet('confirming', false);

        $this->assertDatabaseHas('fixed_assets', [
            'business_unit_id' => $unit->id,
            'name' => 'ノートPC',
            'asset_category' => '工具器具備品',
            'useful_life' => 48,
            'depreciation_method' => FixedAsset::DEPRECIATION_METHOD_STRAIGHT_LINE,
            'taxable_amount' => 250_000,
            'tax_amount' => 25_000,
        ]);

        $this->assertDatabaseHas('transactions', [
            'fiscal_year_id' => $fiscalYear->id,
            'description' => 'ノートPCを購入',
        ]);
    }

    #[Test]
    public function 取得日が期首より前でもチェックボックスなしで登録できる(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user, year: 2025);
        $fiscalYear = $unit->currentFiscalYear;

        $paymentSub = $unit->getAccountByName('事業主借')->subAccounts()->firstOrFail();

        Livewire::actingAs($user)
            ->test(FixedAssetsIndex::class)
            ->call('setCategory', FixedAsset::ASSET_CATEGORY_NEW_LIGHT_CAR)
            ->set('car_name', '過年度取得の軽自動車')
            ->set('car_acquisition_date', '2023-05-10')
            ->set('car_gross_amount', 1_320_000)
            ->set('car_tax_amount', 120_000)
            ->set('car_payment_sub_account_id', $paymentSub->id)
            ->call('confirmCarPreset')
            ->assertHasNoErrors()
            ->call('submitCarPreset')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('fixed_assets', [
            'business_unit_id' => $unit->id,
            'name' => '過年度取得の軽自動車',
            'taxable_amount' => 1_200_000,
            'tax_amount' => 120_000,
        ]);

        $this->assertDatabaseMissing('transactions', [
            'fiscal_year_id' => $fiscalYear->id,
            'description' => '過年度取得の軽自動車 を取得',
        ]);
    }

    #[Test]
    public function 支払元に許可されていない補助科目_例_売上高_を指定するとバリデーションで弾かれる(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $fiscalYear = $unit->currentFiscalYear;

        $illegalPaymentSub = $unit->getAccountByName('売上高')->subAccounts()->firstOrFail();

        Livewire::actingAs($user)
            ->test(FixedAssetsIndex::class)
            ->call('setCategory', FixedAsset::ASSET_CATEGORY_NEW_STANDARD_CAR)
            ->set('car_name', '不正な支払元の車')
            ->set('car_acquisition_date', $fiscalYear->start_date->toDateString())
            ->set('car_gross_amount', 3_300_000)
            ->set('car_tax_amount', 300_000)
            ->set('car_payment_sub_account_id', $illegalPaymentSub->id)
            ->call('confirmCarPreset')
            ->assertHasErrors(['car_payment_sub_account_id'])
            ->assertSet('confirming', false);

        $this->assertDatabaseMissing('fixed_assets', [
            'name' => '不正な支払元の車',
        ]);
    }

    #[Test]
    public function advancedモードで計上先に減価償却対象外の勘定_例_現金_を指定すると弾かれる(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $fiscalYear = $unit->currentFiscalYear;

        $illegalAssetSub = $unit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $paymentSub = $unit->getAccountByName('事業主借')->subAccounts()->firstOrFail();

        Livewire::actingAs($user)
            ->test(FixedAssetsIndex::class)
            ->call('setCategory', FixedAssetsIndex::CATEGORY_ADVANCED)
            ->set('adv_asset_sub_account_id', $illegalAssetSub->id)
            ->set('adv_payment_sub_account_id', $paymentSub->id)
            ->set('adv_name', '不正な計上先')
            ->set('adv_acquisition_date', $fiscalYear->start_date->toDateString())
            ->set('adv_gross_amount', 275_000)
            ->set('adv_tax_amount', 25_000)
            ->set('adv_useful_life', 48)
            ->call('confirmAdvanced')
            ->assertHasErrors(['adv_asset_sub_account_id'])
            ->assertSet('confirming', false);

        $this->assertDatabaseMissing('fixed_assets', [
            'name' => '不正な計上先',
        ]);
    }

    #[Test]
    public function 登録済みの固定資産が一覧に表示される(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);

        FixedAsset::factory()->create([
            'business_unit_id' => $unit->id,
            'name' => '一覧確認用資産',
            'asset_category' => FixedAsset::ASSET_CATEGORY_NEW_LIGHT_CAR,
        ]);

        Livewire::actingAs($user)
            ->test(FixedAssetsIndex::class)
            ->assertSee('一覧確認用資産')
            ->assertSee(FixedAsset::ASSET_CATEGORY_NEW_LIGHT_CAR);
    }

    #[Test]
    public function 消費税額が税込価格以上だとバリデーションで弾かれる(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $fiscalYear = $unit->currentFiscalYear;

        $paymentSub = $unit->getAccountByName('事業主借')->subAccounts()->firstOrFail();

        Livewire::actingAs($user)
            ->test(FixedAssetsIndex::class)
            ->call('setCategory', FixedAsset::ASSET_CATEGORY_NEW_STANDARD_CAR)
            ->set('car_name', '税額が本体以上の車')
            ->set('car_acquisition_date', $fiscalYear->start_date->toDateString())
            ->set('car_gross_amount', 1_000_000)
            ->set('car_tax_amount', 1_000_000)
            ->set('car_payment_sub_account_id', $paymentSub->id)
            ->call('confirmCarPreset')
            ->assertHasErrors(['car_tax_amount'])
            ->assertSet('confirming', false);
    }

    #[Test]
    public function confirmingでない状態でsubmitを直接叩いても登録されない(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $fiscalYear = $unit->currentFiscalYear;

        $paymentSub = $unit->getAccountByName('事業主借')->subAccounts()->firstOrFail();

        Livewire::actingAs($user)
            ->test(FixedAssetsIndex::class)
            ->call('setCategory', FixedAsset::ASSET_CATEGORY_NEW_STANDARD_CAR)
            ->set('car_name', 'confirm経由しない車')
            ->set('car_acquisition_date', $fiscalYear->start_date->toDateString())
            ->set('car_gross_amount', 1_100_000)
            ->set('car_tax_amount', 100_000)
            ->set('car_payment_sub_account_id', $paymentSub->id)
            ->call('submitCarPreset')
            ->assertHasNoErrors()
            ->assertSet('confirming', false);

        $this->assertDatabaseMissing('fixed_assets', [
            'name' => 'confirm経由しない車',
        ]);
    }

    #[Test]
    public function カテゴリ切り替えでconfirmingがリセットされる(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $fiscalYear = $unit->currentFiscalYear;

        $paymentSub = $unit->getAccountByName('事業主借')->subAccounts()->firstOrFail();

        Livewire::actingAs($user)
            ->test(FixedAssetsIndex::class)
            ->call('setCategory', FixedAsset::ASSET_CATEGORY_NEW_STANDARD_CAR)
            ->set('car_name', 'カテゴリ切替される車')
            ->set('car_acquisition_date', $fiscalYear->start_date->toDateString())
            ->set('car_gross_amount', 1_100_000)
            ->set('car_tax_amount', 100_000)
            ->set('car_payment_sub_account_id', $paymentSub->id)
            ->call('confirmCarPreset')
            ->assertSet('confirming', true)
            ->call('setCategory', FixedAssetsIndex::CATEGORY_ADVANCED)
            ->assertSet('confirming', false);
    }

    #[Test]
    public function 登録すると減価償却明細が作成される(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $fiscalYear = $unit->currentFiscalYear;

        $paymentSub = $unit->getAccountByName('事業主借')->subAccounts()->firstOrFail();

        Livewire::actingAs($user)
            ->test(FixedAssetsIndex::class)
            ->call('setCategory', FixedAsset::ASSET_CATEGORY_NEW_STANDARD_CAR)
            ->set('car_name', '減価償却明細作成テスト用')
            ->set('car_acquisition_date', $fiscalYear->start_date->toDateString())
            ->set('car_gross_amount', 3_300_000)
            ->set('car_tax_amount', 300_000)
            ->set('car_payment_sub_account_id', $paymentSub->id)
            ->call('confirmCarPreset')
            ->call('submitCarPreset')
            ->assertHasNoErrors();

        $asset = FixedAsset::where('name', '減価償却明細作成テスト用')->firstOrFail();

        $this->assertDatabaseHas('depreciation_entries', [
            'fixed_asset_id' => $asset->id,
            'fiscal_year_id' => $fiscalYear->id,
        ]);

        $entry = DepreciationEntry::where('fixed_asset_id', $asset->id)
            ->where('fiscal_year_id', $fiscalYear->id)
            ->firstOrFail();

        $this->assertGreaterThan(0, (int) $entry->total_amount);
    }

    #[Test]
    public function 中古車プリセットで耐用月数が計算される(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user, year: 2025);

        $paymentSub = $unit->getAccountByName('事業主借')->subAccounts()->firstOrFail();

        Livewire::actingAs($user)
            ->test(FixedAssetsIndex::class)
            ->call('setCategory', FixedAsset::ASSET_CATEGORY_USED_STANDARD_CAR)
            ->set('car_name', '10年落ちの中古PRIUS')
            ->set('car_acquisition_date', '2025-04-01')
            ->set('car_first_registration_date', '2015-04-01')
            ->set('car_gross_amount', 550_000)
            ->set('car_tax_amount', 50_000)
            ->set('car_payment_sub_account_id', $paymentSub->id)
            ->call('confirmCarPreset')
            ->assertHasNoErrors()
            ->call('submitCarPreset')
            ->assertHasNoErrors();

        // 法定耐用年数 (72ヶ月) を超えて経過しているので下限の 24 ヶ月
        $this->assertDatabaseHas('fixed_assets', [
            'name' => '10年落ちの中古PRIUS',
            'asset_category' => FixedAsset::ASSET_CATEGORY_USED_STANDARD_CAR,
            'useful_life' => 24,
        ]);
    }

    #[Test]
    public function 登録成功時に成功メッセージがビューに表示される(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $fiscalYear = $unit->currentFiscalYear;

        $paymentSub = $unit->getAccountByName('事業主借')->subAccounts()->firstOrFail();

        Livewire::actingAs($user)
            ->test(FixedAssetsIndex::class)
            ->call('setCategory', FixedAsset::ASSET_CATEGORY_NEW_STANDARD_CAR)
            ->set('car_name', '成功メッセージ確認用')
            ->set('car_acquisition_date', $fiscalYear->start_date->toDateString())
            ->set('car_gross_amount', 1_100_000)
            ->set('car_tax_amount', 100_000)
            ->set('car_payment_sub_account_id', $paymentSub->id)
            ->call('confirmCarPreset')
            ->call('submitCarPreset')
            ->assertHasNoErrors()
            ->assertSee(__('fixed_assets.messages.registered'));
    }

    #[Test]
    public function 銀行口座_その他の預金の補助科目_を支払元として選択できる(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $fiscalYear = $unit->currentFiscalYear;

        // 実運用の銀行口座登録と同じ経路で「その他の預金」配下に銀行口座の補助科目を作る
        $bankSub = $unit->getAccountByName('その他の預金')
            ->addCustomSubAccount('メインバンク', $user);

        Livewire::actingAs($user)
            ->test(FixedAssetsIndex::class)
            ->call('setCategory', FixedAsset::ASSET_CATEGORY_NEW_STANDARD_CAR)
            ->set('car_name', '銀行支払の車')
            ->set('car_acquisition_date', $fiscalYear->start_date->toDateString())
            ->set('car_gross_amount', 2_200_000)
            ->set('car_tax_amount', 200_000)
            ->set('car_payment_sub_account_id', $bankSub->id)
            ->call('confirmCarPreset')
            ->assertHasNoErrors()
            ->call('submitCarPreset')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('fixed_assets', [
            'name' => '銀行支払の車',
        ]);
    }

    #[Test]
    public function サービスが例外を投げた時にconfirmingが解除されエラーメッセージが出る(): void
    {
        $user = User::factory()->create();
        $unit = $this->initializeUnit($user);
        $fiscalYear = $unit->currentFiscalYear;

        $paymentSub = $unit->getAccountByName('事業主借')->subAccounts()->firstOrFail();

        // 中古車で初年度登録日 > 取得日 → DepreciationService が InvalidArgumentException を投げる
        Livewire::actingAs($user)
            ->test(FixedAssetsIndex::class)
            ->call('setCategory', FixedAsset::ASSET_CATEGORY_USED_STANDARD_CAR)
            ->set('car_name', '初度登録が取得後の壊れた入力')
            ->set('car_acquisition_date', '2025-04-01')
            ->set('car_first_registration_date', '2025-10-01')
            ->set('car_gross_amount', 550_000)
            ->set('car_tax_amount', 50_000)
            ->set('car_payment_sub_account_id', $paymentSub->id)
            ->call('confirmCarPreset')
            ->assertSet('confirming', true)
            ->call('submitCarPreset')
            ->assertSet('confirming', false)
            ->assertSee(__('fixed_assets.messages.registration_failed'));

        $this->assertDatabaseMissing('fixed_assets', [
            'name' => '初度登録が取得後の壊れた入力',
        ]);
    }
}
