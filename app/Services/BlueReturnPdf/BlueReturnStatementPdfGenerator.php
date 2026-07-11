<?php

namespace App\Services\BlueReturnPdf;

use App\Models\BlueReturnInput;
use App\Models\FiscalYear;
use RuntimeException;
use TCPDF;
use TCPDF_FONTS;

class BlueReturnStatementPdfGenerator
{
    private const FONT_FILE = 'ipaexg.ttf';

    private const PAGE_COUNT = 4;

    private const PAGE_HEIGHT = 595;

    private const PAGE_WIDTH = 842;

    public function __construct(
        private readonly FieldFormatter $fieldFormatter,
        private readonly OverlayRenderer $overlayRenderer,
        private readonly TemplateResolver $templateResolver
    ) {}

    private ?string $japaneseFontFamily = null;

    public function generate(FiscalYear $fiscalYear, int $blueReturnDeduction): string
    {
        $templateVersion = $this->templateResolver->resolve($fiscalYear);

        $pdf = $this->createDocument();
        $calculation = $fiscalYear->calculateBlueReturnStatement($blueReturnDeduction);
        $formattedProfitAndLoss = $this->fieldFormatter->formatProfitAndLoss($calculation['profit_and_loss']);

        for ($page = 1; $page <= self::PAGE_COUNT; $page++) {
            $pdf->AddPage();
            $this->overlayRenderer->renderBackground($pdf, $this->backgroundPath($templateVersion, $page));

            if ($page === 1) {
                $this->overlayRenderer->renderOverlay($pdf, $this->pageOverlay($templateVersion, 1), $formattedProfitAndLoss);
            }

            if ($page === 2) {
                $this->overlayRenderer->renderOverlay(
                    $pdf,
                    $this->pageOverlay($templateVersion, 2),
                    $this->formatPage2Values($fiscalYear, $calculation)
                );
            }

            if ($page === 3) {
                $this->overlayRenderer->renderOverlay(
                    $pdf,
                    $this->pageOverlay($templateVersion, 3),
                    $this->formatPage3Values($calculation)
                );
            }

            if ($page === 4) {
                $this->overlayRenderer->renderOverlay(
                    $pdf,
                    $this->pageOverlay($templateVersion, 4),
                    $this->formatPage4Values($fiscalYear, $calculation)
                );
            }
        }

        return $pdf->Output('', 'S');
    }

    /**
     * @param  array{profit_and_loss: array<string, int>, monthly_sales_and_purchases: array<string, mixed>}  $calculation
     * @return array<string, string>
     */
    private function formatPage2Values(FiscalYear $fiscalYear, array $calculation): array
    {
        return $this->fieldFormatter->formatPage2(
            eraYear: $this->eraYear((int) $fiscalYear->year),
            monthlySalesAndPurchases: $calculation['monthly_sales_and_purchases'],
            incomeBeforeBlueReturnDeduction: $calculation['profit_and_loss']['income_before_blue_return_deduction'],
            familyEmployeeSalaryRows: $this->blueReturnInputRows($fiscalYear, BlueReturnInput::KEY_FAMILY_EMPLOYEE_SALARIES),
            rentExpenseRows: $this->blueReturnInputRows($fiscalYear, BlueReturnInput::KEY_RENT_EXPENSES),
        );
    }

    /**
     * @param  array{profit_and_loss: array<string, int>, depreciation_calculation: array<string, mixed>}  $calculation
     * @return array<string, string>
     */
    private function formatPage3Values(array $calculation): array
    {
        return $this->fieldFormatter->formatPage3(
            salesAmount: $calculation['profit_and_loss']['sales_amount'],
            purchasesAmount: $calculation['profit_and_loss']['purchases_amount'],
            depreciationCalculation: $calculation['depreciation_calculation'],
        );
    }

    /**
     * @param  array{balance_sheet: array<string, mixed>}  $calculation
     * @return array<string, string>
     */
    private function formatPage4Values(FiscalYear $fiscalYear, array $calculation): array
    {
        return $this->fieldFormatter->formatPage4(
            balanceSheet: $calculation['balance_sheet'],
            openingMonth: (int) $fiscalYear->start_date->month,
            openingDay: (int) $fiscalYear->start_date->day,
            endingMonth: (int) $fiscalYear->end_date->month,
            endingDay: (int) $fiscalYear->end_date->day,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function blueReturnInputRows(FiscalYear $fiscalYear, string $key): array
    {
        return $fiscalYear->blueReturnInput($key)?->value['rows'] ?? [];
    }

    /**
     * 西暦年 → 令和の年数(令和5年 = 2023年)。
     */
    private function eraYear(int $year): int
    {
        return $year - 2018;
    }

    public function createDocument(): TCPDF
    {
        $this->japaneseFontFamily();

        $pdf = new TCPDF('L', 'pt', [self::PAGE_WIDTH, self::PAGE_HEIGHT], true, 'UTF-8', false);

        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->setMargins(0, 0, 0);
        $pdf->setAutoPageBreak(false, 0);
        $pdf->setCellMargins(0, 0, 0, 0);
        $pdf->setCellPaddings(0, 0, 0, 0);
        $pdf->setFontSubsetting(true);
        $pdf->SetCreator(config('app.name', 'Laravel'));
        $pdf->SetTitle('青色申告決算書');
        $pdf->SetSubject('Blue return statement');
        $pdf->SetFont($this->japaneseFontFamily(), '', 11, '', true);

        return $pdf;
    }

    private function japaneseFontFamily(): string
    {
        if ($this->japaneseFontFamily !== null) {
            return $this->japaneseFontFamily;
        }

        $fontDirectory = $this->fontDirectory();
        $cacheDirectory = $this->fontCacheDirectory();
        $fontPath = $fontDirectory.self::FONT_FILE;

        if (! is_dir($cacheDirectory)) {
            mkdir($cacheDirectory, 0775, true);
        }

        if (! defined('K_PATH_FONTS')) {
            define('K_PATH_FONTS', $cacheDirectory);
        }

        $fontFamily = TCPDF_FONTS::addTTFfont($fontPath, 'TrueTypeUnicode', '', 32, $cacheDirectory);

        if (! is_string($fontFamily) || $fontFamily === '') {
            throw new RuntimeException('IPAexゴシックをTCPDFに登録できませんでした。');
        }

        $this->japaneseFontFamily = $fontFamily;

        return $fontFamily;
    }

    private function backgroundPath(string $templateVersion, int $pageNumber): string
    {
        return resource_path("blue-return/templates/{$templateVersion}/background/page{$pageNumber}.png");
    }

    /**
     * @return array{page: int, fields: array<string, array<string, array<string, mixed>>>}
     */
    private function pageOverlay(string $templateVersion, int $pageNumber): array
    {
        $overlayDirectory = $this->templateResolver->overlayDirectory($templateVersion);

        return require app_path("Services/BlueReturnPdf/Templates/{$overlayDirectory}/Page{$pageNumber}Overlay.php");
    }

    private function fontDirectory(): string
    {
        return resource_path('pdf/fonts').DIRECTORY_SEPARATOR;
    }

    private function fontCacheDirectory(): string
    {
        return base_path('vendor/tecnickcom/tcpdf/fonts').DIRECTORY_SEPARATOR;
    }
}
