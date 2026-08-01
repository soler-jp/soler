<?php

namespace App\Contracts;

use App\Models\Todo;
use App\Models\User;

interface TodoHandler
{
    public function todoType(): string;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function inputSchema(Todo $todo): array;

    /**
     * @param  array<string, mixed>  $inputs
     * @return array<string, mixed>
     */
    public function validate(Todo $todo, array $inputs): array;

    /**
     * @param  array<string, mixed>  $validatedInputs
     */
    public function execute(Todo $todo, array $validatedInputs, User $actor): void;
}
