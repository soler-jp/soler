<?php

namespace App\Services\CreditCardImport;

use App\Data\ParsedCreditCardStatement;

interface CreditCardCsvParser
{
    public function key(): string;

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
    public function parse(string $csvContents, array $overrides = []): ParsedCreditCardStatement;
}
