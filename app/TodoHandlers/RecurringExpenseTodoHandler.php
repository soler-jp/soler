<?php

namespace App\TodoHandlers;

use App\Models\RecurringTransactionPlan;
use App\Models\Todo;

class RecurringExpenseTodoHandler extends AbstractRecurringTransactionPlanTodoHandler
{
    public function todoType(): string
    {
        return Todo::TODO_TYPE_WIZARD_RECURRING_EXPENSES;
    }

    protected function additionalValidationRules(): array
    {
        return [
            'plans.*.business_ratio' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function additionalLabels(): array
    {
        return [
            'plans.*.business_ratio' => '事業割合',
        ];
    }

    protected function additionalItemSchema(): array
    {
        $rules = $this->validationRules();

        return [
            'business_ratio' => [
                'rules' => $rules['plans.*.business_ratio'],
                'label' => '事業割合',
                'type' => 'number',
            ],
        ];
    }

    protected function normalizeAdditionalPlanInput(array $plan): array
    {
        return [
            'business_ratio' => $this->nullableInt($plan['business_ratio'] ?? null),
        ];
    }

    protected function planType(): string
    {
        return RecurringTransactionPlan::TYPE_EXPENSE;
    }

    protected function plansLabel(): string
    {
        return '定期支出';
    }
}
