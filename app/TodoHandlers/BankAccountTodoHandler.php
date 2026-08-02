<?php

namespace App\TodoHandlers;

use App\Concerns\AuthorizesBusinessUnitAccess;
use App\Contracts\TodoHandler;
use App\Models\Todo;
use App\Models\User;
use App\Services\BankAccountRegistrationService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidationValidator;

class BankAccountTodoHandler implements TodoHandler
{
    use AuthorizesBusinessUnitAccess;

    public const TODO_TYPE = Todo::TODO_TYPE_WIZARD_BANK_ACCOUNT;

    protected const LABEL_REQUIRED_MESSAGE = '銀行名を入力してください。';

    public function __construct(
        protected BankAccountRegistrationService $bankAccountRegistrationService,
    ) {}

    public function todoType(): string
    {
        return self::TODO_TYPE;
    }

    public function inputSchema(Todo $todo): array
    {
        return [
            'bank_accounts' => [
                'rules' => $this->validationRules()['bank_accounts'],
                'label' => '銀行口座',
                'type' => 'array',
                'item_schema' => [
                    'label' => [
                        'rules' => $this->validationRules()['bank_accounts.*.label'],
                        'label' => $this->labels()['bank_accounts.*.label'],
                        'type' => 'text',
                    ],
                    'opening_balance' => [
                        'rules' => $this->validationRules()['bank_accounts.*.opening_balance'],
                        'label' => $this->labels()['bank_accounts.*.opening_balance'],
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
            foreach (($inputs['bank_accounts'] ?? []) as $index => $bankAccount) {
                if (! array_key_exists('label', $bankAccount)) {
                    continue;
                }

                $label = trim((string) $bankAccount['label']);

                if ($label === '') {
                    $validator->errors()->add("bank_accounts.$index.label", self::LABEL_REQUIRED_MESSAGE);
                }
            }
        });

        /** @var array{bank_accounts: array<int, array{label: string, opening_balance: int}>} $validated */
        $validated = $validator->validate();

        $validated['bank_accounts'] = array_map(
            fn (array $bankAccount): array => [
                'label' => trim($bankAccount['label']),
                'opening_balance' => (int) $bankAccount['opening_balance'],
            ],
            $validated['bank_accounts'],
        );

        return $validated;
    }

    public function execute(Todo $todo, array $validatedInputs, User $actor): void
    {
        $this->authorizeBusinessUnitAccess($todo, $actor, 'この Todo を実行する権限がありません。');
        $todo->loadMissing('businessUnit', 'fiscalYear');

        if ($todo->fiscalYear === null) {
            throw new DomainException('会計年度に紐づかない Todo では銀行口座を登録できません。');
        }

        DB::transaction(function () use ($todo, $validatedInputs, $actor): void {
            $this->bankAccountRegistrationService->register(
                $todo->businessUnit,
                $todo->fiscalYear,
                $validatedInputs['bank_accounts'],
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
            'bank_accounts' => ['required', 'array', 'min:1'],
            'bank_accounts.*.label' => ['required', 'string', 'max:255'],
            'bank_accounts.*.opening_balance' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function labels(): array
    {
        return [
            'bank_accounts' => '銀行口座',
            'bank_accounts.*.label' => '銀行名',
            'bank_accounts.*.opening_balance' => '残高',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'bank_accounts.*.label.required' => self::LABEL_REQUIRED_MESSAGE,
        ];
    }
}
