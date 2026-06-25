<?php

namespace Tests\Feature;

use App\Models\FiscalYear;
use App\Validators\TransactionValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TransactionValidatorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function is_plannedを省略した場合はバリデーションにnullが設定される()
    {
        $fiscalYear = FiscalYear::factory()->create();

        $data = [
            'fiscal_year_id' => $fiscalYear->id,
            'date' => '2025-01-01',
            'description' => 'テスト取引',
        ];

        $validated = TransactionValidator::validate($data);

        $this->assertArrayHasKey('is_planned', $validated);
        $this->assertSame(false, $validated['is_planned']);
    }

    #[Test]
    public function is_plannedがnullの場合バリデーション後もnullのまま返る()
    {
        $fiscalYear = FiscalYear::factory()->create();

        $data = [
            'fiscal_year_id' => $fiscalYear->id,
            'date' => '2025-01-01',
            'description' => 'テスト取引',
            'is_planned' => null,
        ];

        $validated = TransactionValidator::validate($data);

        $this->assertArrayHasKey('is_planned', $validated);
        $this->assertSame(false, $validated['is_planned']);
    }
}
