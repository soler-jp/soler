<?php

namespace Tests\Unit;

use App\Services\CreditCardImport\AeonCreditCardCsvParser;
use App\Services\CreditCardImport\OricoCreditCardCsvParser;
use App\Services\CreditCardImport\RakutenCreditCardCsvParser;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreditCardCsvParsersTest extends TestCase
{
    #[Test]
    public function orico_csvをparseできる(): void
    {
        $parser = $this->app->make(OricoCreditCardCsvParser::class);
        $statement = $parser->parse($this->fixture('orico-master.csv'));

        $this->assertSame(2025, $statement->statementYear);
        $this->assertSame(9, $statement->statementMonth);
        $this->assertSame('2025-09-29', $statement->billedOn);
        $this->assertSame(1444, $statement->totalAmount);
        $this->assertSame(2, $statement->lineCount());
        $this->assertSame('2025-07-16', $statement->lines[0]->usedOn);
        $this->assertSame('ETC 北海道 沖縄', $statement->lines[0]->merchantName);
        $this->assertSame(1234, $statement->lines[0]->amount);
    }

    #[Test]
    public function orico_visa_csvをparseできる(): void
    {
        $parser = $this->app->make(OricoCreditCardCsvParser::class);
        $statement = $parser->parse($this->fixture('orico-visa.csv'));

        $this->assertSame(2026, $statement->statementYear);
        $this->assertSame(7, $statement->statementMonth);
        $this->assertSame('2026-07-27', $statement->billedOn);
        $this->assertSame(15468, $statement->totalAmount);
        $this->assertSame(5, $statement->lineCount());
        $this->assertSame('2026-05-31', $statement->lines[0]->usedOn);
        $this->assertSame('それる通信', $statement->lines[0]->merchantName);
        $this->assertSame(2974, $statement->lines[0]->amount);
        $this->assertSame('2026-06-28', $statement->lines[4]->usedOn);
        $this->assertSame(3699, $statement->lines[4]->amount);
    }

    #[Test]
    public function aeon_csvをparseできる(): void
    {
        $parser = $this->app->make(AeonCreditCardCsvParser::class);
        $statement = $parser->parse($this->fixture('aeon.csv'));

        $this->assertSame(2026, $statement->statementYear);
        $this->assertSame(7, $statement->statementMonth);
        $this->assertSame('2026-07-02', $statement->billedOn);
        $this->assertSame(6150, $statement->totalAmount);
        $this->assertSame(2, $statement->lineCount());
        $this->assertSame('2026-05-18', $statement->lines[0]->usedOn);
        $this->assertSame('コンビニSOLER', $statement->lines[0]->merchantName);
        $this->assertSame(100, $statement->lines[0]->amount);
    }

    #[Test]
    public function rakuten_csvをparseできる(): void
    {
        $parser = $this->app->make(RakutenCreditCardCsvParser::class);
        $statement = $parser->parse($this->fixture('rakuten.csv'));

        $this->assertSame(2026, $statement->statementYear);
        $this->assertSame(7, $statement->statementMonth);
        $this->assertNull($statement->billedOn);
        $this->assertSame(2400, $statement->totalAmount);
        $this->assertSame(2, $statement->lineCount());
        $this->assertSame('2026-06-28', $statement->lines[0]->usedOn);
        $this->assertSame('ＶＩＳＡ国内利用 VS STEREO', $statement->lines[0]->merchantName);
        $this->assertSame(2000, $statement->lines[0]->amount);
    }

    #[Test]
    public function overrideで支払期間や支払日を補完できる(): void
    {
        $parser = $this->app->make(OricoCreditCardCsvParser::class);
        $statement = $parser->parse($this->fixture('orico-master.csv'), [
            'period_start_on' => '2025-07-01',
            'period_end_on' => '2025-07-31',
            'paid_on' => '2025-09-29',
            'billed_on' => '2025-09-30',
        ]);

        $this->assertSame('2025-07-01', $statement->periodStartOn);
        $this->assertSame('2025-07-31', $statement->periodEndOn);
        $this->assertSame('2025-09-29', $statement->paidOn);
        $this->assertSame('2025-09-30', $statement->billedOn);
    }

    #[Test]
    public function クォート内改行を含むcsvもparseできる(): void
    {
        $parser = $this->app->make(OricoCreditCardCsvParser::class);

        $csv = <<<'CSV'
登録番号,T12345
お支払日,2025年9月29日
ご請求総額,"\210"
<利用明細>
ご利用日,ご利用先など,新旧,ご利用者,支払開始年月,支払区分,お支払回数,何回目,ご利用金額,手数料・利息,年利%,その他,当月ご請求額,翌月繰越残高
2025年8月19日,"コンビニ
SOLER",*,本人,2025年9月,アド,1,1,\210,,,\0,\210,\0
CSV;

        $statement = $parser->parse($csv);

        $this->assertSame(1, $statement->lineCount());
        $this->assertSame('コンビニ SOLER', $statement->lines[0]->merchantName);
        $this->assertSame(210, $statement->lines[0]->amount);
    }

    #[Test]
    public function oricoの金額列が壊れていると例外になる(): void
    {
        $parser = $this->app->make(OricoCreditCardCsvParser::class);

        $csv = <<<'CSV'
登録番号,T12345
お支払日,2025年9月29日
ご請求総額,"\1,444"
<利用明細>
ご利用日,ご利用先など,新旧,ご利用者,支払開始年月,支払区分,お支払回数,何回目,ご利用金額,手数料・利息,年利%,その他,当月ご請求額,翌月繰越残高
2025年8月19日,コンビニSOLER,*,本人,2025年9月,アド,1,1,abc,,,\0,\210,\0
CSV;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Failed to parse ORICO statement line.');

        $parser->parse($csv);
    }

    #[Test]
    public function aeonの金額列が壊れていると例外になる(): void
    {
        $parser = $this->app->make(AeonCreditCardCsvParser::class);

        $csv = <<<'CSV'
ご利用カード,イオンカード・ブランド
今回ご請求金額,6150
お支払い日,2026年 7月 2日
ご利用明細
ご利用日,利用者区分,ご利用先,支払方法,,,ご利用金額,備考,
260518,本人,コンビニSOLER,１回,,,abc,,
CSV;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Failed to parse AEON statement line.');

        $parser->parse($csv);
    }

    #[Test]
    public function rakutenで支払月を判定できないと例外になる(): void
    {
        $parser = $this->app->make(RakutenCreditCardCsvParser::class);

        $csv = <<<'CSV'
利用日,利用店名・商品名,利用者,支払方法,利用金額,手数料/利息,支払総額,支払月,7月支払金額,当月請求額,8月繰越残高,8月以降請求額
2026/06/28,ＶＩＳＡ国内利用　VS STEREO,本人,1回払い,2000,0,2000,,2000,2000,0,
CSV;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('[rakuten_csv_v1] could not determine statement month. Provide statement_month override.');

        $parser->parse($csv);
    }

    #[Test]
    public function rakutenで年を判定できない場合はoverrideで補完できる(): void
    {
        $parser = $this->app->make(RakutenCreditCardCsvParser::class);

        $csv = <<<'CSV'
利用日,利用店名・商品名,利用者,支払方法,利用金額,手数料/利息,支払総額,支払月,7月支払金額,当月請求額,8月繰越残高,8月以降請求額
,ＶＩＳＡ国内利用　VS STEREO,本人,1回払い,2000,0,2000,7月,2000,2000,0,
CSV;

        $statement = $parser->parse($csv, [
            'statement_year' => 2026,
            'statement_month' => 7,
            'billed_on' => '2026-07-27',
        ]);

        $this->assertSame(2026, $statement->statementYear);
        $this->assertSame(7, $statement->statementMonth);
        $this->assertSame('2026-07-27', $statement->billedOn);
        $this->assertSame(0, $statement->lineCount());
    }

    private function fixture(string $filename): string
    {
        $path = base_path('tests/Fixtures/CreditCardCsv/'.$filename);
        $contents = file_get_contents($path);

        $this->assertNotFalse($contents, sprintf('Failed to read fixture [%s].', $path));

        return $contents;
    }
}
