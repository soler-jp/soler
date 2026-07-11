<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Page1Overlay のヘッダー欄(年分・整理番号・期首期末月日・住所や氏名などのテキスト欄)が
 * geometry JSON(公式様式の抽出値)と一致しているかを機械検証する。
 */
class BlueReturnPage1OverlayHeaderTest extends TestCase
{
    private const COORDINATE_TOLERANCE = 0.5;

    private const PAGE_WIDTH = 842.0;

    private const PAGE_HEIGHT = 595.0;

    /**
     * ヘッダーの桁マス欄 → geometry 上のグループ(top と先頭マスの x0)と期待マス数。
     */
    private const HEADER_DIGIT_CELL_FIELDS = [
        'era_year' => ['top' => 50.5, 'x0' => 331.26, 'cell_count' => 2],
        'filing_number' => ['top' => 172.73, 'x0' => 667.25, 'cell_count' => 8],
        'opening_month' => ['top' => 185.82, 'x0' => 441.92, 'cell_count' => 2],
        'opening_day' => ['top' => 185.82, 'x0' => 483.83, 'cell_count' => 2],
        'ending_month' => ['top' => 185.82, 'x0' => 539.7, 'cell_count' => 2],
        'ending_day' => ['top' => 185.82, 'x0' => 581.6, 'cell_count' => 2],
    ];

    private const HEADER_TEXT_FIELDS = [
        'address',
        'name_kana',
        'name',
        'business_address',
        'home_phone_number',
        'business_phone_number',
        'business_type',
        'trade_name',
        'association_name',
        'tax_accountant_office_address',
        'tax_accountant_name',
        'tax_accountant_phone_number',
    ];

    #[Test]
    public function ヘッダーの桁マス欄はgeometryのグループと一致する(): void
    {
        $fields = $this->overlayFields();
        $geometry = $this->geometry();

        foreach (self::HEADER_DIGIT_CELL_FIELDS as $fieldKey => $expected) {
            $this->assertArrayHasKey($fieldKey, $fields, "{$fieldKey} が Page1Overlay にありません");

            $definition = $fields[$fieldKey]['amount'];
            $this->assertSame('digit_cells', $definition['type'], $fieldKey);

            $group = $this->findGeometryGroup($geometry, $expected['top'], $expected['x0']);
            $this->assertNotNull($group, "{$fieldKey} に対応する digit_cell_group が geometry にありません");

            $geometryCells = array_values(array_filter(
                $group['cells'],
                fn (array $cell): bool => $cell['x1'] - $cell['x0'] >= 6.0
            ));

            $this->assertCount($expected['cell_count'], $definition['cells'], $fieldKey);
            $this->assertCount($expected['cell_count'], $geometryCells, $fieldKey);
            $this->assertEqualsWithDelta($group['top'], $definition['top'], self::COORDINATE_TOLERANCE, $fieldKey);
            $this->assertEqualsWithDelta($group['bottom'], $definition['bottom'], self::COORDINATE_TOLERANCE, $fieldKey);

            foreach ($geometryCells as $index => $geometryCell) {
                $this->assertEqualsWithDelta($geometryCell['x0'], $definition['cells'][$index]['x0'], self::COORDINATE_TOLERANCE, "{$fieldKey} cell{$index}");
                $this->assertEqualsWithDelta($geometryCell['x1'], $definition['cells'][$index]['x1'], self::COORDINATE_TOLERANCE, "{$fieldKey} cell{$index}");
            }
        }
    }

    #[Test]
    public function ヘッダーのテキスト欄はページ内に収まる(): void
    {
        $fields = $this->overlayFields();

        foreach (self::HEADER_TEXT_FIELDS as $fieldKey) {
            $this->assertArrayHasKey($fieldKey, $fields, "{$fieldKey} が Page1Overlay にありません");

            $definition = $fields[$fieldKey]['text'];

            $this->assertLessThan($definition['x1'], $definition['x0'], $fieldKey);
            $this->assertGreaterThanOrEqual(0.0, $definition['x0'], $fieldKey);
            $this->assertLessThanOrEqual(self::PAGE_WIDTH, $definition['x1'], $fieldKey);
            $this->assertGreaterThanOrEqual(0.0, $definition['y'], $fieldKey);
            $this->assertLessThanOrEqual(self::PAGE_HEIGHT, $definition['y'] + $definition['size'] * 1.5, $fieldKey);
        }
    }

    #[Test]
    public function 損益計算書の45欄はヘッダー欄の追加後も揃っている(): void
    {
        $fields = $this->overlayFields();

        /** @var array{fields: array<string, int>} $fieldsMap */
        $fieldsMap = json_decode(
            (string) file_get_contents(resource_path('blue-return/templates/from2023/fields_map/page1.json')),
            true
        );

        foreach (array_keys($fieldsMap['fields']) as $fieldKey) {
            $this->assertArrayHasKey($fieldKey, $fields, "{$fieldKey} が Page1Overlay にありません");
        }

        $expectedKeyCount = count($fieldsMap['fields'])
            + count(self::HEADER_DIGIT_CELL_FIELDS)
            + count(self::HEADER_TEXT_FIELDS);
        $this->assertCount($expectedKeyCount, $fields);
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function overlayFields(): array
    {
        /** @var array{fields: array<string, array<string, array<string, mixed>>>} $overlay */
        $overlay = require app_path('Services/BlueReturnPdf/Templates/From2023/Page1Overlay.php');

        return $overlay['fields'];
    }

    /**
     * @return array{digit_cell_groups: array<int, array{top: float, bottom: float, cells: array<int, array{x0: float, x1: float, w: float}>}>}
     */
    private function geometry(): array
    {
        return json_decode(
            (string) file_get_contents(resource_path('blue-return/templates/from2023/geometry/page1.json')),
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
