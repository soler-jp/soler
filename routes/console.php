<?php

use App\Services\BlueReturnPdf\BlueReturnStatementPdfGenerator;
use App\Services\BlueReturnPdf\FieldFormatter;
use App\Services\BlueReturnPdf\OverlayRenderer;
use App\Services\BlueReturnPdf\Proof\FieldCatalog;
use App\Services\BlueReturnPdf\TemplateResolver;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Artisan::command('blue-return:proof-all {--template=from2023} {--output-dir=} {--overlay-only}', function () {
    $templateVersion = (string) $this->option('template');
    $outputDir = rtrim($this->option('output-dir') ?: storage_path('app/blue-return/proof-fields'), DIRECTORY_SEPARATOR);
    $overlayOnly = (bool) $this->option('overlay-only');

    try {
        $overlayDirectory = app(TemplateResolver::class)->overlayDirectory($templateVersion);
    } catch (InvalidArgumentException $exception) {
        $this->error($exception->getMessage());

        return 1;
    }

    $generator = app(BlueReturnStatementPdfGenerator::class);
    $overlayRenderer = app(OverlayRenderer::class);
    $fieldFormatter = app(FieldFormatter::class);

    /** @var array{page: int, fields: array<string, array<string, array<string, mixed>>>} $overlay */
    $overlay = require app_path("Services/BlueReturnPdf/Templates/{$overlayDirectory}/Page1Overlay.php");

    if (! is_dir($outputDir)) {
        mkdir($outputDir, 0775, true);
    }

    $generated = [];
    $failed = [];

    foreach (FieldCatalog::profitAndLossFields() as $fieldKey => $catalogEntry) {
        $definition = $overlay['fields'][$fieldKey] ?? null;

        if ($definition === null) {
            $failed[] = $fieldKey.' (missing overlay definition)';

            continue;
        }

        $fieldNumber = $catalogEntry['field_number'];
        $label = $catalogEntry['label'].' ('.$fieldKey.')';

        try {
            if (isset($definition['amount']) && ($definition['amount']['type'] ?? null) === 'digit_cells') {
                $cellCount = count($definition['amount']['cells']);
                $digits = substr(str_repeat((string) $fieldNumber, $cellCount), 0, $cellCount);
                $value = $fieldFormatter->formatAmount((int) $digits);
            } elseif (isset($definition['text'])) {
                $value = str_repeat('最長文字列', 6);
            } else {
                $failed[] = $fieldKey.' (unknown definition type)';

                continue;
            }

            $pdf = $generator->createDocument();
            $pdf->AddPage();

            if (! $overlayOnly) {
                $overlayRenderer->renderBackground(
                    $pdf,
                    resource_path("blue-return/templates/{$templateVersion}/background/page1.png")
                );
            }

            $pdf->SetFontSize(20);
            $pdf->Text(100, 100, $label);
            $overlayRenderer->renderOverlay(
                $pdf,
                ['page' => 1, 'fields' => [$fieldKey => $definition]],
                [$fieldKey => $value]
            );

            $fileName = sprintf('%02d_%s.pdf', $fieldNumber, $fieldKey);
            file_put_contents($outputDir.DIRECTORY_SEPARATOR.$fileName, $pdf->Output('', 'S'));
            $generated[] = $fileName.' '.$label;
        } catch (Throwable $exception) {
            $failed[] = $fieldKey.' ('.$exception->getMessage().')';
        }
    }

    file_put_contents(
        $outputDir.DIRECTORY_SEPARATOR.'_manifest.txt',
        implode(PHP_EOL, $generated).PHP_EOL
    );

    if ($failed !== []) {
        file_put_contents(
            $outputDir.DIRECTORY_SEPARATOR.'_failed.txt',
            implode(PHP_EOL, $failed).PHP_EOL
        );
    }

    $this->info($outputDir);
})->purpose('Page1Overlay の妥当性を目視確認するため、勘定科目ごとの校正PDFを出力する');
