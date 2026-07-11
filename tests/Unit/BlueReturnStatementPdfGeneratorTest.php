<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\BlueReturnPdf\BlueReturnStatementPdfGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BlueReturnStatementPdfGeneratorTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_blank_pdf_has_four_pages(): void
    {
        $generator = app(BlueReturnStatementPdfGenerator::class);
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'PDF基盤テスト',
        ]);
        $fiscalYear = $businessUnit->createFiscalYear(2025);

        $pdf = $generator->generate($fiscalYear, 650000);

        $this->assertNotSame('', $pdf);
        $this->assertSame(4, preg_match_all('/\/Type\s*\/Page\b/', $pdf));
        $this->assertStringContainsString('/Subtype /Image', $pdf);
    }

    #[Test]
    public function test_fiscal_year_wrapper_generates_pdf(): void
    {
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'PDF基盤テスト',
        ]);
        $fiscalYear = $businessUnit->createFiscalYear(2025);

        $pdf = $fiscalYear->generateBlueReturnStatementPdf(650000);

        $this->assertNotSame('', $pdf);
        $this->assertSame(4, preg_match_all('/\/Type\s*\/Page\b/', $pdf));
    }

    #[Test]
    public function test_unsupported_year_throws_exception(): void
    {
        $generator = app(BlueReturnStatementPdfGenerator::class);
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'PDF基盤テスト',
        ]);
        $fiscalYear = $businessUnit->createFiscalYear(2022);

        $this->expectException(InvalidArgumentException::class);

        $generator->generate($fiscalYear, 650000);
    }

    #[Test]
    public function test_japanese_text_renders_without_errors(): void
    {
        $generator = app(BlueReturnStatementPdfGenerator::class);
        $document = $generator->createDocument();

        $document->AddPage();
        $document->Write(0, '青色申告決算書');

        $pdf = $document->Output('', 'S');

        $this->assertNotSame('', $pdf);
    }
}
