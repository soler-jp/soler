<?php

namespace Tests\Unit;

use App\Services\CreditCardImport\AeonCreditCardCsvParser;
use App\Services\CreditCardImport\CreditCardCsvParserRegistry;
use App\Services\CreditCardImport\OricoCreditCardCsvParser;
use App\Services\CreditCardImport\RakutenCreditCardCsvParser;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreditCardCsvParserRegistryTest extends TestCase
{
    #[Test]
    public function generic_csv_v1はresolveできず自動判別専用である(): void
    {
        $registry = $this->app->make(CreditCardCsvParserRegistry::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported credit card parser key [generic_csv_v1].');

        $registry->resolve('generic_csv_v1');
    }

    #[Test]
    public function 未対応のparser_keyを指定すると例外になる(): void
    {
        $registry = $this->app->make(CreditCardCsvParserRegistry::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported credit card parser key [unsupported_parser].');

        $registry->resolve('unsupported_parser');
    }

    #[Test]
    public function カード会社別parserを解決できる(): void
    {
        $registry = $this->app->make(CreditCardCsvParserRegistry::class);

        $this->assertInstanceOf(OricoCreditCardCsvParser::class, $registry->resolve('orico_csv_v1'));
        $this->assertInstanceOf(AeonCreditCardCsvParser::class, $registry->resolve('aeon_csv_v1'));
        $this->assertInstanceOf(RakutenCreditCardCsvParser::class, $registry->resolve('rakuten_csv_v1'));
    }

    #[Test]
    public function csv内容から実際の形式を判定できる(): void
    {
        $registry = $this->app->make(CreditCardCsvParserRegistry::class);

        $orico = file_get_contents(base_path('tests/Fixtures/CreditCardCsv/orico-master.csv'));
        $aeon = file_get_contents(base_path('tests/Fixtures/CreditCardCsv/aeon.csv'));
        $rakuten = file_get_contents(base_path('tests/Fixtures/CreditCardCsv/rakuten.csv'));

        $this->assertNotFalse($orico);
        $this->assertNotFalse($aeon);
        $this->assertNotFalse($rakuten);

        $this->assertSame('orico_csv_v1', $registry->detect($orico)->key());
        $this->assertSame('aeon_csv_v1', $registry->detect($aeon)->key());
        $this->assertSame('rakuten_csv_v1', $registry->detect($rakuten)->key());
        $this->assertNull($registry->detect("foo,bar,baz\n1,2,3\n"));
    }
}
