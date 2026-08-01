<?php

namespace App\TodoHandlers;

use App\Concerns\AuthorizesBusinessUnitAccess;
use App\Contracts\TodoHandler;
use App\Models\Todo;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidationValidator;

class CounterpartyTodoHandler implements TodoHandler
{
    use AuthorizesBusinessUnitAccess;

    public const TODO_TYPE = Todo::TODO_TYPE_WIZARD_COUNTERPARTY;

    protected const NAME_REQUIRED_MESSAGE = '取引先名を入力してください。';

    protected const DUPLICATE_NAME_MESSAGE = '同じ名前が入力内で重複しています。';

    public function todoType(): string
    {
        return self::TODO_TYPE;
    }

    public function inputSchema(Todo $todo): array
    {
        return [
            'counterparties' => [
                'rules' => $this->validationRules()['counterparties'],
                'label' => '取引先',
                'type' => 'array',
                'item_schema' => [
                    'name' => [
                        'rules' => $this->validationRules()['counterparties.*.name'],
                        'label' => $this->labels()['counterparties.*.name'],
                        'type' => 'text',
                    ],
                    'notes' => [
                        'rules' => $this->validationRules()['counterparties.*.notes'],
                        'label' => $this->labels()['counterparties.*.notes'],
                        'type' => 'textarea',
                    ],
                ],
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

        $validator->after(function (ValidationValidator $validator) use ($inputs): void {
            $seenNames = [];

            foreach (($inputs['counterparties'] ?? []) as $index => $counterparty) {
                if (! is_array($counterparty) || ! array_key_exists('name', $counterparty)) {
                    continue;
                }

                $name = trim((string) $counterparty['name']);

                if ($name === '') {
                    $validator->errors()->add("counterparties.$index.name", self::NAME_REQUIRED_MESSAGE);

                    continue;
                }

                if (in_array($name, $seenNames, true)) {
                    $validator->errors()->add("counterparties.$index.name", self::DUPLICATE_NAME_MESSAGE);
                }

                $seenNames[] = $name;
            }
        });

        /** @var array{counterparties: array<int, array{name: string, notes?: ?string}>} $validated */
        $validated = $validator->validate();

        $validated['counterparties'] = array_map(
            fn (array $counterparty): array => [
                'name' => trim($counterparty['name']),
                'notes' => $this->normalizeNullableString($counterparty['notes'] ?? null),
            ],
            $validated['counterparties'],
        );

        return $validated;
    }

    public function execute(Todo $todo, array $validatedInputs, User $actor): void
    {
        $this->authorizeBusinessUnitAccess($todo, $actor, 'この Todo を実行する権限がありません。');
        $todo->loadMissing('businessUnit');

        DB::transaction(function () use ($todo, $validatedInputs): void {
            $counterparties = $validatedInputs['counterparties'];
            $names = array_column($counterparties, 'name');
            $existingNames = $todo->businessUnit->counterparties()
                ->whereIn('name', $names)
                ->pluck('name')
                ->all();

            if ($existingNames !== []) {
                $errors = [];

                foreach ($counterparties as $index => $counterparty) {
                    if (in_array($counterparty['name'], $existingNames, true)) {
                        $errors["counterparties.$index.name"] = [
                            sprintf('取引先「%s」はすでに登録されています。', $counterparty['name']),
                        ];
                    }
                }

                throw ValidationException::withMessages($errors);
            }

            foreach ($counterparties as $counterparty) {
                $todo->businessUnit->counterparties()->create([
                    'name' => $counterparty['name'],
                    'notes' => $counterparty['notes'] ?? null,
                ]);
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
            'counterparties' => ['required', 'array', 'min:1'],
            'counterparties.*.name' => ['required', 'string', 'max:255'],
            'counterparties.*.notes' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function labels(): array
    {
        return [
            'counterparties' => '取引先',
            'counterparties.*.name' => '取引先名',
            'counterparties.*.notes' => 'メモ',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'counterparties.*.name.required' => self::NAME_REQUIRED_MESSAGE,
        ];
    }

    protected function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
