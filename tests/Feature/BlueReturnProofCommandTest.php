<?php

namespace Tests\Feature;

use App\Services\BlueReturnPdf\Proof\FieldCatalog;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BlueReturnProofCommandTest extends TestCase
{
    #[Test]
    public function 全科目の個別_pdf_が出力される(): void
    {
        $outputDir = storage_path('app/testing/blue-return-proof-fields');

        if (is_dir($outputDir)) {
            foreach (glob($outputDir.DIRECTORY_SEPARATOR.'*') as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }

        $exitCode = Artisan::call('blue-return:proof-all', [
            '--template' => 'from2023',
            '--output-dir' => $outputDir,
            '--overlay-only' => true,
        ]);

        $this->assertSame(0, $exitCode);

        // 1ページの勘定科目ごとのPDF + 2ページ・3ページの全欄PDF
        $expectedCount = count(FieldCatalog::profitAndLossFields()) + 2;
        $pdfFiles = glob($outputDir.DIRECTORY_SEPARATOR.'*.pdf') ?: [];

        $this->assertCount($expectedCount, $pdfFiles);
        $this->assertFileExists($outputDir.DIRECTORY_SEPARATOR.'_manifest.txt');
        $this->assertFileDoesNotExist($outputDir.DIRECTORY_SEPARATOR.'_failed.txt');
        $this->assertFileExists($outputDir.DIRECTORY_SEPARATOR.'14_entertainment_expenses.pdf');
        $this->assertFileExists($outputDir.DIRECTORY_SEPARATOR.'page2_all_fields.pdf');
        $this->assertFileExists($outputDir.DIRECTORY_SEPARATOR.'page3_all_fields.pdf');

        foreach ($pdfFiles as $path) {
            $this->assertGreaterThan(0, filesize($path));
            unlink($path);
        }

        unlink($outputDir.DIRECTORY_SEPARATOR.'_manifest.txt');
        rmdir($outputDir);
    }

    #[Test]
    public function 未知のテンプレート版は失敗する(): void
    {
        $exitCode = Artisan::call('blue-return:proof-all', [
            '--template' => 'unknown',
            '--overlay-only' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('未対応のテンプレート版です: unknown', Artisan::output());
    }
}
