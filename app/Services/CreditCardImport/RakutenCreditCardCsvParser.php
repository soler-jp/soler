<?php

namespace App\Services\CreditCardImport;

use App\Data\ParsedCreditCardStatement;
use InvalidArgumentException;

class RakutenCreditCardCsvParser extends AbstractCreditCardCsvParser
{
    public function key(): string
    {
        return 'rakuten_csv_v1';
    }

    /**
     * @param  array{
     *     statement_year?: int,
     *     statement_month?: int,
     *     billed_on?: string,
     *     paid_on?: string,
     *     period_start_on?: string,
     *     period_end_on?: string
     * }  $overrides
     */
    public function parse(string $csvContents, array $overrides = []): ParsedCreditCardStatement
    {
        $rows = $this->csvRows($csvContents);
        $header = $rows[0] ?? [];

        if (($header[0] ?? null) !== '利用日') {
            throw new InvalidArgumentException('Unsupported Rakuten CSV header.');
        }

        $lines = [];
        $occurrences = [];
        $statementMonth = $overrides['statement_month'] ?? null;
        $usedDates = [];
        $totalAmount = 0;

        foreach (array_slice($rows, 1) as $row) {
            $usedOn = $this->parseDate($row[0] ?? null);

            if ($usedOn === null) {
                continue;
            }

            $merchantName = $this->normalizeText($row[1] ?? '');
            $amount = $this->parseAmount($row[4] ?? null);
            $billedAmount = $this->parseAmount($row[9] ?? null) ?? $amount;
            $paymentMonth = $this->parseStatementMonth($row[7] ?? null);

            if ($statementMonth === null) {
                $statementMonth = $paymentMonth['month'] ?? null;
            }

            if ($merchantName === '' || $amount === null) {
                throw new InvalidArgumentException('Failed to parse Rakuten statement line.');
            }

            $usedDates[] = $usedOn;
            $totalAmount += $billedAmount;

            $lines[] = $this->buildLine(
                lineNumber: count($lines) + 1,
                usedOn: $usedOn,
                merchantName: $merchantName,
                description: $merchantName,
                amount: $amount,
                occurrence: $this->nextOccurrence($usedOn, $merchantName, $amount, $occurrences),
                rawPayload: [
                    'source' => 'rakuten_csv_v1',
                    'row' => $row,
                ],
            );
        }

        $statementMonth = $this->requireStatementMonth($statementMonth, $this->key());
        $statementYear = $overrides['statement_year'] ?? $this->inferStatementYearFromUsedDates($statementMonth, $usedDates);

        return new ParsedCreditCardStatement(
            statementYear: $this->requireStatementYear($statementYear, $this->key()),
            statementMonth: $statementMonth,
            periodStartOn: $overrides['period_start_on'] ?? null,
            periodEndOn: $overrides['period_end_on'] ?? null,
            billedOn: $overrides['billed_on'] ?? null,
            paidOn: $overrides['paid_on'] ?? null,
            totalAmount: $totalAmount,
            lines: $lines,
        );
    }
}
