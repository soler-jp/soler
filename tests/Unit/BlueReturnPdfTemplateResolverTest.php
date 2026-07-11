<?php

namespace Tests\Unit;

use App\Services\BlueReturnPdf\TemplateResolver;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BlueReturnPdfTemplateResolverTest extends TestCase
{
    #[Test]
    public function 西暦2023年分以降は現行テンプレートになる(): void
    {
        $resolver = new TemplateResolver;

        $this->assertSame(TemplateResolver::FROM_2023, $resolver->resolveForYear(2023));
        $this->assertSame(TemplateResolver::FROM_2023, $resolver->resolveForYear(2025));
    }

    #[Test]
    public function 西暦2022年分以前は例外になる(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('2022年分の青色申告決算書テンプレートは未対応です。');

        (new TemplateResolver)->resolveForYear(2022);
    }

    #[Test]
    public function テンプレート版からオーバーレイ定義のディレクトリ名を解決できる(): void
    {
        $resolver = new TemplateResolver;

        $this->assertSame('From2023', $resolver->overlayDirectory(TemplateResolver::FROM_2023));
    }

    #[Test]
    public function 未知のテンプレート版のディレクトリ名解決は例外になる(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('未対応のテンプレート版です: unknown');

        (new TemplateResolver)->overlayDirectory('unknown');
    }
}
