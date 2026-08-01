<?php

namespace App\TodoHandlers;

use App\Concerns\AuthorizesBusinessUnitAccess;
use App\Contracts\TodoHandler;
use App\Models\Todo;
use App\Models\User;
use App\Services\CashOnHandRegistrationService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidationValidator;

class CashOnHandTodoHandler implements TodoHandler
{
    use AuthorizesBusinessUnitAccess;

    public const TODO_TYPE = Todo::TODO_TYPE_WIZARD_CASH_ON_HAND;

    protected const LABEL_REQUIRED_MESSAGE = '現金の表示名を入力してください。';

    public function __construct(
        protected CashOnHandRegistrationService $cashOnHandRegistrationService,
    ) {}

    public function todoType(): string
    {
        return self::TODO_TYPE;
    }

    public function inputSchema(Todo $todo): array
    {
        return [
            'cash_accounts' => [
                'rules' => $this->validationRules()['cash_accounts'],
                'label' => '事業用現金',
                'type' => 'array',
                'item_schema' => [
                    'label' => [
                        'rules' => $this->validationRules()['cash_accounts.*.label'],
                        'label' => $this->labels()['cash_accounts.*.label'],
                        'type' => 'text',
                    ],
                    'opening_balance' => [
                        'rules' => $this->validationRules()['cash_accounts.*.opening_balance'],
                        'label' => $this->labels()['cash_accounts.*.opening_balance'],
                        'type' => 'number',
                    ],
                ],
            ],
        ];
    }

    public function validate(Todo $todo, array $inputs): array
    {
        $messages = $this->messages();

        $validator = Validator::make(
            $inputs,
            $this->validationRules(),
            $messages,
            $this->labels(),
        );

        $validator->after(function (ValidationValidator $validator) use ($inputs): void {
            foreach (($inputs['cash_accounts'] ?? []) as $index => $cashAccount) {
                if (! array_key_exists('label', $cashAccount)) {
                    continue;
                }

                $label = trim((string) $cashAccount['label']);

                if ($label === '') {
                    $validator->errors()->add("cash_accounts.$index.label", self::LABEL_REQUIRED_MESSAGE);
                }
            }
        });

        /** @var array{cash_accounts: array<int, array{label: string, opening_balance: int}>} $validated */
        $validated = $validator->validate();

        $validated['cash_accounts'] = array_map(
            fn (array $cashAccount): array => [
                'label' => trim($cashAccount['label']),
                'opening_balance' => (int) $cashAccount['opening_balance'],
            ],
            $validated['cash_accounts'],
        );

        return $validated;
    }

    public function execute(Todo $todo, array $validatedInputs, User $actor): void
    {
        $this->authorizeBusinessUnitAccess($todo, $actor, 'この Todo を実行する権限がありません。');
        $todo->loadMissing('businessUnit', 'fiscalYear');

        if ($todo->fiscalYear === null) {
            throw new DomainException('会計年度に紐づかない Todo では事業用現金を登録できません。');
        }

        DB::transaction(function () use ($todo, $validatedInputs, $actor): void {
            $this->cashOnHandRegistrationService->register(
                $todo->businessUnit,
                $todo->fiscalYear,
                $validatedInputs['cash_accounts'],
                $actor,
            );

            $todo->markCompleted();
        });
    }

    /**
     * @return array<string, list<string>>
     */
    protected function validationRules(): array
    {
        return [
            'cash_accounts' => ['required', 'array', 'min:1'],
            'cash_accounts.*.label' => ['required', 'string', 'max:255'],
            'cash_accounts.*.opening_balance' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function labels(): array
    {
        return [
            'cash_accounts' => '事業用現金',
            'cash_accounts.*.label' => '表示名',
            'cash_accounts.*.opening_balance' => 'その年の期首残高',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'cash_accounts.*.label.required' => self::LABEL_REQUIRED_MESSAGE,
        ];
    }
}
