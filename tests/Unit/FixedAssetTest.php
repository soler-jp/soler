<?php

namespace Tests\Unit;

use App\Models\FixedAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FixedAssetTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function factoryは車両運搬具として整合した固定資産を作成する()
    {
        $fixedAsset = FixedAsset::factory()->create();

        $this->assertSame('車両運搬具', $fixedAsset->account->name);
        $this->assertSame($fixedAsset->business_unit_id, $fixedAsset->account->business_unit_id);
        $this->assertSame('新車-普通車', $fixedAsset->asset_category);
        $this->assertNull($fixedAsset->first_registration_date);
        $this->assertSame(2_200_000, $fixedAsset->acquisition_cost);
        $this->assertSame(72, $fixedAsset->useful_life);
        $this->assertFalse($fixedAsset->is_disposed);
    }

    #[Test]
    public function 普通車カテゴリならis_new_standard_carがtrueになる()
    {
        $fixedAsset = FixedAsset::factory()->create([
            'asset_category' => FixedAsset::ASSET_CATEGORY_NEW_STANDARD_CAR,
        ]);

        $this->assertTrue($fixedAsset->isNewStandardCar());
        $this->assertFalse($fixedAsset->isNewLightCar());
        $this->assertFalse($fixedAsset->isUsedStandardCar());
        $this->assertFalse($fixedAsset->isUsedLightCar());
    }

    #[Test]
    public function 軽自動車カテゴリならis_new_light_carがtrueになる()
    {
        $fixedAsset = FixedAsset::factory()->create([
            'asset_category' => FixedAsset::ASSET_CATEGORY_NEW_LIGHT_CAR,
            'useful_life' => 48,
        ]);

        $this->assertFalse($fixedAsset->isNewStandardCar());
        $this->assertTrue($fixedAsset->isNewLightCar());
        $this->assertFalse($fixedAsset->isUsedStandardCar());
        $this->assertFalse($fixedAsset->isUsedLightCar());
    }

    #[Test]
    public function 中古普通車カテゴリならis_used_standard_carがtrueになる()
    {
        $fixedAsset = FixedAsset::factory()->create([
            'asset_category' => FixedAsset::ASSET_CATEGORY_USED_STANDARD_CAR,
            'useful_life' => 48,
        ]);

        $this->assertFalse($fixedAsset->isNewStandardCar());
        $this->assertFalse($fixedAsset->isNewLightCar());
        $this->assertTrue($fixedAsset->isUsedStandardCar());
        $this->assertFalse($fixedAsset->isUsedLightCar());
    }

    #[Test]
    public function 中古軽自動車カテゴリならis_used_light_carがtrueになる()
    {
        $fixedAsset = FixedAsset::factory()->create([
            'asset_category' => FixedAsset::ASSET_CATEGORY_USED_LIGHT_CAR,
            'useful_life' => 24,
        ]);

        $this->assertFalse($fixedAsset->isNewStandardCar());
        $this->assertFalse($fixedAsset->isNewLightCar());
        $this->assertFalse($fixedAsset->isUsedStandardCar());
        $this->assertTrue($fixedAsset->isUsedLightCar());
    }
}
