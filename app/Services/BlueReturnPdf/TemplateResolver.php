<?php

namespace App\Services\BlueReturnPdf;

use App\Concerns\SkipActorGuard;
use App\Models\FiscalYear;
use InvalidArgumentException;

#[SkipActorGuard('PDF テンプレート解決。参照だけで書き込みはない純粋関数。')]
class TemplateResolver
{
    public const FROM_2020 = 'from2020';

    public const FROM_2023 = 'from2023';

    public function resolve(FiscalYear $fiscalYear): string
    {
        return $this->resolveForYear((int) $fiscalYear->year);
    }

    public function resolveForYear(int $year): string
    {
        if ($year < 2020) {
            throw new InvalidArgumentException("{$year}年分の青色申告決算書テンプレートは未対応です。");
        }

        if ($year < 2023) {
            return self::FROM_2020;
        }

        return self::FROM_2023;
    }

    /**
     * テンプレート版に対応するオーバーレイ定義のディレクトリ名（app/Services/BlueReturnPdf/Templates/ 配下）を返す。
     */
    public function overlayDirectory(string $templateVersion): string
    {
        return match ($templateVersion) {
            self::FROM_2020 => 'From2020',
            self::FROM_2023 => 'From2023',
            default => throw new InvalidArgumentException("未対応のテンプレート版です: {$templateVersion}"),
        };
    }
}
