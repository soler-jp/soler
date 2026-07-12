<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * From2020(令和二年分以降用)の Page2Overlay / Page3Overlay を機械検証する。
 * 桁マス欄は geometry JSON(公式様式の抽出値)との一致、box / text 欄はページ内に収まることを確認する。
 */
class BlueReturnFrom2020OverlayTest extends TestCase
{
    private const COORDINATE_TOLERANCE = 0.5;

    private const PAGE_WIDTH = 842.0;

    private const PAGE_HEIGHT = 595.0;

    /**
     * 2ページの桁マス欄 → geometry 上のグループ(top と先頭マスの x0)と期待マス数。
     */
    private const PAGE2_DIGIT_CELL_FIELDS = [
        'era_year' => ['top' => 32.72, 'x0' => 100.67, 'cell_count' => 2],
        'filing_number' => ['top' => 58.92, 'x0' => 667.14, 'cell_count' => 8],
        'monthly_house_consumption' => ['top' => 320.82, 'x0' => 94.45, 'cell_count' => 8],
        'monthly_misc_income' => ['top' => 338.28, 'x0' => 94.45, 'cell_count' => 8],
        'family_employee_salary_total_months' => ['top' => 373.20, 'x0' => 478.57, 'cell_count' => 2],
        'family_employee_salary_total_withheld_tax' => ['top' => 373.20, 'x0' => 681.39, 'cell_count' => 6],
    ];

    /**
     * 月別売上・仕入の計は geometry 上で1つのグループ(16マス)に連結されているため、
     * 左半分(売上)・右半分(仕入)に分けて検証する。
     */
    private const PAGE2_MONTHLY_TOTAL_FIELDS = [
        'monthly_sales_total' => ['cell_offset' => 0, 'cell_count' => 8],
        'monthly_purchases_total' => ['cell_offset' => 8, 'cell_count' => 8],
    ];

    private const PAGE3_DIGIT_CELL_FIELDS = [
        'filing_number' => ['top' => 30.10, 'x0' => 576.35, 'cell_count' => 8],
    ];

    #[Test]
    public function page2の桁マス欄はgeometryのグループと一致する(): void
    {
        $fields = $this->overlayFields(2);
        $geometry = $this->geometry(2);

        foreach (self::PAGE2_DIGIT_CELL_FIELDS as $fieldKey => $expected) {
            $this->assertDigitCellsMatchGeometry($fields, $geometry, $fieldKey, $expected);
        }
    }

    #[Test]
    public function page2の月別売上と仕入の計は連結グループの左右半分と一致する(): void
    {
        $fields = $this->overlayFields(2);
        $geometry = $this->geometry(2);

        $group = $this->findGeometryGroup($geometry, 364.47, 94.45);
        $this->assertNotNull($group, '月別の計に対応する digit_cell_group が geometry にありません');

        $geometryCells = $this->numericCells($group['cells']);
        $this->assertCount(16, $geometryCells);

        foreach (self::PAGE2_MONTHLY_TOTAL_FIELDS as $fieldKey => $expected) {
            $this->assertArrayHasKey($fieldKey, $fields, "{$fieldKey} が Page2Overlay にありません");

            $definition = $fields[$fieldKey]['amount'];
            $this->assertSame('digit_cells', $definition['type'], $fieldKey);
            $this->assertCount($expected['cell_count'], $definition['cells'], $fieldKey);
            $this->assertEqualsWithDelta($group['top'], $definition['top'], self::COORDINATE_TOLERANCE, $fieldKey);
            $this->assertEqualsWithDelta($group['bottom'], $definition['bottom'], self::COORDINATE_TOLERANCE, $fieldKey);

            foreach ($definition['cells'] as $index => $cell) {
                $geometryCell = $geometryCells[$expected['cell_offset'] + $index];
                $this->assertEqualsWithDelta($geometryCell['x0'], $cell['x0'], self::COORDINATE_TOLERANCE, "{$fieldKey} cell{$index}");
                $this->assertEqualsWithDelta($geometryCell['x1'], $cell['x1'], self::COORDINATE_TOLERANCE, "{$fieldKey} cell{$index}");
            }
        }
    }

    #[Test]
    public function page3の桁マス欄はgeometryのグループと一致する(): void
    {
        $fields = $this->overlayFields(3);
        $geometry = $this->geometry(3);

        foreach (self::PAGE3_DIGIT_CELL_FIELDS as $fieldKey => $expected) {
            $this->assertDigitCellsMatchGeometry($fields, $geometry, $fieldKey, $expected);
        }
    }

