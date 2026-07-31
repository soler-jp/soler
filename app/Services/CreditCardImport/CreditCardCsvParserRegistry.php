<?php

namespace App\Services\CreditCardImport;

use App\Concerns\SkipActorGuard;
use InvalidArgumentException;

#[SkipActorGuard('CSV パーサ選択レジストリ。認可対象のリソースを持たない。')]
class CreditCardCsvParserRegistry
{
    /**
     * @param  iterable<int, CreditCardCsvParser>  $parsers
     */
    public function __construct(
        private readonly iterable $parsers,
    ) {}

    public function resolve(string $parserKey): CreditCardCsvParser
    {
        foreach ($this->parsers as $parser) {
            if ($parser->key() === $parserKey) {
                return $parser;
            }
        }

        throw new InvalidArgumentException(sprintf(
            'Unsupported credit card parser key [%s].',
            $parserKey
        ));
    }

    public function detect(string $csvContents): ?CreditCardCsvParser
    {
        $normalizedContents = $this->normalizeEncoding($csvContents);
        $firstLine = strtok($normalizedContents, "\r\n") ?: '';

        foreach ($this->parsers as $parser) {
            if ($this->matches($parser->key(), $normalizedContents, $firstLine)) {
                return $parser;
            }
        }

        return null;
    }

    protected function normalizeEncoding(string $csvContents): string
    {
        $contents = str_starts_with($csvContents, "\xEF\xBB\xBF")
            ? substr($csvContents, 3)
            : $csvContents;

        if (mb_check_encoding($contents, 'UTF-8')) {
            return $contents;
        }

        return mb_convert_encoding($contents, 'UTF-8', 'SJIS-win');
    }

    protected function matches(string $parserKey, string $normalizedContents, string $firstLine): bool
    {
        return match ($parserKey) {
            'orico_csv_v1' => str_contains($normalizedContents, '<利用明細>')
                && str_contains($normalizedContents, 'ご利用先など'),
            'aeon_csv_v1' => str_contains($normalizedContents, 'ご利用カード')
                && str_contains($normalizedContents, 'イオン'),
            'rakuten_csv_v1' => str_starts_with($firstLine, '利用日,利用店名・商品名'),
            default => false,
        };
    }
}
