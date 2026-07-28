<?php

namespace App\Services\CreditCardImport;

use App\Data\ParsedCreditCardStatement;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class AeonCreditCardCsvParser extends AbstractCreditCardCsvParser
{
    public function key(): string
    {
        return 'aeon_csv_v1';
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
        $billedOn = $this->parseDate($this->findFirstValue($rows, 'お支払い日'));
        $billedAt = $billedOn !== null ? new CarbonImmutable($billedOn) : null;

        $statementYear = $overrides['statement_year'] ?? $billedAt?->year;
        $statementMonth = $overrides['statement_month'] ?? $billedAt?->month;
        $totalAmount = $this->parseAmount($this->findFirstValue($rows, '今回ご請求金額'));

        $lines = [];
        $occurrences = [];
        $detailStarted = false;

        foreach ($rows as $row) {
            if (($row[0] ?? null) === 'ご利用日' && ($row[1] ?? null) === '利用者区分') {
                $detailStarted = true;

                continue;
            }

            if (! $detailStarted) {
                continue;
            }

            if (($row[0] ?? null) === '分割・ボーナス払い明細') {
                break;
            }

            $usedOn = $this->parseDate($row[0] ?? null);

            if ($usedOn === null) {
                continue;
            }

            $merchantName = $this->normalizeText($row[2] ?? '');
            $amount = $this->parseAmount($row[6] ?? null);

            if ($merchantName === '' || $amount === null) {
                throw new InvalidArgumentException('Failed to parse AEON statement line.');
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
                    'source' => 'aeon_csv_v1',
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
