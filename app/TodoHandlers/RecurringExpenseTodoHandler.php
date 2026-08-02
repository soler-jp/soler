<?php

namespace App\TodoHandlers;

use App\Models\BusinessUnit;
use App\Models\JournalEntry;
use App\Models\RecurringTransactionPlan;
use App\Models\Todo;
use DomainException;

class RecurringExpenseTodoHandler extends AbstractRecurringTransactionPlanTodoHandler
{
    /**
     * @var array<int, array{
     *     key: string,
     *     account_name: string,
     *     interval: string,
     *     tax_type: string,
     *     tax_type_locked: bool
     * }>
     */
    protected const PLAN_TEMPLATES = [
        [
            'key' => 'rent',
            'account_name' => '地代家賃',
            'interval' => 'monthly',
            'tax_type' => JournalEntry::TAX_TYPE_EXEMPT,
            'tax_type_locked' => false,
        ],
        [
            'key' => 'electricity',
            'account_name' => '水道光熱費',
            'interval' => 'monthly',
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            'tax_type_locked' => false,
        ],
        [
            'key' => 'gas',
            'account_name' => '水道光熱費',
            'interval' => 'monthly',
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            'tax_type_locked' => false,
        ],
        [
            'key' => 'water',
            'account_name' => '水道光熱費',
            'interval' => 'bimonthly',
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            'tax_type_locked' => false,
        ],
        [
            'key' => 'mobile_phone',
            'account_name' => '通信費',
            'interval' => 'monthly',
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            'tax_type_locked' => false,
        ],
        [
            'key' => 'internet',
            'account_name' => '通信費',
            'interval' => 'monthly',
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            'tax_type_locked' => false,
        ],
        [
            'key' => 'vehicle_inspection',
            'account_name' => '租税公課',
            'interval' => 'yearly',
            'tax_type' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            'tax_type_locked' => false,
        ],
    ];

    public function todoType(): string
    {
        return Todo::TODO_TYPE_WIZARD_RECURRING_EXPENSES;
    }

    public function inputSchema(Todo $todo): array
    {
        $schema = parent::inputSchema($todo);
        $businessUnit = $todo->businessUnit()->firstOrFail();
        $creditSourceOptions = $this->creditSourceOptions($businessUnit);
        $defaultCreditSubAccountId = $creditSourceOptions[0]['value'] ?? null;
        $itemSchema = $schema['plans']['item_schema'];

        $itemSchema['credit_sub_account_id'] = [
            'rules' => $this->validationRules()['plans.*.credit_sub_account_id'],
            'label' => __('recurring_transaction_plans.todo_card.fields.credit_source'),
            'type' => 'radio',
            'options' => $creditSourceOptions,
        ];
        $itemSchema['amount'] = [
            'rules' => $this->validationRules()['plans.*.amount'],
            'label' => __('recurring_transaction_plans.todo_card.fields.gross_amount'),
            'type' => 'number',
            'help' => $this->amountHelp(),
        ];
        $itemSchema['interval'] = [
            'rules' => $this->validationRules()['plans.*.interval'],
            'label' => __('recurring_transaction_plans.todo_card.fields.interval'),
            'type' => 'radio',
            'options' => $this->intervalOptions(),
        ];
        $itemSchema['tax_type'] = [
            'rules' => $this->validationRules()['plans.*.tax_type'],
            'label' => __('recurring_transaction_plans.todo_card.fields.tax_type'),
            'type' => 'radio',
            'options' => $this->taxTypeOptions(),
        ];
        $itemSchema['month_of_year']['label'] = __('recurring_transaction_plans.todo_card.fields.payment_month');
        $itemSchema['day_of_month']['label'] = __('recurring_transaction_plans.todo_card.fields.payment_day');
        $itemSchema['start_month_type']['label'] = __('recurring_transaction_plans.todo_card.fields.bimonthly_month_type');

        $schema['plans']['item_schema'] = $itemSchema;
        $schema['plans']['default_items'] = $this->buildDefaultPlanItems($businessUnit, $defaultCreditSubAccountId);

        return $schema;
    }

