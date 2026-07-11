<?php

namespace App\Services\BlueReturnPdf;

use RuntimeException;
use TCPDF;

class OverlayRenderer
{
    public function renderBackground(TCPDF $pdf, string $backgroundPath): void
    {
        if (! is_file($backgroundPath)) {
            throw new RuntimeException("背景画像が見つかりません: {$backgroundPath}");
        }

        $pdf->Image($backgroundPath, 0, 0, 842, 595, 'PNG');
    }

    /**
     * @param  array{fields: array<string, array<string, array<string, mixed>>>}  $overlayDefinition
     * @param  array<string, string>  $values
     */
    public function renderOverlay(TCPDF $pdf, array $overlayDefinition, array $values): void
    {
        foreach ($overlayDefinition['fields'] as $fieldKey => $fieldDefinition) {
            $value = $values[$fieldKey] ?? '';

            if ($value === '') {
                continue;
            }

            if (isset($fieldDefinition['amount'])) {
                $this->renderAmount($pdf, $fieldDefinition['amount'], $value);

                continue;
            }

            if (isset($fieldDefinition['text'])) {
                $this->renderText($pdf, $fieldDefinition['text'], $value);
            }
        }
    }

    /**
     * @param  array{type: string, top: float, bottom: float, cells: array<int, array{x0: float, x1: float}>}  $definition
     */
    private function renderAmount(TCPDF $pdf, array $definition, string $value): void
    {
        if ($definition['type'] === 'digit_cells') {
            foreach ($this->buildDigitCellPlacements($value, $definition['cells']) as $placement) {
                $pdf->SetFontSize(11);
                $pdf->SetXY($placement['x0'], $definition['top']);
                $pdf->Cell(
                    $placement['x1'] - $placement['x0'],
                    $definition['bottom'] - $definition['top'],
                    $placement['text'],
                    0,
                    0,
                    'C',
                    false,
                    '',
                    0,
                    false,
                    'T',
                    'M'
                );
            }

            return;
        }

        if ($definition['type'] === 'box') {
            $placement = $this->buildBoxPlacement($value, $definition);
            $pdf->SetFontSize((float) ($definition['size'] ?? 11.0));
            $pdf->SetXY($placement['x0'], $placement['y']);
            $pdf->Cell(
                $placement['x1'] - $placement['x0'],
                $placement['height'],
                $placement['text'],
                0,
                0,
                $placement['align'],
                false,
                '',
                0,
                false,
                'T',
                'M'
            );

            return;
        }

        throw new RuntimeException('amount 定義は digit_cells または box である必要があります。');
    }

    /**
     * @param  array{x0: float, x1: float, y: float, size: float, align: string}  $definition
     */
    private function renderText(TCPDF $pdf, array $definition, string $value): void
    {
        // maxh が1行の高さ(フォントサイズ × cell_height_ratio)を下回ると1行も描画されないため、余裕を持たせる
        $lineHeight = (float) $definition['size'] * 1.5;

        $pdf->SetFontSize((float) $definition['size']);
        $pdf->MultiCell(
            (float) $definition['x1'] - (float) $definition['x0'],
            $lineHeight,
            $value,
            0,
            (string) $definition['align'],
            false,
            0,
            (float) $definition['x0'],
            (float) $definition['y'],
            true,
            0,
            false,
            true,
            $lineHeight,
            'M'
        );
    }

    /**
     * @param  array{x0: float, x1: float, y: float, size?: float, align: string}  $definition
     * @return array{x0: float, x1: float, y: float, height: float, text: string, align: string}
     */
    public function buildBoxPlacement(string $value, array $definition): array
    {
        return [
            'x0' => (float) $definition['x0'],
            'x1' => (float) $definition['x1'],
            'y' => (float) $definition['y'],
            'height' => (float) ($definition['size'] ?? 11.0) + 2.0,
            'text' => $value,
            'align' => (string) $definition['align'],
        ];
    }

    /**
     * @param  array<int, array{x0: float, x1: float}>  $cells
     * @return array<int, array{x0: float, x1: float, text: string}>
     */
    public function buildDigitCellPlacements(string $value, array $cells): array
    {
        $normalized = str_replace(',', '', $value);

        if ($normalized === '') {
            return [];
        }

        $characters = preg_split('//u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            throw new RuntimeException('金額文字列を分解できませんでした。');
        }

        if (count($characters) > count($cells)) {
            throw new RuntimeException("金額が桁マスを超えています: {$value}");
        }

        $placements = [];
        $cellIndex = count($cells) - 1;

        for ($charIndex = count($characters) - 1; $charIndex >= 0; $charIndex--) {
            $cell = $cells[$cellIndex];
            $placements[] = [
                'x0' => (float) $cell['x0'],
                'x1' => (float) $cell['x1'],
                'text' => $characters[$charIndex],
            ];
            $cellIndex--;
        }

        return array_reverse($placements);
    }
}
