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
        $this->assertSame(72, $fixedAsset->useful_life);
        $this->assertFalse($fixedAsset->is_disposed);
    }

    #[Test]
    public function 普通車カテゴリならis_new_standard_carがtrueになる()
    {
        $fixedAsset = FixedAsset::factory()->create([
            'asset_category' => '新車-普通車',
        ]);

        $this->assertTrue($fixedAsset->isNewStandardCar());
        $this->assertFalse($fixedAsset->isNewLightCar());
    }

    #[Test]
    public function 軽自動車カテゴリならis_new_light_carがtrueになる()
    {
        $fixedAsset = FixedAsset::factory()->create([
            'asset_category' => '新車-軽自動車',
            'useful_life' => 48,
        ]);

        $this->assertFalse($fixedAsset->isNewStandardCar());
        $this->assertTrue($fixedAsset->isNewLightCar());
    }
}
