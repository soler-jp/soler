<?php

namespace Tests\Unit;

use App\Models\BusinessUnit;
use App\Models\FixedAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FixedAssetTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function 新車の普通車プリセットから初期値を生成できる()
    {
        $businessUnit = BusinessUnit::factory()->create();
        $fixedAsset = $businessUnit->newStandardCarFixedAsset();

        $this->assertSame('新車-普通車', $fixedAsset->asset_category);
        $this->assertSame('新車-普通車', $fixedAsset->name);
        $this->assertSame(72, $fixedAsset->useful_life);
        $this->assertSame(FixedAsset::DEPRECIATION_METHOD_STRAIGHT_LINE, $fixedAsset->depreciation_method);
        $this->assertTrue($fixedAsset->isNewStandardCar());
    }

    #[Test]
    public function 新車の普通車プリセットは入力値で上書きできる()
    {
        $businessUnit = BusinessUnit::factory()->create();
        $fixedAsset = $businessUnit->newStandardCarFixedAsset([
            'name' => '営業車',
            'acquisition_cost' => 3300000,
        ]);

        $this->assertSame('営業車', $fixedAsset->name);
        $this->assertSame(3300000, $fixedAsset->acquisition_cost);
        $this->assertSame(72, $fixedAsset->useful_life);
    }

    #[Test]
    public function パラメータなしでプリセットを保存できる()
    {
        $businessUnit = BusinessUnit::factory()->create();
        $fixedAsset = $businessUnit->newStandardCarFixedAsset([
            'acquisition_date' => now()->toDateString(),
            'taxable_amount' => 2_000_000,
            'tax_amount' => 200_000,
            'acquisition_cost' => 2_200_000,
            'depreciation_base_amount' => 2_200_000,
        ]);

        $this->assertNotNull($fixedAsset->id);
        $this->assertSame('新車-普通車', $fixedAsset->asset_category);
        $this->assertSame('新車-普通車', $fixedAsset->name);
        $this->assertSame(72, $fixedAsset->useful_life);
        $this->assertSame(FixedAsset::DEPRECIATION_METHOD_STRAIGHT_LINE, $fixedAsset->depreciation_method);
    }

    #[Test]
    public function factoryは車両運搬具として整合した固定資産を作成する()
    {
        $fixedAsset = FixedAsset::factory()->create();

        $this->assertSame('車両運搬具', $fixedAsset->account->name);
        $this->assertSame($fixedAsset->business_unit_id, $fixedAsset->account->business_unit_id);
        $this->assertSame('新車-普通車', $fixedAsset->asset_category);
        $this->assertSame(72, $fixedAsset->useful_life);
        $this->assertFalse($fixedAsset->is_disposed);
    }

    #[Test]
    public function 新車の軽自動車プリセットから初期値を生成できる()
    {
        $businessUnit = BusinessUnit::factory()->create();
        $fixedAsset = $businessUnit->newLightCarFixedAsset();

        $this->assertSame('新車-軽自動車', $fixedAsset->asset_category);
        $this->assertSame('新車-軽自動車', $fixedAsset->name);
        $this->assertSame(48, $fixedAsset->useful_life);
        $this->assertSame(FixedAsset::DEPRECIATION_METHOD_STRAIGHT_LINE, $fixedAsset->depreciation_method);
        $this->assertTrue($fixedAsset->isNewLightCar());
    }

    #[Test]
    public function 新車の軽自動車プリセットは入力値で上書きできる()
    {
        $businessUnit = BusinessUnit::factory()->create();
        $fixedAsset = $businessUnit->newLightCarFixedAsset([
            'name' => '営業軽自動車',
            'acquisition_cost' => 1_500_000,
        ]);

        $this->assertSame('営業軽自動車', $fixedAsset->name);
        $this->assertSame(1_500_000, $fixedAsset->acquisition_cost);
        $this->assertSame(48, $fixedAsset->useful_life);
    }

    #[Test]
    public function 軽自動車のパラメータなしでプリセットを保存できる()
    {
        $businessUnit = BusinessUnit::factory()->create();
        $fixedAsset = $businessUnit->newLightCarFixedAsset([
            'acquisition_date' => now()->toDateString(),
            'taxable_amount' => 1_000_000,
            'tax_amount' => 100_000,
            'acquisition_cost' => 1_100_000,
            'depreciation_base_amount' => 1_100_000,
        ]);

        $this->assertNotNull($fixedAsset->id);
        $this->assertSame('新車-軽自動車', $fixedAsset->asset_category);
        $this->assertSame('新車-軽自動車', $fixedAsset->name);
        $this->assertSame(48, $fixedAsset->useful_life);
        $this->assertSame(FixedAsset::DEPRECIATION_METHOD_STRAIGHT_LINE, $fixedAsset->depreciation_method);
    }
}