    #[Test]
    public function page2は必要な欄キーをすべて持つ(): void
    {
        $fields = $this->overlayFields(2);

        $expectedKeys = ['era_year', 'filing_number', 'name', 'name_kana', 'income_before_blue_return_deduction',
            'monthly_house_consumption', 'monthly_misc_income', 'monthly_sales_total', 'monthly_purchases_total'];

        foreach (range(1, 12) as $month) {
            $expectedKeys[] = "monthly_sales_{$month}";
            $expectedKeys[] = "monthly_purchases_{$month}";
        }

        // 様式は明細5行(From2023 は4行)
        foreach (range(1, 5) as $row) {
            foreach (['name', 'age', 'months', 'salary', 'bonus', 'total', 'withheld_tax'] as $column) {
                $expectedKeys[] = "family_employee_salary_{$row}_{$column}";
            }
        }

        foreach (['months', 'salary', 'bonus', 'amount', 'withheld_tax'] as $column) {
            $expectedKeys[] = "family_employee_salary_total_{$column}";
        }

        foreach ($expectedKeys as $fieldKey) {
            $this->assertArrayHasKey($fieldKey, $fields, "{$fieldKey} が Page2Overlay にありません");
        }

        $this->assertCount(count($expectedKeys), $fields);
    }

    #[Test]
    public function page3は必要な欄キーをすべて持つ(): void
    {
        $fields = $this->overlayFields(3);

        $expectedKeys = ['filing_number'];

        // 様式は明細11行(From2023 は7行)
        foreach (range(1, 11) as $row) {
            foreach ([
                'asset_name', 'quantity', 'acquisition_year_month', 'base_amount', 'method', 'useful_life',
                'depreciation_rate', 'months', 'ordinary_amount', 'total_amount', 'business_usage_ratio',
                'deductible_amount', 'ending_undepreciated_balance',
            ] as $column) {
                $expectedKeys[] = "depreciation_{$row}_{$column}";
            }
        }

        foreach (['ordinary_amount', 'amount', 'deductible_amount'] as $column) {
            $expectedKeys[] = "depreciation_total_{$column}";
        }

        // 地代家賃の内訳は From2020 では3ページにある
        foreach (range(1, 2) as $row) {
            foreach (['address', 'name', 'rent_amount', 'deductible_amount'] as $column) {
                $expectedKeys[] = "rent_expense_{$row}_{$column}";
            }
        }

        foreach ($expectedKeys as $fieldKey) {
            $this->assertArrayHasKey($fieldKey, $fields, "{$fieldKey} が Page3Overlay にありません");
        }

        $this->assertCount(count($expectedKeys), $fields);

        // 売上・仕入金額の明細(インボイス対応欄)は令和二年分様式に存在しない
        $this->assertArrayNotHasKey('sales_amount_total', $fields);
        $this->assertArrayNotHasKey('purchases_amount_total', $fields);
    }

    #[Test]
    public function page1とpage4は_from2023の定義を共用する(): void
    {
        foreach ([1, 4] as $page) {
            $from2020 = require app_path("Services/BlueReturnPdf/Templates/From2020/Page{$page}Overlay.php");
            $from2023 = require app_path("Services/BlueReturnPdf/Templates/From2023/Page{$page}Overlay.php");

            $this->assertSame($from2023, $from2020, "page{$page}");
        }
    }

