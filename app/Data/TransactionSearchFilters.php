<?php

namespace App\Data;

final readonly class TransactionSearchFilters
{
    /**
     * @param  array<int, string>  $debitAccountNames
     * @param  array<int, string>  $creditAccountNames
     * @param  array<int, int>  $months
     */
    public function __construct(
        public array $debitAccountNames = [],
        public array $creditAccountNames = [],
        public string $keyword = '',
        public array $months = [],
        public ?int $exactAmount = null,
        public ?int $minAmount = null,
        public ?int $maxAmount = null,
        public int $perPage = 100,
    ) {}

    /**
     * @param  array<int, string>  $debitAccountNames
     * @param  array<int, string>  $creditAccountNames
     * @param  array<int, int|string>  $months
     */
    public static function from(
        array $debitAccountNames = [],
        array $creditAccountNames = [],
        string $keyword = '',
        array $months = [],
        ?int $exactAmount = null,
        ?int $minAmount = null,
        ?int $maxAmount = null,
        int $perPage = 100,
    ): self {
        return new self(
            debitAccountNames: self::normalizeAccountNames($debitAccountNames),
            creditAccountNames: self::normalizeAccountNames($creditAccountNames),
            keyword: trim($keyword),
            months: self::normalizeMonths($months),
            exactAmount: $exactAmount,
            minAmount: $minAmount,
            maxAmount: $maxAmount,
            perPage: in_array($perPage, [50, 100, 200], true) ? $perPage : 100,
        );
    }

    /**
     * @param  array<int, string>  $accountNames
     * @return array<int, string>
     */
    private static function normalizeAccountNames(array $accountNames): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (string $accountName): string => trim($accountName), $accountNames),
            static fn (string $accountName): bool => $accountName !== '',
        )));
    }

    /**
     * @param  array<int, int|string>  $months
     * @return array<int, int>
     */
    private static function normalizeMonths(array $months): array
    {
        $normalized = array_map(static fn ($month): int => (int) $month, $months);
        $filtered = array_filter($normalized, static fn (int $month): bool => $month >= 1 && $month <= 12);
        $unique = array_values(array_unique($filtered));
        sort($unique);

        return $unique;
    }
}
