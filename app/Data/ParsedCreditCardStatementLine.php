<?php

namespace App\Data;

class ParsedCreditCardStatementLine
{
    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public readonly int $lineNumber,
        public readonly ?string $usedOn,
        public readonly string $merchantName,
        public readonly string $description,
        public readonly int $amount,
        public readonly string $fingerprint,
        public readonly array $rawPayload,
    ) {}
}
