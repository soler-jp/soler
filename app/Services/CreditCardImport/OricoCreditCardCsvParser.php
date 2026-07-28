<?php

namespace App\Services\CreditCardImport;

use App\Data\ParsedCreditCardStatement;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class OricoCreditCardCsvParser extends AbstractCreditCardCsvParser
{
    public function key(): string
    {
        return 'orico_csv_v1';
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
        $billedOn = $this->parseDate($this->findFirstValue($rows, 'お支払日'));
        $billedAt = $billedOn !== null ? new CarbonImmutable($billedOn) : null;

        $statementYear = $overrides['statement_year'] ?? $billedAt?->year;
        $statementMonth = $overrides['statement_month'] ?? $billedAt?->month;
        $totalAmount = $this->parseAmount($this->findFirstValue($rows, 'ご請求総額'));

        $lines = [];
        $occurrences = [];
        $detailStarted = false;

        foreach ($rows as $row) {
            if (($row[0] ?? null) === 'ご利用日' && ($row[1] ?? null) === 'ご利用先など') {
                $detailStarted = true;

                continue;
            }

            if (! $detailStarted) {
                continue;
            }

            $usedOn = $this->parseDate($row[0] ?? null);

            if ($usedOn === null) {
                if (($row[0] ?? '') !== '') {
                    break;
                }

                continue;
            }

            $merchantName = $this->normalizeText($row[1] ?? '');
            $amount = $this->parseAmount($row[8] ?? null);

            if ($merchantName === '' || $amount === null) {
                throw new InvalidArgumentException('Failed to parse ORICO statement line.');
            }

            $lines[] = $this->buildLine(
                lineNumber: count($lines) + 1,
                usedOn: $usedOn,
                postedOn: null,
                merchantName: $merchantName,
                description: $merchantName,
                amount: $amount,
                occurrence: $this->nextOccurrence($usedOn, $merchantName, $amount, $occurrences),
                rawPayload: [
                    'source' => 'orico_csv_v1',
                    'row' => $row,
                ],
            );
        }

        return new ParsedCreditCardStatement(
            statementYear: $this->requireStatementYear($statementYear, $this->key()),
            statementMonth: $this->requireStatementMonth($statementMonth, $this->key()),
            periodStartOn: $overrides['period_start_on'] ?? null,
            periodEndOn: $overrides['period_end_on'] ?? null,
            billedOn: $overrides['billed_on'] ?? $billedOn,
            paidOn: $overrides['paid_on'] ?? null,
            totalAmount: $totalAmount,
            lines: $lines,
        );
    }
}
