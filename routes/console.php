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

    // 1ページのヘッダー欄(年分・住所・氏名・電話番号・期首期末月日ほか)は全欄をテスト値で埋めた1枚のPDFで確認する
    try {
        $page1HeaderValues = $fieldFormatter->formatPage1(
            eraYear: 7,
            profitAndLoss: [],
            openingMonth: 10,
            openingDay: 21,
            endingMonth: 12,
            endingDay: 31,
            header: [
                'filing_number' => '12345678',
                'address' => '東京都千代田区霞が関1-2-3 テストマンション405号室',
                'name_kana' => 'サイチョウシメイカクニンヨウノナマエ',
                'name' => '最長氏名確認用の名前',
                'business_address' => '東京都千代田区丸の内9-8-7 テストビル10階',
                'home_phone_number' => '03-1234-5678',
                'business_phone_number' => '090-1234-5678',
                'business_type' => 'ソフトウェア開発業',
                'trade_name' => '最長屋号確認用の屋号',
                'association_name' => '東京青色申告会連合会',
                'tax_accountant_office_address' => '東京都新宿区西新宿1-2-3 税理士ビル501',
                'tax_accountant_name' => '税理士氏名確認用',
                'tax_accountant_phone_number' => '03-9876-5432',
            ],
        );

        $pdf = $generator->createDocument();
        $pdf->AddPage();

        if (! $overlayOnly) {
            $overlayRenderer->renderBackground(
                $pdf,
                resource_path("blue-return/templates/{$templateVersion}/background/page1.png")
            );
        }

        $overlayRenderer->renderOverlay($pdf, $overlay, $page1HeaderValues);

        file_put_contents($outputDir.DIRECTORY_SEPARATOR.'page1_header_fields.pdf', $pdf->Output('', 'S'));
        $generated[] = 'page1_header_fields.pdf 1ページ(ヘッダー欄テスト値)';
    } catch (Throwable $exception) {
        $failed[] = 'page1_header_fields ('.$exception->getMessage().')';
    }

    // 2ページ(月別売上・専従者給与・地代家賃ほか)は全欄をテスト値で埋めた1枚のPDFで確認する
    try {
        $page2Values = $fieldFormatter->formatPage2(
            eraYear: 7,
            monthlySalesAndPurchases: [
                'months' => collect(range(1, 12))->map(fn (int $month): array => [
                    'year_month' => sprintf('2025-%02d', $month),
                    'label' => $month.'月',
                    'sales_amount' => $month * 1_000_000 + 111_111,
                    'house_consumption_amount' => 0,
                    'misc_income_amount' => 0,
                    'purchases_amount' => $month * 1_000_000,
                ])->all(),
                'totals' => [
                    'sales_amount' => 79_333_332,
                    'house_consumption_amount' => 4_444_444,
                    'misc_income_amount' => 555_555,
                    'purchases_amount' => 78_000_000,
                ],
            ],
            incomeBeforeBlueReturnDeduction: 12_345_678,
            familyEmployeeSalaryRows: collect(range(1, 4))->map(fn (int $row): array => [
                'name' => '専従者氏名'.$row,
                'age' => 45,
                'months' => 12,
                'salary' => 2_222_222,
                'bonus' => 333_333,
                'withheld_tax_amount' => 111_111,
            ])->all(),
            rentExpenseRows: collect(range(1, 2))->map(fn (int $row): array => [
                'address' => '東京都千代田区霞が関1-2-'.$row.' テストビル405',
                'name' => '賃貸太郎'.$row,
                'rent_amount' => 1_234_567,
                'deductible_amount' => 987_654,
            ])->all(),
            name: '最長氏名確認用の名前',
            nameKana: 'サイチョウシメイカクニンヨウノナマエ',
            filingNumber: '12345678',
        );

        $pdf = $generator->createDocument();
        $pdf->AddPage();

        if (! $overlayOnly) {
            $overlayRenderer->renderBackground(
                $pdf,
                resource_path("blue-return/templates/{$templateVersion}/background/page2.png")
            );
        }

        $overlayRenderer->renderOverlay(
            $pdf,
            require app_path("Services/BlueReturnPdf/Templates/{$overlayDirectory}/Page2Overlay.php"),
            $page2Values
        );

        file_put_contents($outputDir.DIRECTORY_SEPARATOR.'page2_all_fields.pdf', $pdf->Output('', 'S'));
        $generated[] = 'page2_all_fields.pdf 2ページ(全欄テスト値)';
    } catch (Throwable $exception) {
        $failed[] = 'page2_all_fields ('.$exception->getMessage().')';
    }

    // 3ページ(売上・仕入金額の明細、減価償却費の計算)も全欄をテスト値で埋めた1枚のPDFで確認する
    try {
        $page3Values = $fieldFormatter->formatPage3(
            salesAmount: 12_345_678,
            purchasesAmount: 87_654_321,
            depreciationCalculation: [
                'entries' => collect(range(1, 7))->map(fn (int $row): array => [
                    'fixed_asset_name' => '減価償却資産'.$row,
                    'quantity' => 1,
                    'acquisition_year_month' => sprintf('202%d-%02d', $row % 6, $row + 3),
                    'depreciation_base_amount' => $row * 1_000_000 + 111_111,
                    'depreciation_method' => 'straight_line',
                    'useful_life' => $row + 3,
                    'depreciation_rate' => '0.'.str_repeat((string) $row, 3),
                    'months' => 12,
                    'ordinary_amount' => $row * 100_000 + 11_111,
                    'total_amount' => $row * 100_000 + 11_111,
                    'business_usage_ratio' => $row % 2 === 0 ? '1.00' : '0.875',
                    'deductible_amount' => $row * 100_000,
                    'ending_undepreciated_balance' => $row * 500_000 + 55_555,
                ])->all(),
                'totals' => [
                    'ordinary_amount' => 2_877_777,
                    'total_amount' => 2_877_777,
                    'deductible_amount' => 2_800_000,
                ],
            ],
            // 地代家賃の内訳は令和二年分以降用(from2020)では3ページにある(令和五年分以降用の3ページには欄がなく印字されない)
            rentExpenseRows: collect(range(1, 2))->map(fn (int $row): array => [
                'address' => '東京都千代田区霞が関1-2-'.$row.' テストビル405',
                'name' => '賃貸太郎'.$row,
                'rent_amount' => 1_234_567,
                'deductible_amount' => 987_654,
            ])->all(),
            filingNumber: '12345678',
        );

        $pdf = $generator->createDocument();
        $pdf->AddPage();

        if (! $overlayOnly) {
            $overlayRenderer->renderBackground(
                $pdf,
                resource_path("blue-return/templates/{$templateVersion}/background/page3.png")
            );
        }

        $overlayRenderer->renderOverlay(
            $pdf,
            require app_path("Services/BlueReturnPdf/Templates/{$overlayDirectory}/Page3Overlay.php"),
            $page3Values
        );

        file_put_contents($outputDir.DIRECTORY_SEPARATOR.'page3_all_fields.pdf', $pdf->Output('', 'S'));
        $generated[] = 'page3_all_fields.pdf 3ページ(全欄テスト値)';
    } catch (Throwable $exception) {
        $failed[] = 'page3_all_fields ('.$exception->getMessage().')';
    }

    // 4ページ(貸借対照表)も全欄をテスト値で埋めた1枚のPDFで確認する
    try {
        $balanceSheetRows = static fn (array $accountNames): array => collect($accountNames)
            ->values()
            ->map(fn (string $accountName, int $index): array => [
                'account_id' => $index + 1,
                'account_name' => $accountName,
                'opening_balance' => ($index + 1) * 1_000_000 + 111_111,
                'ending_balance' => ($index + 1) * 1_000_000 + 999_999,
                'rows' => [],
            ])->all();

        $page4Values = $fieldFormatter->formatPage4(
            balanceSheet: [
                'income_before_blue_return_deduction' => 12_345_678,
                'sections' => [
                    // 固定行にない科目名(追加◯◯)は空欄行にラベル付きで載る
                    'asset' => [
                        'type' => 'asset',
                        'label' => '資産の部',
                        'opening_total_balance' => 88_888_888,
                        'ending_total_balance' => 88_888_888,
                        'rows' => $balanceSheetRows([
                            '現金', '当座預金', '定期預金', 'その他の預金', '受取手形', '売掛金', '有価証券', '棚卸資産',
                            '前払金', '貸付金', '建物', '建物附属設備', '機械装置', '車両運搬具', '工具器具備品', '土地',
                            '追加資産科目1', '追加資産科目2', '追加資産科目3', '追加資産科目4', '追加資産科目5', '追加資産科目6', '追加資産科目7',
                        ]),
                    ],
                    'liability' => [
                        'type' => 'liability',
                        'label' => '負債の部',
                        'opening_total_balance' => 77_777_777,
                        'ending_total_balance' => 77_777_777,
                        'rows' => $balanceSheetRows([
                            '支払手形', '買掛金', '借入金', '未払金', '前受金', '預り金', '貸倒引当金',
                            '追加負債科目1', '追加負債科目2', '追加負債科目3', '追加負債科目4', '追加負債科目5', '追加負債科目6', '追加負債科目7',
                        ]),
                    ],
                    'equity' => [
                        'type' => 'equity',
                        'label' => '純資産の部',
                        'opening_total_balance' => 66_666_666,
                        'ending_total_balance' => 66_666_666,
                        'rows' => [
                            ['account_id' => 91, 'account_name' => '事業主貸', 'opening_balance' => 0, 'ending_balance' => -33_333_333, 'rows' => []],
                            ['account_id' => 92, 'account_name' => '事業主借', 'opening_balance' => 0, 'ending_balance' => 44_444_444, 'rows' => []],
                            ['account_id' => 93, 'account_name' => '元入金', 'opening_balance' => 55_555_555, 'ending_balance' => 55_555_555, 'rows' => []],
                        ],
                    ],
                ],
                'totals' => [
                    'opening' => ['asset' => 88_888_888, 'liability' => 77_777_777, 'equity' => 66_666_666],
                    'ending' => ['asset' => 88_888_888, 'liability' => 77_777_777, 'equity' => 66_666_666],
                ],
            ],
            openingMonth: 1,
            openingDay: 1,
            endingMonth: 12,
            endingDay: 31,
            filingNumber: '12345678',
        );

        $pdf = $generator->createDocument();
        $pdf->AddPage();

        if (! $overlayOnly) {
            $overlayRenderer->renderBackground(
                $pdf,
                resource_path("blue-return/templates/{$templateVersion}/background/page4.png")
            );
        }

        $overlayRenderer->renderOverlay(
            $pdf,
            require app_path("Services/BlueReturnPdf/Templates/{$overlayDirectory}/Page4Overlay.php"),
            $page4Values
        );

        file_put_contents($outputDir.DIRECTORY_SEPARATOR.'page4_all_fields.pdf', $pdf->Output('', 'S'));
        $generated[] = 'page4_all_fields.pdf 4ページ(全欄テスト値)';
    } catch (Throwable $exception) {
        $failed[] = 'page4_all_fields ('.$exception->getMessage().')';
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
})->purpose('Page1〜Page4 Overlay の妥当性を目視確認するため、勘定科目ごとの校正PDFと2〜4ページの全欄校正PDFを出力する');
