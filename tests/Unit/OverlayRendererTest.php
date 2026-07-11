<?php

namespace Tests\Unit;

use App\Services\BlueReturnPdf\OverlayRenderer;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class OverlayRendererTest extends TestCase
{
    #[Test]
    public function 桁マスは右詰めで配置される(): void
    {
        $renderer = new OverlayRenderer;

        $placements = $renderer->buildDigitCellPlacements('1,234', [
            ['x0' => 0.0, 'x1' => 10.0],
            ['x0' => 10.0, 'x1' => 20.0],
            ['x0' => 20.0, 'x1' => 30.0],
            ['x0' => 30.0, 'x1' => 40.0],
        ]);

        $this->assertSame(['1', '2', '3', '4'], array_column($placements, 'text'));
        $this->assertSame(4, count($placements));
        $this->assertSame(0.0, $placements[0]['x0']);
        $this->assertSame(40.0, $placements[3]['x1']);
    }

    #[Test]
    public function 三角付きでも右詰めで配置される(): void
    {
        $renderer = new OverlayRenderer;

        $placements = $renderer->buildDigitCellPlacements('△1234', [
            ['x0' => 0.0, 'x1' => 10.0],
            ['x0' => 10.0, 'x1' => 20.0],
            ['x0' => 20.0, 'x1' => 30.0],
            ['x0' => 30.0, 'x1' => 40.0],
            ['x0' => 40.0, 'x1' => 50.0],
        ]);

        $this->assertSame(['△', '1', '2', '3', '4'], array_column($placements, 'text'));
    }

    #[Test]
    public function 空欄は何も配置しない(): void
    {
        $renderer = new OverlayRenderer;

        $this->assertSame([], $renderer->buildDigitCellPlacements('', [
            ['x0' => 0.0, 'x1' => 10.0],
        ]));
    }

    #[Test]
    public function 桁あふれは例外になる(): void
    {
        $renderer = new OverlayRenderer;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('金額が桁マスを超えています');

        $renderer->buildDigitCellPlacements('12345', [
            ['x0' => 0.0, 'x1' => 10.0],
            ['x0' => 10.0, 'x1' => 20.0],
            ['x0' => 20.0, 'x1' => 30.0],
            ['x0' => 30.0, 'x1' => 40.0],
        ]);
    }

    #[Test]
    public function box型は右寄せ配置情報を返す(): void
    {
        $renderer = new OverlayRenderer;

        $placement = $renderer->buildBoxPlacement('1,234', [
            'x0' => 100.0,
            'x1' => 160.0,
            'y' => 42.0,
            'align' => 'R',
        ]);

        $this->assertSame(100.0, $placement['x0']);
        $this->assertSame(160.0, $placement['x1']);
        $this->assertSame(42.0, $placement['y']);
        $this->assertSame('R', $placement['align']);
        $this->assertSame('1,234', $placement['text']);
    }
}
