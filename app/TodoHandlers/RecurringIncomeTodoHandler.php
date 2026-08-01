<?php

namespace App\TodoHandlers;

use App\Models\RecurringTransactionPlan;
use App\Models\Todo;

class RecurringIncomeTodoHandler extends AbstractRecurringTransactionPlanTodoHandler
{
    public function todoType(): string
    {
        return Todo::TODO_TYPE_WIZARD_RECURRING_INCOMES;
    }

    protected function additionalValidationRules(): array
    {
        return [
            'plans.*.is_withholding' => ['nullable', 'boolean'],
            'plans.*.withholding_tax_amount' => ['nullable', 'integer', 'min:0'],
            'plans.*.withholding_sub_account_id' => ['nullable', 'integer'],
        ];
    }

    protected function additionalLabels(): array
    {
        return [
            'plans.*.is_withholding' => '源泉徴収',
            'plans.*.withholding_tax_amount' => '源泉徴収税額',
            'plans.*.withholding_sub_account_id' => '源泉徴収補助科目',
        ];
    }

    protected function additionalItemSchema(): array
    {
        $rules = $this->validationRules();

        return [
            'is_withholding' => [
                'rules' => $rules['plans.*.is_withholding'],
                'label' => '源泉徴収',
                'type' => 'boolean',
            ],
            'withholding_tax_amount' => [
                'rules' => $rules['plans.*.withholding_tax_amount'],
                'label' => '源泉徴収税額',
                'type' => 'number',
            ],
            'withholding_sub_account_id' => [
                'rules' => $rules['plans.*.withholding_sub_account_id'],
                'label' => '源泉徴収補助科目',
                'type' => 'number',
            ],
        ];
    }

    protected function normalizeAdditionalPlanInput(array $plan): array
    {
        return [
            'is_withholding' => (bool) ($plan['is_withholding'] ?? false),
            'withholding_tax_amount' => $this->nullableInt($plan['withholding_tax_amount'] ?? null),
            'withholding_sub_account_id' => $this->nullableInt($plan['withholding_sub_account_id'] ?? null),
        ];
    }

    protected function planType(): string
    {
        return RecurringTransactionPlan::TYPE_INCOME;
    }

    protected function plansLabel(): string
    {
        return '定期収入';
    }
}
