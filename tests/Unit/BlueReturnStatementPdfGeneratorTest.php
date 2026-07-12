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
    public function test_header_values_generate_pdf(): void
    {
        $generator = app(BlueReturnStatementPdfGenerator::class);
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'PDF基盤テスト',
        ]);
        $fiscalYear = $businessUnit->createFiscalYear(2025);

        $pdf = $generator->generate($fiscalYear, 650000, [
            'filing_number' => '12345678',
            'address' => '東京都千代田区霞が関1-2-3',
            'name_kana' => 'ヤマダ タロウ',
            'name' => '山田 太郎',
            'business_address' => '東京都千代田区丸の内9-8-7',
            'home_phone_number' => '03-1234-5678',
            'business_phone_number' => '090-1234-5678',
            'business_type' => 'ソフトウェア開発業',
            'trade_name' => 'ソレル商店',
            'association_name' => '東京青色申告会',
            'tax_accountant_office_address' => '東京都新宿区西新宿1-2-3',
            'tax_accountant_name' => '税理 士郎',
            'tax_accountant_phone_number' => '03-9876-5432',
        ]);

        $this->assertNotSame('', $pdf);
        $this->assertSame(4, preg_match_all('/\/Type\s*\/Page\b/', $pdf));
    }

    #[Test]
    public function test_from2020_template_generates_pdf(): void
    {
        $generator = app(BlueReturnStatementPdfGenerator::class);
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'PDF基盤テスト',
        ]);
        $fiscalYear = $businessUnit->createFiscalYear(2022);

        $pdf = $generator->generate($fiscalYear, 650000);

        $this->assertNotSame('', $pdf);
        $this->assertSame(4, preg_match_all('/\/Type\s*\/Page\b/', $pdf));
        $this->assertStringContainsString('/Subtype /Image', $pdf);
    }

    #[Test]
    public function test_unsupported_year_throws_exception(): void
    {
        $generator = app(BlueReturnStatementPdfGenerator::class);
        $user = User::factory()->create();
        $businessUnit = $user->createBusinessUnitWithDefaults([
            'name' => 'PDF基盤テスト',
        ]);
        $fiscalYear = $businessUnit->createFiscalYear(2019);

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
