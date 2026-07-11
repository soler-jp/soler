<?php

namespace App\Services\BlueReturnPdf;

use App\Models\FiscalYear;
use InvalidArgumentException;

class TemplateResolver
{
    public const FROM_2023 = 'from2023';

    public function resolve(FiscalYear $fiscalYear): string
    {
        return $this->resolveForYear((int) $fiscalYear->year);
    }

    public function resolveForYear(int $year): string
    {
        if ($year < 2023) {
            throw new InvalidArgumentException("{$year}年分の青色申告決算書テンプレートは未対応です。");
        }

        return self::FROM_2023;
    }

    /**
     * テンプレート版に対応するオーバーレイ定義のディレクトリ名（app/Services/BlueReturnPdf/Templates/ 配下）を返す。
     */
    public function overlayDirectory(string $templateVersion): string
    {
        return match ($templateVersion) {
            self::FROM_2023 => 'From2023',
            default => throw new InvalidArgumentException("未対応のテンプレート版です: {$templateVersion}"),
        };
    }
}
