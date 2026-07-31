<?php

namespace App\Services\BlueReturnPdf;

use App\Concerns\SkipActorGuard;
use App\Models\BlueReturnInput;
use App\Models\FiscalYear;
use RuntimeException;
use TCPDF;
use TCPDF_FONTS;

#[SkipActorGuard('PDF 生成ロジック。呼び出し側のコントローラで FiscalYear を actor でガードする前提。')]
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

    /**
     * @param  array<string, string>  $header  住所・氏名などヘッダー欄の帳簿外情報(1ページの全ヘッダー欄と2〜4ページの氏名・フリガナ・整理番号に印字する)
     */
    public function generate(FiscalYear $fiscalYear, int $blueReturnDeduction, array $header = []): string
    {
        $templateVersion = $this->templateResolver->resolve($fiscalYear);

        $pdf = $this->createDocument();
        $calculation = $fiscalYear->calculateBlueReturnStatement($blueReturnDeduction);

        for ($page = 1; $page <= self::PAGE_COUNT; $page++) {
            $pdf->AddPage();
            $this->overlayRenderer->renderBackground($pdf, $this->backgroundPath($templateVersion, $page));

            if ($page === 1) {
                $this->overlayRenderer->renderOverlay(
                    $pdf,
                    $this->pageOverlay($templateVersion, 1),
                    $this->formatPage1Values($fiscalYear, $calculation, $header)
                );
            }

            if ($page === 2) {
                $this->overlayRenderer->renderOverlay(
                    $pdf,
                    $this->pageOverlay($templateVersion, 2),
                    $this->formatPage2Values($fiscalYear, $calculation, $header)
                );
            }

            if ($page === 3) {
                $this->overlayRenderer->renderOverlay(
                    $pdf,
                    $this->pageOverlay($templateVersion, 3),
                    $this->formatPage3Values($fiscalYear, $calculation, $header)
                );
            }

            if ($page === 4) {
                $this->overlayRenderer->renderOverlay(
                    $pdf,
                    $this->pageOverlay($templateVersion, 4),
                    $this->formatPage4Values($fiscalYear, $calculation, $header)
                );
            }
        }

        return $pdf->Output('', 'S');
    }

    /**
     * @param  array{profit_and_loss: array<string, int>, custom_expense_labels: array<string, string>}  $calculation
     * @param  array<string, string>  $header
     * @return array<string, string>
     */
    private function formatPage1Values(FiscalYear $fiscalYear, array $calculation, array $header): array
    {
        return $this->fieldFormatter->formatPage1(
            eraYear: $this->eraYear((int) $fiscalYear->year),
            profitAndLoss: $calculation['profit_and_loss'],
            openingMonth: (int) $fiscalYear->start_date->month,
            openingDay: (int) $fiscalYear->start_date->day,
            endingMonth: (int) $fiscalYear->end_date->month,
            endingDay: (int) $fiscalYear->end_date->day,
            header: $header,
            customExpenseLabels: $calculation['custom_expense_labels'],
        );
    }

    /**
     * @param  array{profit_and_loss: array<string, int>, monthly_sales_and_purchases: array<string, mixed>}  $calculation
     * @param  array<string, string>  $header
     * @return array<string, string>
     */
    private function formatPage2Values(FiscalYear $fiscalYear, array $calculation, array $header): array
    {
        return $this->fieldFormatter->formatPage2(
            eraYear: $this->eraYear((int) $fiscalYear->year),
            monthlySalesAndPurchases: $calculation['monthly_sales_and_purchases'],
            incomeBeforeBlueReturnDeduction: $calculation['profit_and_loss']['income_before_blue_return_deduction'],
            familyEmployeeSalaryRows: $this->blueReturnInputRows($fiscalYear, BlueReturnInput::KEY_FAMILY_EMPLOYEE_SALARIES),
            rentExpenseRows: $this->blueReturnInputRows($fiscalYear, BlueReturnInput::KEY_RENT_EXPENSES),
            name: $header['name'] ?? '',
            nameKana: $header['name_kana'] ?? '',
            filingNumber: $header['filing_number'] ?? '',
        );
    }

    /**
     * 地代家賃の内訳は様式版で載るページが違う(令和五年分以降用は2ページ・令和二年分以降用は3ページ)ため、
     * 2ページと3ページの両方の値に渡し、オーバーレイ定義に欄があるページだけで印字される。
     *
     * @param  array{profit_and_loss: array<string, int>, depreciation_calculation: array<string, mixed>}  $calculation
     * @param  array<string, string>  $header
     * @return array<string, string>
     */
    private function formatPage3Values(FiscalYear $fiscalYear, array $calculation, array $header): array
    {
        return $this->fieldFormatter->formatPage3(
            salesAmount: $calculation['profit_and_loss']['sales_amount'],
            purchasesAmount: $calculation['profit_and_loss']['purchases_amount'],
            depreciationCalculation: $calculation['depreciation_calculation'],
            rentExpenseRows: $this->blueReturnInputRows($fiscalYear, BlueReturnInput::KEY_RENT_EXPENSES),
            filingNumber: $header['filing_number'] ?? '',
        );
    }

    /**
     * @param  array{balance_sheet: array<string, mixed>}  $calculation
     * @param  array<string, string>  $header
     * @return array<string, string>
     */
    private function formatPage4Values(FiscalYear $fiscalYear, array $calculation, array $header): array
    {
        return $this->fieldFormatter->formatPage4(
            balanceSheet: $calculation['balance_sheet'],
            openingMonth: (int) $fiscalYear->start_date->month,
            openingDay: (int) $fiscalYear->start_date->day,
            endingMonth: (int) $fiscalYear->end_date->month,
            endingDay: (int) $fiscalYear->end_date->day,
            filingNumber: $header['filing_number'] ?? '',
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
