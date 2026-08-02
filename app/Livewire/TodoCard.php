<?php

namespace App\Livewire;

use App\Models\Todo;
use App\Models\User;
use App\Services\TodoService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use LogicException;

class TodoCard extends Component
{
    public Todo $todo;

    /**
     * @var array<string, mixed>
     */
    public array $inputs = [];

    public function mount(Todo $todo, TodoService $service): void
    {
        $this->todo = $todo;
        $this->inputs = $this->defaultInputs(
            $service->schemaFor($todo, $this->currentUser()) ?? []
        );
    }

    public function addItem(string $field, TodoService $service): void
    {
        $schema = $service->schemaFor($this->todo, $this->currentUser()) ?? [];
        $definition = $schema[$field] ?? null;

        if (! is_array($definition) || ($definition['type'] ?? null) !== 'array') {
            return;
        }

        $itemSchema = $definition['item_schema'] ?? null;

        if (! is_array($itemSchema)) {
            return;
        }

        $items = $this->inputs[$field] ?? [];

        if (! is_array($items)) {
            $items = [];
        }

        $items[] = $this->defaultItemInputs($itemSchema);
        $this->inputs[$field] = array_values($items);
    }

    public function removeItem(string $field, int $index): void
    {
        $items = $this->inputs[$field] ?? null;

        if (! is_array($items) || ! array_key_exists($index, $items)) {
            return;
        }

        unset($items[$index]);
        $this->inputs[$field] = array_values($items);
    }

    public function submit(TodoService $service): mixed
    {
        try {
            $service->execute($this->todo, $this->inputs, $this->currentUser());
        } catch (ValidationException $exception) {
            $this->mapValidationErrors($exception);

            return null;
        }

        session()->flash('message', 'ToDo を完了しました。');

        return $this->redirect(route('dashboard'));
    }

    public function complete(TodoService $service): mixed
    {
        $service->complete($this->todo, $this->currentUser());
        session()->flash('message', 'ToDo を完了しました。');

        return $this->redirect(route('dashboard'));
    }

    public function render(TodoService $service): View
    {
        if (! $this->todo->isExecutable()) {
            return view('livewire.todo-cards.display-only', [
                'todo' => $this->todo,
            ]);
        }

        return view($this->cardView(), [
            'todo' => $this->todo,
            'schema' => $service->schemaFor($this->todo, $this->currentUser()) ?? [],
            'icon' => $this->icon(),
        ]);
    }

    protected function cardView(): string
    {
        return match ($this->todo->todo_type) {
            Todo::TODO_TYPE_WIZARD_BANK_ACCOUNT => 'livewire.todo-cards.bank-account-form',
            Todo::TODO_TYPE_WIZARD_CASH_ON_HAND => 'livewire.todo-cards.cash-on-hand-form',
            default => 'livewire.todo-cards.generic-form',
        };
    }

    protected function icon(): string
    {
        return match ($this->todo->todo_type) {
            Todo::TODO_TYPE_WIZARD_BANK_ACCOUNT => '銀行',
            Todo::TODO_TYPE_WIZARD_CASH_ON_HAND => '現金',
            Todo::TODO_TYPE_WIZARD_COUNTERPARTY => '取引先',
            Todo::TODO_TYPE_WIZARD_RECURRING_EXPENSES => '定期支出',
            Todo::TODO_TYPE_WIZARD_RECURRING_INCOMES => '定期収入',
            default => 'ToDo',
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $schema
     * @return array<string, mixed>
     */
    protected function defaultInputs(array $schema): array
    {
        $defaults = [];

        foreach ($schema as $field => $definition) {
            if (($definition['type'] ?? 'text') === 'array') {
                $itemSchema = $definition['item_schema'] ?? [];

                $defaults[$field] = [
                    $this->defaultItemInputs(is_array($itemSchema) ? $itemSchema : []),
                ];

                continue;
            }

            $defaults[$field] = $this->defaultScalarValue($definition);
        }

        return $defaults;
    }

    /**
     * @param  array<string, array<string, mixed>>  $schema
     * @return array<string, mixed>
     */
    protected function defaultItemInputs(array $schema): array
    {
        $defaults = [];

        foreach ($schema as $field => $definition) {
            $defaults[$field] = $this->defaultScalarValue($definition);
        }

        return $defaults;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    protected function defaultScalarValue(array $definition): mixed
    {
        return match ($definition['type'] ?? 'text') {
            'boolean' => false,
            'number' => null,
            default => '',
        };
    }

    protected function currentUser(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new LogicException('TodoCard は認証済みユーザーからのみ利用できます。');
        }

        return $user;
    }

    protected function mapValidationErrors(ValidationException $exception): void
    {
        $this->resetErrorBag();

        foreach ($exception->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $this->addError('inputs.'.$field, $message);
            }
        }
    }
}
