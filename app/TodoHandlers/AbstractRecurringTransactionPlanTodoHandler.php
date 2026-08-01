<?php

namespace App\TodoHandlers;

use App\Concerns\AuthorizesBusinessUnitAccess;
use App\Contracts\TodoHandler;
use App\Models\RecurringTransactionPlan;
use App\Models\Todo;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidationValidator;

abstract class AbstractRecurringTransactionPlanTodoHandler implements TodoHandler
{
    use AuthorizesBusinessUnitAccess;

    protected const NAME_REQUIRED_MESSAGE = '定期取引名を入力してください。';

    protected const DUPLICATE_NAME_MESSAGE = '同じ名前が入力内で重複しています。';

    protected const START_MONTH_CONFLICT_MESSAGE = '開始月と開始月種別は同時に指定できません。';

    protected const EMPTY_PLANS_MESSAGE = '定期取引を1件以上入力してください。';

    public function inputSchema(Todo $todo): array
    {
        return [
            'plans' => [
                'rules' => ['required', 'array', 'min:1'],
                'label' => $this->plansLabel(),
                'type' => 'array',
                'item_schema' => $this->itemSchema(),
            ],
        ];
    }

    public function validate(Todo $todo, array $inputs): array
    {
        $validator = Validator::make(
            $inputs,
            $this->validationRules(),
            $this->messages(),
            $this->labels(),
        );

        $validator->after(function (ValidationValidator $validator) use ($todo, $inputs): void {
            $normalizedNames = [];

            foreach (($inputs['plans'] ?? []) as $index => $rawPlan) {
                if (! is_array($rawPlan)) {
                    continue;
                }

                $hasBlankName = array_key_exists('name', $rawPlan) && trim((string) $rawPlan['name']) === '';

                if ($hasBlankName) {
                    $validator->errors()->add("plans.$index.name", self::NAME_REQUIRED_MESSAGE);
                }

                if ($this->hasStartMonthConflict($rawPlan)) {
                    $validator->errors()->add("plans.$index.start_month", self::START_MONTH_CONFLICT_MESSAGE);
                    $validator->errors()->add("plans.$index.start_month_type", self::START_MONTH_CONFLICT_MESSAGE);
                }

                $plan = $this->normalizePlanInput($rawPlan, $todo);
                $normalizedName = $plan['name'];

                // 先頭出現を基準として扱い、2件目以降のみ重複エラーにする。
                if ($normalizedName !== '' && in_array($normalizedName, $normalizedNames, true)) {
                    $validator->errors()->add("plans.$index.name", self::DUPLICATE_NAME_MESSAGE);
                }

                $normalizedNames[] = $normalizedName;
                $itemValidator = RecurringTransactionPlan::validator($plan);

                if (! $itemValidator->fails()) {
                    continue;
                }

                foreach ($itemValidator->errors()->messages() as $field => $messages) {
                    if ($hasBlankName && $field === 'name') {
                        continue;
                    }

                    foreach ($messages as $message) {
                        $validator->errors()->add("plans.$index.$field", $message);
                    }
                }
            }
        });

        /** @var array{plans: array<int, array<string, mixed>>} $validated */
        $validated = $validator->validate();

        return [
            'plans' => array_map(
                fn (array $plan): array => $this->normalizePlanInput($plan, $todo),
                $validated['plans'],
            ),
        ];
    }

    public function execute(Todo $todo, array $validatedInputs, User $actor): void
    {
        $this->authorizeBusinessUnitAccess($todo, $actor, 'この Todo を実行する権限がありません。');
        $todo->loadMissing('businessUnit', 'fiscalYear');

        if ($todo->fiscalYear === null) {
            throw new DomainException('会計年度に紐づかない Todo では定期取引を登録できません。');
        }

        // TodoService::execute() は validate() を先に通すが、CLI から handler を直接叩く経路もあるため防御する。
        if (($validatedInputs['plans'] ?? []) === []) {
            throw ValidationException::withMessages([
                'plans' => [self::EMPTY_PLANS_MESSAGE],
            ]);
        }

        DB::transaction(function () use ($todo, $validatedInputs, $actor): void {
            foreach ($validatedInputs['plans'] as $planAttributes) {
                $plan = $todo->businessUnit->createRecurringTransactionPlan(
                    $this->normalizePlanInput($planAttributes, $todo),
                    $actor,
                );

                $todo->businessUnit->generatePlannedTransactionsForPlan(
                    $plan,
                    $todo->fiscalYear,
                    $actor,
                );
            }

            $todo->markCompleted();
        });
    }

