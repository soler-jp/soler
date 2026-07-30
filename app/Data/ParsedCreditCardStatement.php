<?php

namespace App\Data;

/**
 * @param  array<int, ParsedCreditCardStatementLine>  $lines
 */
class ParsedCreditCardStatement
{
    public function __construct(
        public readonly int $statementYear,
        public readonly int $statementMonth,
        public readonly ?string $periodStartOn,
        public readonly ?string $periodEndOn,
        public readonly ?string $billedOn,
        public readonly ?string $paidOn,
        public readonly ?int $totalAmount,
        public readonly array $lines,
    ) {}

    public function lineCount(): int
    {
        return count($this->lines);
    }
}