    protected function additionalValidationRules(): array
    {
        return [
            'plans.*.template_key' => ['nullable', 'string'],
            'plans.*.amount_mode' => ['nullable', 'string'],
            'plans.*.tax_type_locked' => ['nullable', 'boolean'],
            'plans.*.should_register' => ['nullable', 'boolean'],
            'plans.*.business_ratio' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function additionalLabels(): array
    {
        return [
            'plans.*.template_key' => 'テンプレート',
            'plans.*.amount_mode' => '金額入力種別',
            'plans.*.tax_type_locked' => '税区分固定',
            'plans.*.should_register' => '登録対象',
            'plans.*.business_ratio' => __('recurring_transaction_plans.todo_card.fields.business_ratio'),
        ];
    }

    protected function additionalItemSchema(): array
    {
        $rules = $this->validationRules();

        return [
            'template_key' => [
                'rules' => $rules['plans.*.template_key'],
                'label' => 'テンプレート',
                'type' => 'hidden',
            ],
            'amount_mode' => [
                'rules' => $rules['plans.*.amount_mode'],
                'label' => '金額入力種別',
                'type' => 'hidden',
                'default' => 'gross',
            ],
            'tax_type_locked' => [
                'rules' => $rules['plans.*.tax_type_locked'],
                'label' => '税区分固定',
                'type' => 'hidden',
                'default' => false,
            ],
            'should_register' => [
                'rules' => $rules['plans.*.should_register'],
                'label' => '登録対象',
                'type' => 'boolean',
                'default' => true,
            ],
            'business_ratio' => [
                'rules' => $rules['plans.*.business_ratio'],
                'label' => __('recurring_transaction_plans.todo_card.fields.business_ratio'),
                'type' => 'number',
                'help' => __('recurring_transaction_plans.todo_card.help.business_ratio'),
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

    protected function amountHelp(): ?string
    {
        return __('recurring_transaction_plans.todo_card.help.amount');
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    protected function normalizePlanInput(array $plan, Todo $todo): array
    {
        $normalized = parent::normalizePlanInput($plan, $todo);

        if (! array_key_exists('template_key', $plan)) {
            return $normalized;
        }

        $template = $this->templateFor($plan['template_key'] ?? null);

        $normalized['name'] = __('recurring_transaction_plans.todo_card.defaults.'.$template['key']);
        $normalized['debit_sub_account_id'] = $this->resolveExpenseSubAccountId(
            $todo->businessUnit()->firstOrFail(),
            $template['account_name'],
        );
        $normalized['tax_type'] = $template['tax_type_locked']
            ? $template['tax_type']
            : ($normalized['tax_type'] ?? $template['tax_type']);

        if (($plan['amount_mode'] ?? 'gross') === 'gross') {
            [$netAmount, $taxAmount] = $this->splitGrossAmount(
                (int) ($plan['amount'] ?? 0),
                (string) $normalized['tax_type'],
            );

            $normalized['amount'] = $netAmount;
            $normalized['tax_amount'] = $taxAmount;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $rawPlan
     * @param  array<string, mixed>  $normalizedPlan
     * @return array<string, list<string>>
     */
    protected function additionalPlanValidationErrors(array $rawPlan, array $normalizedPlan, Todo $todo): array
    {
        $errors = [];
        $interval = $normalizedPlan['interval'] ?? null;

        if (! array_key_exists('template_key', $rawPlan)) {
            return $errors;
        }

        $template = $this->templateFor($rawPlan['template_key'] ?? null);

        if ($interval === 'bimonthly' && $this->nullableString($rawPlan['start_month_type'] ?? null) === null) {
            $errors['start_month_type'][] = __('recurring_transaction_plans.todo_card.errors.bimonthly_month_type_required');
        }

        if ($interval === 'yearly' && $this->nullableInt($rawPlan['month_of_year'] ?? null) === null) {
            $errors['month_of_year'][] = __('recurring_transaction_plans.todo_card.errors.payment_month_required');
        }

        if ($template['tax_type_locked'] && ($rawPlan['tax_type'] ?? $template['tax_type']) !== $template['tax_type']) {
            $errors['tax_type'][] = __('recurring_transaction_plans.todo_card.errors.locked_tax_type');
        }

        return $errors;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function buildDefaultPlanItems(BusinessUnit $businessUnit, ?int $defaultCreditSubAccountId): array
    {
        return array_map(function (array $template) use ($businessUnit, $defaultCreditSubAccountId): array {
            return [
                'template_key' => $template['key'],
                'name' => __('recurring_transaction_plans.todo_card.defaults.'.$template['key']),
                'interval' => $template['interval'],
                'day_of_month' => null,
                'month_of_year' => null,
                'start_month_type' => $template['interval'] === 'bimonthly' ? 'odd' : null,
                'debit_sub_account_id' => $this->resolveExpenseSubAccountId($businessUnit, $template['account_name']),
                'credit_sub_account_id' => $defaultCreditSubAccountId,
                'amount' => null,
                'tax_amount' => 0,
                'tax_type' => $template['tax_type'],
                'tax_type_locked' => $template['tax_type_locked'],
                'counterparty_id' => null,
                'is_active' => true,
                'should_register' => true,
                'business_ratio' => null,
                'amount_mode' => 'gross',
            ];
        }, self::PLAN_TEMPLATES);
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    protected function creditSourceOptions(BusinessUnit $businessUnit): array
    {
        return $businessUnit->availableCreditSources()
            ->map(fn (array $source): array => [
                'value' => $source['sub_account_id'],
                'label' => $source['label'] === 'プライベートの財布・クレジットから支払い'
                    ? 'プライベートの財布・クレジットで支払い'
                    : $source['label'],
            ])
            ->all();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    protected function intervalOptions(): array
    {
        return [
            ['value' => 'monthly', 'label' => __('recurring_transaction_plans.todo_card.options.interval.monthly')],
            ['value' => 'bimonthly', 'label' => __('recurring_transaction_plans.todo_card.options.interval.bimonthly')],
            ['value' => 'yearly', 'label' => __('recurring_transaction_plans.todo_card.options.interval.yearly')],
        ];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    protected function taxTypeOptions(): array
    {
        return [
            ['value' => JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10, 'label' => __('recurring_transaction_plans.todo_card.options.tax_type.taxable_10')],
        ];
    }

    protected function filterPlanPayloads(mixed $plans): array
    {
        return array_values(array_filter(
            parent::filterPlanPayloads($plans),
            fn (array $plan): bool => ! array_key_exists('should_register', $plan) || (bool) $plan['should_register'],
        ));
    }

    protected function allowsEmptyPlans(): bool
    {
        return true;
    }

    /**
     * @return array{
     *     key: string,
     *     account_name: string,
     *     interval: string,
     *     tax_type: string,
     *     tax_type_locked: bool
     * }
     */
    protected function templateFor(mixed $key): array
    {
        foreach (self::PLAN_TEMPLATES as $template) {
            if ($template['key'] === $key) {
                return $template;
            }
        }

        throw new DomainException('未対応の定期支出テンプレートです。');
    }

    protected function resolveExpenseSubAccountId(BusinessUnit $businessUnit, string $accountName): int
    {
        $subAccount = $businessUnit->getSubAccountByName($accountName, $accountName);

        if ($subAccount === null) {
            throw new DomainException(sprintf('定期支出テンプレート用の補助科目が見つかりません: %s', $accountName));
        }

        return $subAccount->id;
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected function splitGrossAmount(int $grossAmount, string $taxType): array
    {
        $rate = match ($taxType) {
            JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10 => 10,
            JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_8 => 8,
            JournalEntry::TAX_TYPE_EXEMPT => 0,
            default => throw new DomainException('未対応の消費税区分です。'),
        };

        if ($rate === 0) {
            return [$grossAmount, 0];
        }

        $netAmount = intdiv($grossAmount * 100, 100 + $rate);

        return [$netAmount, $grossAmount - $netAmount];
    }
}
