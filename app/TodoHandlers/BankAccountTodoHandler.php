<?php

namespace App\TodoHandlers;

use App\Concerns\AuthorizesBusinessUnitAccess;
use App\Contracts\TodoHandler;
use App\Models\Todo;
use App\Models\User;
use App\Services\BankAccountRegistrationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class BankAccountTodoHandler implements TodoHandler
{
    use AuthorizesBusinessUnitAccess;

    public const TODO_TYPE = Todo::TODO_TYPE_WIZARD_BANK_ACCOUNT;

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
            'bank_name' => [
                'rules' => $this->validationRules()['bank_name'],
                'label' => $this->labels()['bank_name'],
                'type' => 'text',
            ],
            'opening_balance' => [
                'rules' => $this->validationRules()['opening_balance'],
                'label' => $this->labels()['opening_balance'],
                'type' => 'number',
            ],
        ];
    }

    public function validate(Todo $todo, array $inputs): array
    {
        /** @var array{bank_name: string, opening_balance: int} $validated */
        $validated = Validator::make(
            $inputs,
            $this->validationRules(),
            [],
            $this->labels(),
        )->validate();

        $validated['bank_name'] = trim($validated['bank_name']);
        $validated['opening_balance'] = (int) $validated['opening_balance'];

        return $validated;
    }

    public function execute(Todo $todo, array $validatedInputs, User $actor): void
    {
        $this->authorizeBusinessUnitAccess($todo, $actor, 'この Todo を実行する権限がありません。');
        $todo->loadMissing('businessUnit', 'fiscalYear');

        if ($todo->fiscalYear === null) {
            throw new \DomainException('会計年度に紐づかない Todo では銀行口座を登録できません。');
        }

        DB::transaction(function () use ($todo, $validatedInputs, $actor): void {
            $this->bankAccountRegistrationService->register(
                $todo->businessUnit,
                $todo->fiscalYear,
                $validatedInputs['bank_name'],
                $validatedInputs['opening_balance'],
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
            'bank_name' => ['required', 'string', 'max:255'],
            'opening_balance' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function labels(): array
    {
        return [
            'bank_name' => '銀行名',
            'opening_balance' => 'その年の期首残高',
        ];
    }
}