    /**
     * @return array<string, list<string>>
     */
    protected function validationRules(): array
    {
        return [
            'plans' => ['required', 'array', 'min:1'],
            'plans.*.name' => ['required', 'string', 'max:255'],
            'plans.*.interval' => ['required', 'in:monthly,bimonthly,yearly'],
            'plans.*.day_of_month' => ['required', 'integer', 'min:1', 'max:31'],
            'plans.*.month_of_year' => ['nullable', 'integer', 'min:1', 'max:12'],
            'plans.*.start_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'plans.*.start_month_type' => ['nullable', 'in:odd,even'],
            'plans.*.debit_sub_account_id' => ['required', 'integer'],
            'plans.*.credit_sub_account_id' => ['required', 'integer'],
            'plans.*.amount' => ['required', 'integer', 'min:1'],
            'plans.*.tax_amount' => ['nullable', 'integer', 'min:0'],
            'plans.*.tax_type' => ['nullable', 'string', 'max:50'],
            'plans.*.counterparty_id' => ['nullable', 'integer'],
            'plans.*.is_active' => ['nullable', 'boolean'],
            ...$this->additionalValidationRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function labels(): array
    {
        return [
            'plans' => $this->plansLabel(),
            'plans.*.name' => '定期取引名',
            'plans.*.interval' => '頻度',
            'plans.*.day_of_month' => '日付',
            'plans.*.month_of_year' => '対象月',
            'plans.*.start_month' => '開始月',
            'plans.*.start_month_type' => '開始月種別',
            'plans.*.debit_sub_account_id' => '借方補助科目',
            'plans.*.credit_sub_account_id' => '貸方補助科目',
            'plans.*.amount' => '金額',
            'plans.*.tax_amount' => '消費税額',
            'plans.*.tax_type' => '税区分',
            'plans.*.counterparty_id' => '取引先',
            'plans.*.is_active' => '有効',
            ...$this->additionalLabels(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'plans.*.name.required' => self::NAME_REQUIRED_MESSAGE,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function itemSchema(): array
    {
        $rules = $this->validationRules();

        return [
            'name' => [
                'rules' => $rules['plans.*.name'],
                'label' => '定期取引名',
                'type' => 'text',
            ],
            'interval' => [
                'rules' => $rules['plans.*.interval'],
                'label' => '頻度',
                'type' => 'text',
            ],
            'day_of_month' => [
                'rules' => $rules['plans.*.day_of_month'],
                'label' => '日付',
                'type' => 'number',
            ],
            'month_of_year' => [
                'rules' => $rules['plans.*.month_of_year'],
                'label' => '対象月',
                'type' => 'number',
            ],
            'start_month' => [
                'rules' => $rules['plans.*.start_month'],
                'label' => '開始月',
                'type' => 'number',
            ],
            'start_month_type' => [
                'rules' => $rules['plans.*.start_month_type'],
                'label' => '開始月種別',
                'type' => 'text',
            ],
            'debit_sub_account_id' => [
                'rules' => $rules['plans.*.debit_sub_account_id'],
                'label' => '借方補助科目',
                'type' => 'number',
            ],
            'credit_sub_account_id' => [
                'rules' => $rules['plans.*.credit_sub_account_id'],
                'label' => '貸方補助科目',
                'type' => 'number',
            ],
            'amount' => [
                'rules' => $rules['plans.*.amount'],
                'label' => '金額',
                'type' => 'number',
            ],
            'tax_amount' => [
                'rules' => $rules['plans.*.tax_amount'],
                'label' => '消費税額',
                'type' => 'number',
            ],
            'tax_type' => [
                'rules' => $rules['plans.*.tax_type'],
                'label' => '税区分',
                'type' => 'text',
            ],
            'counterparty_id' => [
                'rules' => $rules['plans.*.counterparty_id'],
                'label' => '取引先',
                'type' => 'number',
            ],
            'is_active' => [
                'rules' => $rules['plans.*.is_active'],
                'label' => '有効',
                'type' => 'boolean',
            ],
            ...$this->additionalItemSchema(),
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    protected function normalizePlanInput(array $plan, Todo $todo): array
    {
        return [
            'business_unit_id' => $todo->business_unit_id,
            'name' => trim((string) ($plan['name'] ?? '')),
            'interval' => $this->nullableString($plan['interval'] ?? null),
            'day_of_month' => $this->nullableInt($plan['day_of_month'] ?? null),
            'month_of_year' => $this->nullableInt($plan['month_of_year'] ?? null),
            'start_month' => $this->nullableInt($plan['start_month'] ?? null)
                ?? $this->normalizeStartMonth($plan['start_month_type'] ?? null),
            'type' => $this->planType(),
            'debit_sub_account_id' => $this->nullableInt($plan['debit_sub_account_id'] ?? null),
            'credit_sub_account_id' => $this->nullableInt($plan['credit_sub_account_id'] ?? null),
            'amount' => $this->nullableInt($plan['amount'] ?? null),
            'tax_amount' => $this->nullableInt($plan['tax_amount'] ?? null) ?? 0,
            'tax_type' => $this->nullableString($plan['tax_type'] ?? null),
            'counterparty_id' => $this->nullableInt($plan['counterparty_id'] ?? null),
            'is_active' => array_key_exists('is_active', $plan) ? (bool) $plan['is_active'] : true,
            ...$this->normalizeAdditionalPlanInput($plan),
        ];
    }

    protected function normalizeStartMonth(mixed $value): ?int
    {
        return match ($this->nullableString($value)) {
            'odd' => 1,
            'even' => 2,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    protected function hasStartMonthConflict(array $plan): bool
    {
        return $this->nullableInt($plan['start_month'] ?? null) !== null
            && $this->nullableString($plan['start_month_type'] ?? null) !== null;
    }

    protected function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array<string, list<string>>
     */
    abstract protected function additionalValidationRules(): array;

    /**
     * @return array<string, string>
     */
    abstract protected function additionalLabels(): array;

    /**
     * @return array<string, array<string, mixed>>
     */
    abstract protected function additionalItemSchema(): array;

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    abstract protected function normalizeAdditionalPlanInput(array $plan): array;

    abstract protected function planType(): string;

    abstract protected function plansLabel(): string;
}
