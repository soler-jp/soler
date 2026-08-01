<?php

namespace Tests\Support\TodoHandlers;

use App\Models\Todo;
use App\Models\User;
use DomainException;

class ThrowingFakeTodoHandler extends FakeTodoHandler
{
    public function execute(Todo $todo, array $validatedInputs, User $actor): void
    {
        throw new DomainException('handler failure');
    }
}
