<?php

namespace App\Services\CreditCardImport;

use App\Data\ParsedCreditCardStatementLine;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

abstract class AbstractCreditCardCsvParser implements CreditCardCsvParser
{
    /**
     * @return array<int, array<int, string>>
     */
    protected function csvRows(string $csvContents): array
    {
        $normalizedContents = $this->normalizeEncoding($csvContents);
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new InvalidArgumentException('Failed to open temporary stream for CSV parsing.');
        }

        fwrite($handle, $normalizedContents);
        rewind($handle);

        $rows = [];

        try {
            while (($cells = fgetcsv($handle, escape: '')) !== false) {
                $rows[] = array_map(
                    fn (?string $cell): string => $this->cleanCell($cell),
                    $cells,
                );
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    protected function normalizeEncoding(string $csvContents): string
    {
        $contents = $this->stripUtf8Bom($csvContents);

        if (mb_check_encoding($contents, 'UTF-8')) {
            return $contents;
        }

        return mb_convert_encoding($contents, 'UTF-8', 'SJIS-win');
    }

    protected function cleanCell(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim($this->stripUtf8Bom($value));
    }

    protected function stripUtf8Bom(string $value): string
    {
        if (str_starts_with($value, "\xEF\xBB\xBF")) {
            return substr($value, 3);
        }

        return $value;
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     */
    protected function findFirstValue(array $rows, string $label): ?string
    {
        foreach ($rows as $row) {
            if (($row[0] ?? null) === $label) {
                return $row[1] ?? null;
            }
        }

        return null;
    }

    protected function normalizeText(?string $value): string
    {
        $text = str_replace('　', ' ', (string) $value);
        $text = preg_replace('/\s+/u', ' ', trim($text));

        return $text ?? '';
    }

    protected function parseAmount(?string $value): ?int
    {
        $normalized = preg_replace('/[^\d\-]/', '', (string) $value);

        if ($normalized === null || $normalized === '') {
            return null;
        }

        return (int) $normalized;
    }

    protected function parseDate(?string $value): ?string
    {
        $normalized = $this->normalizeText($value);

        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^\d{4}\/\d{1,2}\/\d{1,2}$/', $normalized) === 1) {
            return CarbonImmutable::createFromFormat('Y/m/d', $normalized)->format('Y-m-d');
        }

        if (preg_match('/^\d{4}年\s*\d{1,2}月\s*\d{1,2}日$/u', $normalized) === 1) {
            $compact = preg_replace('/\s+/u', '', $normalized) ?? $normalized;

            return CarbonImmutable::createFromFormat('Y年n月j日', $compact)->format('Y-m-d');
        }

        if (preg_match('/^\d{6}$/', $normalized) === 1) {
            return CarbonImmutable::createFromFormat('ymd', $normalized)->format('Y-m-d');
        }

        return null;
    }

    /**
     * @return array{year?: int, month: int}|null
     */
    protected function parseStatementMonth(?string $value): ?array
    {
        $normalized = $this->normalizeText($value);

        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^(?<year>\d{4})年\s*(?<month>\d{1,2})月$/u', $normalized, $matches) === 1) {
            return [
                'year' => (int) $matches['year'],
                'month' => (int) $matches['month'],
            ];
        }

        if (preg_match('/^(?<month>\d{1,2})月$/u', $normalized, $matches) === 1) {
            return [
                'month' => (int) $matches['month'],
            ];
        }

        return null;
    }

    protected function buildFingerprint(?string $usedOn, string $merchantName, int $amount, int $occurrence): string
    {
        return sha1(implode('|', [
            $usedOn ?? '',
            $merchantName,
            (string) $amount,
            (string) $occurrence,
        ]));
    }

    /**
     * @param  array<string, int>  $occurrences
     */
    protected function nextOccurrence(?string $usedOn, string $merchantName, int $amount, array &$occurrences): int
    {
        $baseKey = implode('|', [
            $usedOn ?? '',
            $merchantName,
            (string) $amount,
        ]);

        $occurrences[$baseKey] = ($occurrences[$baseKey] ?? 0) + 1;

        return $occurrences[$baseKey];
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     */
    protected function buildLine(
        int $lineNumber,
        ?string $usedOn,
        ?string $postedOn,
        string $merchantName,
        string $description,
        int $amount,
        int $occurrence,
        array $rawPayload,
    ): ParsedCreditCardStatementLine {
        return new ParsedCreditCardStatementLine(
            lineNumber: $lineNumber,
            usedOn: $usedOn,
            postedOn: $postedOn,
            merchantName: $merchantName,
            description: $description,
            amount: $amount,
            fingerprint: $this->buildFingerprint($usedOn, $merchantName, $amount, $occurrence),
            rawPayload: $rawPayload,
        );
    }

    /**
     * @param  array<int, string>  $usedDates
     */
    protected function inferStatementYearFromUsedDates(int $statementMonth, array $usedDates): ?int
    {
        $dates = array_values(array_filter($usedDates));

        if ($dates === []) {
            return null;
        }

        rsort($dates);
        $latestUsedOn = CarbonImmutable::parse($dates[0]);

        if ($statementMonth < (int) $latestUsedOn->format('n')) {
            return (int) $latestUsedOn->addYear()->format('Y');
        }

        return (int) $latestUsedOn->format('Y');
    }

    protected function requireStatementYear(?int $statementYear, string $parserKey): int
    {
        if ($statementYear === null) {
            throw new InvalidArgumentException(sprintf(
                '[%s] could not determine statement year. Provide statement_year override.',
                $parserKey
            ));
        }

        return $statementYear;
    }

    protected function requireStatementMonth(?int $statementMonth, string $parserKey): int
    {
        if ($statementMonth === null) {
            throw new InvalidArgumentException(sprintf(
                '[%s] could not determine statement month. Provide statement_month override.',
                $parserKey
            ));
        }

        return $statementMonth;
    }
}
