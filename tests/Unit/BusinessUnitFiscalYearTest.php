<?php

namespace Tests\Unit;

use App\Models\BusinessUnit;
use App\Models\User;
use App\Models\FiscalYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use App\Validators\FiscalYearValidator;
use Illuminate\Validation\ValidationException;

class BusinessUnitFiscalYearTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function createFiscalYearは年だけ指定して正しく作成される()
    {
        $user = User::factory()->create();
        $businessUnit = BusinessUnit::factory()->create(['user_id' => $user->id]);

        $fiscalYear = $businessUnit->createFiscalYear(2025);

        $this->assertInstanceOf(FiscalYear::class, $fiscalYear);
        $this->assertEquals(2025, $fiscalYear->year);
        $this->assertEquals('2025-01-01', $fiscalYear->start_date->toDateString());
        $this->assertEquals('2025-12-31', $fiscalYear->end_date->toDateString());
        $this->assertFalse($fiscalYear->is_closed);
        $this->assertEquals($businessUnit->id, $fiscalYear->business_unit_id);

        $this->assertDatabaseHas('fiscal_years', [
            'id' => $fiscalYear->id,
            'year' => 2025,
        ]);
    }

    #[Test]
    public function 年度の重複でバリデーションエラーになる()
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'テスト事業',
            'type' => BusinessUnit::TYPE_GENERAL,
        ]);

        $fiscalYear = $businessUnit->createFiscalYear(2025);
        $this->assertEquals(2025, $fiscalYear->year);

        // 同じyearで重複登録しようとしてバリデーションエラー
        $this->expectException(ValidationException::class);

        FiscalYearValidator::validate([
            'business_unit_id' => $businessUnit->id,
            'year' => 2025,
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
            'is_closed' => false,
        ]);
    }


    #[Test]
    public function 初回作成したFiscalYearは自動でis_activeになる()
    {
        $user = User::factory()->create();
        $businessUnit = BusinessUnit::factory()->create(['user_id' => $user->id]);

        $this->assertDatabaseMissing('fiscal_years', [
            'business_unit_id' => $businessUnit->id,
        ]);

        $fiscalYear = $businessUnit->createFiscalYear(2025);
        $fiscalYear->refresh(); // DBから最新の状態を取得

        $this->assertTrue($fiscalYear->is_active);
        $this->assertDatabaseHas('fiscal_years', [
            'id' => $fiscalYear->id,
            'is_active' => true,
        ]);
    }


    #[Test]
    public function FiscalYear作成時に2つ目以降はis_activeがfalseで作成される()
    {
        $user = User::factory()->create();
        $businessUnit = BusinessUnit::factory()->create(['user_id' => $user->id]);

        // 1つ目作成でactiveになる
        $businessUnit->createFiscalYear(2024);

        // 2つ目作成は非active
        $fiscalYear2 = $businessUnit->createFiscalYear(2025);

        $this->assertFalse($fiscalYear2->is_active);
        $this->assertDatabaseHas('fiscal_years', [
            'id' => $fiscalYear2->id,
            'is_active' => false,
        ]);
    }
}
