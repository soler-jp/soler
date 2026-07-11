<?php

namespace App\Services\BlueReturnPdf;

class FieldFormatter
{
    /**
     * @param  array<string, int>  $profitAndLoss
     * @return array<string, string>
     */
    public function formatProfitAndLoss(array $profitAndLoss): array
    {
        $formatted = [];

        foreach ($profitAndLoss as $fieldKey => $amount) {
            $formatted[$fieldKey] = $this->formatProfitAndLossAmount($fieldKey, $amount);
        }

        return $formatted;
    }

    public function formatAmount(int $amount): string
    {
        if ($amount < 0) {
            return '△'.number_format(abs($amount));
        }

        return number_format($amount);
    }

    public function formatOptionalAmount(?int $amount): string
    {
        if ($amount === null || $amount === 0) {
            return '';
        }

        return $this->formatAmount($amount);
    }

    private function formatProfitAndLossAmount(string $fieldKey, int $amount): string
    {
        if ($amount === 0 && in_array($fieldKey, $this->blankWhenZeroFields(), true)) {
            return '';
        }

        return $this->formatAmount($amount);
    }

    /**
     * @return array<int, string>
     */
    private function blankWhenZeroFields(): array
    {
        return [
            'custom_expense_1',
            'custom_expense_2',
            'custom_expense_3',
            'custom_expense_4',
            'custom_expense_5',
            'custom_expense_6',
            'bad_debt_reserve_reversal',
            'reserve_reversal_1',
            'reserve_reversal_2',
            'bad_debt_reserve_provision',
            'reserve_provision_1',
            'reserve_provision_2',
        ];
    }
}
