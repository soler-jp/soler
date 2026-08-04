<?php

namespace App\Livewire\SolerUi\TransactionEntry\Concerns;

trait FormatsJapaneseAmount
{
    protected function formatJapaneseAmount(mixed $amount): string
    {
        $amount = $this->parseAmountInput($amount);

        if ($amount === null || $amount <= 0) {
            return '';
        }

        $oku = intdiv($amount, 100000000);
        $remaining = $amount % 100000000;
        $man = intdiv($remaining, 10000);
        $yen = $remaining % 10000;
        $parts = [];

        if ($oku > 0) {
            $parts[] = number_format($oku).'億';
        }

        if ($man > 0) {
            $parts[] = number_format($man).'万';
        }

        if ($yen > 0 || $parts === []) {
            $parts[] = number_format($yen).'円';
        } else {
            $lastIndex = array_key_last($parts);
            $parts[$lastIndex] .= '円';
        }

        return implode(' ', $parts);
    }

    protected function parseAmountInput(mixed $amount): ?int
    {
        if (is_int($amount)) {
            return $amount;
        }

        if ($amount === null) {
            return null;
        }

        $normalized = trim((string) $amount);

        if ($normalized === '' || ! preg_match('/^\d+$/', $normalized)) {
            return null;
        }

        return (int) $normalized;
    }

    protected function hasInvalidAmountInput(mixed $amount, int $min = 1, ?int $max = null): bool
    {
        if ($amount === null) {
            return false;
        }

        $normalized = trim((string) $amount);

        if ($normalized === '') {
            return false;
        }

        $parsedAmount = $this->parseAmountInput($amount);

        if ($parsedAmount === null) {
            return true;
        }

        if ($parsedAmount < $min) {
            return true;
        }

        return $max !== null && $parsedAmount > $max;
    }
}
