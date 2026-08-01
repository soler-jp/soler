<?php

namespace Tests\Unit\Architecture;

use App\Models\Todo;
use Tests\TestCase;

class TodoHandlerMappingTest extends TestCase
{
    public function test_todo_handler_registration_and_declared_types_are_consistent(): void
    {
        foreach (Todo::$handlers as $todoType => $handlerClass) {
            $this->assertSame(
                $todoType,
                app($handlerClass)->todoType(),
                sprintf('%s must declare %s in todoType().', $handlerClass, $todoType),
            );
        }
    }
}
