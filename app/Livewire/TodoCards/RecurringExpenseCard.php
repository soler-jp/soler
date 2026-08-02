<?php

namespace App\Livewire\TodoCards;

use App\Models\Todo;
use App\Models\User;
use App\Services\TodoService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use LogicException;

class RecurringExpenseCard extends Component
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

    public function render(TodoService $service): View
    {
        return view('livewire.todo-cards.recurring-expense-form', [
            'todo' => $this->todo,
            'schema' => $service->schemaFor($this->todo, $this->currentUser()) ?? [],
            'icon' => '定期支出',
        ]);
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
                $defaultItems = $definition['default_items'] ?? null;

                if (is_array($defaultItems) && $defaultItems !== []) {
                    $defaults[$field] = array_map(
                        fn (mixed $item): array => $this->defaultItemInputs(
                            is_array($itemSchema) ? $itemSchema : [],
                            is_array($item) ? $item : [],
                        ),
                        array_values($defaultItems),
                    );
                } else {
                    $defaults[$field] = [
                        $this->defaultItemInputs(is_array($itemSchema) ? $itemSchema : []),
                    ];
                }

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
    protected function defaultItemInputs(array $schema, array $overrides = []): array
    {
        $defaults = [];

        foreach ($schema as $field => $definition) {
            $defaults[$field] = array_key_exists($field, $overrides)
                ? $overrides[$field]
                : $this->defaultScalarValue($definition);
        }

        return $defaults;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    protected function defaultScalarValue(array $definition): mixed
    {
        if (array_key_exists('default', $definition)) {
            return $definition['default'];
        }

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
            throw new LogicException('RecurringExpenseCard は認証済みユーザーからのみ利用できます。');
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
