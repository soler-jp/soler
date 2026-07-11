<?php

namespace Tests\Unit;

use App\Services\BlueReturnPdf\FieldFormatter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FieldFormatterTest extends TestCase
{
    #[Test]
    public function 金額はカンマ付きで整形される(): void
    {
        $formatter = new FieldFormatter;

        $this->assertSame('1,234,567', $formatter->formatAmount(1234567));
    }

    #[Test]
    public function 負値は三角付きで整形される(): void
    {
        $formatter = new FieldFormatter;

        $this->assertSame('△1,234,567', $formatter->formatAmount(-1234567));
    }

    #[Test]
    public function 零は零で整形される(): void
    {
        $formatter = new FieldFormatter;

        $this->assertSame('0', $formatter->formatAmount(0));
    }

    #[Test]
    public function nullは空欄として整形される(): void
    {
        $formatter = new FieldFormatter;

        $this->assertSame('', $formatter->formatOptionalAmount(null));
    }

    #[Test]
    public function 損益計算書の0は欄により空欄になる(): void
    {
        $formatter = new FieldFormatter;

        $formatted = $formatter->formatProfitAndLoss([
            'sales_amount' => 0,
            'custom_expense_1' => 0,
        ]);

        $this->assertSame('0', $formatted['sales_amount']);
        $this->assertSame('', $formatted['custom_expense_1']);
    }
}
