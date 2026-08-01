<?php

namespace Tests\Support\TodoHandlers;

use App\Concerns\AuthorizesBusinessUnitAccess;
use App\Contracts\TodoHandler;
use App\Models\Todo;
use App\Models\User;
use DomainException;

class FakeTodoHandler implements TodoHandler
{
    use AuthorizesBusinessUnitAccess;

    public const TODO_TYPE = 'fake_executable';

    public function todoType(): string
    {
        return self::TODO_TYPE;
    }

    public function inputSchema(Todo $todo): array
    {
        return [
            'amount' => [
                'rules' => ['required', 'integer', 'min:1'],
                'label' => '金額',
                'type' => 'number',
            ],
        ];
    }

    public function validate(Todo $todo, array $inputs): array
    {
        if (! isset($inputs['amount']) || ! is_int($inputs['amount']) || $inputs['amount'] < 1) {
            throw new DomainException('invalid amount');
        }

        return $inputs;
    }

    public function execute(Todo $todo, array $validatedInputs, User $actor): void
    {
        $this->authorizeBusinessUnitAccess($todo, $actor, 'この Todo を実行する権限がありません。');

        $todo->markCompleted();
    }
}