    #[Test]
    public function 全欄がページ内に収まる(): void
    {
        foreach ([2, 3] as $page) {
            foreach ($this->overlayFields($page) as $fieldKey => $fieldDefinition) {
                $definition = $fieldDefinition['amount'] ?? $fieldDefinition['text'];
                $label = "page{$page} {$fieldKey}";

                if (($definition['type'] ?? 'text') === 'digit_cells') {
                    $this->assertLessThan($definition['bottom'], $definition['top'], $label);
                    $previousX1 = 0.0;

                    foreach ($definition['cells'] as $index => $cell) {
                        $this->assertLessThan($cell['x1'], $cell['x0'], "{$label} cell{$index}");
                        $this->assertGreaterThan($previousX1, $cell['x0'], "{$label} cell{$index} は左→右の昇順ではありません");
                        $previousX1 = $cell['x1'];
                    }

                    $this->assertLessThanOrEqual(self::PAGE_WIDTH, $previousX1, $label);
                    $this->assertLessThanOrEqual(self::PAGE_HEIGHT, $definition['bottom'], $label);

                    continue;
                }

                $this->assertLessThan($definition['x1'], $definition['x0'], $label);
                $this->assertGreaterThanOrEqual(0.0, $definition['x0'], $label);
                $this->assertLessThanOrEqual(self::PAGE_WIDTH, $definition['x1'], $label);
                $this->assertGreaterThanOrEqual(0.0, $definition['y'], $label);
                $this->assertLessThanOrEqual(self::PAGE_HEIGHT, $definition['y'] + ($definition['size'] ?? 11.0) * 1.5, $label);
            }
        }
    }

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $fields
     * @param  array{digit_cell_groups: array<int, array{top: float, bottom: float, cells: array<int, array{x0: float, x1: float, w: float}>}>}  $geometry
     * @param  array{top: float, x0: float, cell_count: int}  $expected
     */
    private function assertDigitCellsMatchGeometry(array $fields, array $geometry, string $fieldKey, array $expected): void
    {
        $this->assertArrayHasKey($fieldKey, $fields, "{$fieldKey} が Overlay にありません");

        $definition = $fields[$fieldKey]['amount'];
        $this->assertSame('digit_cells', $definition['type'], $fieldKey);

        $group = $this->findGeometryGroup($geometry, $expected['top'], $expected['x0']);
        $this->assertNotNull($group, "{$fieldKey} に対応する digit_cell_group が geometry にありません");

        $geometryCells = $this->numericCells($group['cells']);

        $this->assertCount($expected['cell_count'], $definition['cells'], $fieldKey);
        $this->assertCount($expected['cell_count'], $geometryCells, $fieldKey);
        $this->assertEqualsWithDelta($group['top'], $definition['top'], self::COORDINATE_TOLERANCE, $fieldKey);
        $this->assertEqualsWithDelta($group['bottom'], $definition['bottom'], self::COORDINATE_TOLERANCE, $fieldKey);

        foreach ($geometryCells as $index => $geometryCell) {
            $this->assertEqualsWithDelta($geometryCell['x0'], $definition['cells'][$index]['x0'], self::COORDINATE_TOLERANCE, "{$fieldKey} cell{$index}");
            $this->assertEqualsWithDelta($geometryCell['x1'], $definition['cells'][$index]['x1'], self::COORDINATE_TOLERANCE, "{$fieldKey} cell{$index}");
        }
    }

    /**
     * 桁区切りの細マス(幅約3pt)を除いた数字マスだけを返す。
     *
     * @param  array<int, array{x0: float, x1: float, w: float}>  $cells
     * @return array<int, array{x0: float, x1: float, w: float}>
     */
    private function numericCells(array $cells): array
    {
        return array_values(array_filter(
            $cells,
            fn (array $cell): bool => $cell['x1'] - $cell['x0'] >= 6.0
        ));
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function overlayFields(int $page): array
    {
        /** @var array{page: int, fields: array<string, array<string, array<string, mixed>>>} $overlay */
        $overlay = require app_path("Services/BlueReturnPdf/Templates/From2020/Page{$page}Overlay.php");

        $this->assertSame($page, $overlay['page']);

        return $overlay['fields'];
    }

    /**
     * @return array{digit_cell_groups: array<int, array{top: float, bottom: float, cells: array<int, array{x0: float, x1: float, w: float}>}>}
     */
    private function geometry(int $page): array
    {
        return json_decode(
            (string) file_get_contents(resource_path("blue-return/templates/from2020/geometry/page{$page}.json")),
            true
        );
    }

    /**
     * @param  array{digit_cell_groups: array<int, array{top: float, bottom: float, cells: array<int, array{x0: float, x1: float, w: float}>}>}  $geometry
     * @return array{top: float, bottom: float, cells: array<int, array{x0: float, x1: float, w: float}>}|null
     */
    private function findGeometryGroup(array $geometry, float $top, float $x0): ?array
    {
        foreach ($geometry['digit_cell_groups'] as $group) {
            if (
                abs($group['top'] - $top) < self::COORDINATE_TOLERANCE
                && abs($group['cells'][0]['x0'] - $x0) < self::COORDINATE_TOLERANCE
            ) {
                return $group;
            }
        }

        return null;
    }
}
