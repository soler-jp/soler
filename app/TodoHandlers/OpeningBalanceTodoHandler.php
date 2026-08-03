<?php

namespace App\TodoHandlers;

use App\Concerns\AuthorizesBusinessUnitAccess;
use App\Contracts\TodoHandler;
use App\Models\Todo;
use App\Models\User;
use App\Services\OpeningBalanceRegistrationService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidationValidator;

class OpeningBalanceTodoHandler implements TodoHandler
{
    use AuthorizesBusinessUnitAccess;

    public const TODO_TYPE = Todo::TODO_TYPE_WIZARD_OPENING_BALANCE;

    /**
     * @var list<string>
     */
    protected const ASSET_ACCOUNT_NAMES = [
        '受取手形',
        '売掛金',
        '有価証券',
        '棚卸資産',
        '前払金',
        '貸付金',
    ];

    /**
     * @var list<string>
     */
    protected const LIABILITY_ACCOUNT_NAMES = [
        '支払手形',
        '買掛金',
        '借入金',
        '未払金',
        '前受金',
        '預り金',
    ];

    public function __construct(
        protected OpeningBalanceRegistrationService $openingBalanceRegistrationService,
    ) {}

    public function todoType(): string
    {
        return self::TODO_TYPE;
    }

    public function inputSchema(Todo $todo): array
    {
        return [
            'asset_accounts' => [
                'label' => '資産',
                'type' => 'array',
                'item_schema' => $this->entryItemSchema(),
                'default_items' => array_map(
                    fn (string $name): array => ['account_name' => $name, 'amount' => null],
                    self::ASSET_ACCOUNT_NAMES,
                ),
            ],
            'custom_asset_accounts' => [
                'label' => 'その他の資産',
                'type' => 'array',
                'item_schema' => $this->entryItemSchema(),
            ],
            'liability_accounts' => [
                'label' => '負債',
                'type' => 'array',
                'item_schema' => $this->entryItemSchema(),
                'default_items' => array_map(
                    fn (string $name): array => ['account_name' => $name, 'amount' => null],
                    self::LIABILITY_ACCOUNT_NAMES,
                ),
            ],
            'custom_liability_accounts' => [
                'label' => 'その他の負債',
                'type' => 'array',
                'item_schema' => $this->entryItemSchema(),
            ],
        ];
    }

    public function validate(Todo $todo, array $inputs): array
    {
        $inputs['custom_asset_accounts'] = $this->filterBlankCustomEntries($inputs['custom_asset_accounts'] ?? []);
        $inputs['custom_liability_accounts'] = $this->filterBlankCustomEntries($inputs['custom_liability_accounts'] ?? []);

        $validator = Validator::make(
            $inputs,
            $this->validationRules(),
            $this->messages(),
            $this->labels(),
        );

        $validator->after(function (ValidationValidator $validator) use ($inputs): void {
            $this->validateDuplicateCustomNames($validator, $inputs['custom_asset_accounts'] ?? [], 'custom_asset_accounts');
            $this->validateDuplicateCustomNames($validator, $inputs['custom_liability_accounts'] ?? [], 'custom_liability_accounts');
        });

        /** @var array<string, array<int, array{account_name: string, amount: ?int}>> $validated */
        $validated = $validator->validate();

        return [
            'asset_accounts' => $this->normalizeValidatedEntries($validated['asset_accounts'] ?? []),
            'custom_asset_accounts' => $this->normalizeValidatedEntries($validated['custom_asset_accounts'] ?? []),
            'liability_accounts' => $this->normalizeValidatedEntries($validated['liability_accounts'] ?? []),
            'custom_liability_accounts' => $this->normalizeValidatedEntries($validated['custom_liability_accounts'] ?? []),
        ];
    }

    public function execute(Todo $todo, array $validatedInputs, User $actor): void
    {
        $this->authorizeBusinessUnitAccess($todo, $actor, 'この Todo を実行する権限がありません。');
        $todo->loadMissing('businessUnit', 'fiscalYear');

        if ($todo->fiscalYear === null) {
            throw new DomainException('会計年度に紐づかない Todo では開始残高を登録できません。');
        }

        DB::transaction(function () use ($todo, $validatedInputs, $actor): void {
            $this->openingBalanceRegistrationService->register(
                $todo->businessUnit,
                $todo->fiscalYear,
                $validatedInputs,
                $actor,
            );

            $todo->markCompleted();
        });
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function entryItemSchema(): array
    {
        return [
            'account_name' => [
                'label' => '項目名',
                'type' => 'text',
            ],
            'amount' => [
                'label' => '金額',
                'type' => 'number',
            ],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    protected function validationRules(): array
    {
        return [
            'asset_accounts' => ['required', 'array'],
            'asset_accounts.*.account_name' => ['required', 'string', 'max:255'],
            'asset_accounts.*.amount' => ['nullable', 'integer', 'min:0'],
            'custom_asset_accounts' => ['present', 'array'],
            'custom_asset_accounts.*.account_name' => ['required', 'string', 'max:255'],
            'custom_asset_accounts.*.amount' => ['required', 'integer', 'min:0'],
            'liability_accounts' => ['required', 'array'],
            'liability_accounts.*.account_name' => ['required', 'string', 'max:255'],
            'liability_accounts.*.amount' => ['nullable', 'integer', 'min:0'],
            'custom_liability_accounts' => ['present', 'array'],
            'custom_liability_accounts.*.account_name' => ['required', 'string', 'max:255'],
            'custom_liability_accounts.*.amount' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function labels(): array
    {
        return [
            'asset_accounts.*.account_name' => '資産の項目名',
            'asset_accounts.*.amount' => '資産の金額',
            'custom_asset_accounts.*.account_name' => 'その他の資産の項目名',
            'custom_asset_accounts.*.amount' => 'その他の資産の金額',
            'liability_accounts.*.account_name' => '負債の項目名',
            'liability_accounts.*.amount' => '負債の金額',
            'custom_liability_accounts.*.account_name' => 'その他の負債の項目名',
            'custom_liability_accounts.*.amount' => 'その他の負債の金額',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'custom_asset_accounts.*.account_name.required' => 'その他の資産の項目名を入力してください。',
            'custom_liability_accounts.*.account_name.required' => 'その他の負債の項目名を入力してください。',
        ];
    }

    /**
     * @param  array<int, mixed>  $entries
     * @return array<int, array{account_name: string, amount: int}>
     */
    protected function filterBlankCustomEntries(array $entries): array
    {
        return array_values(array_filter($entries, function (mixed $entry): bool {
            if (! is_array($entry)) {
                return true;
            }

            $accountName = trim((string) ($entry['account_name'] ?? ''));
            $amount = $entry['amount'] ?? null;

            return $accountName !== '' || $amount !== null && $amount !== '';
        }));
    }

    /**
     * @param  array<int, array{account_name: string, amount: ?int}>  $entries
     * @return array<int, array{account_name: string, amount: int}>
     */
    protected function normalizeValidatedEntries(array $entries): array
    {
        return array_map(
            fn (array $entry): array => [
                'account_name' => trim($entry['account_name']),
                'amount' => (int) ($entry['amount'] ?? 0),
            ],
            $entries,
        );
    }

    /**
     * @param  array<int, array{account_name: string, amount?: mixed}>  $entries
     */
    protected function validateDuplicateCustomNames(
        ValidationValidator $validator,
        array $entries,
        string $field,
    ): void {
        $seenNames = [];

        foreach ($entries as $index => $entry) {
            $name = trim((string) ($entry['account_name'] ?? ''));

            if ($name === '') {
                continue;
            }

            if (in_array($name, $seenNames, true)) {
                $validator->errors()->add("$field.$index.account_name", '同じ名前が入力内で重複しています。');
            }

            $seenNames[] = $name;
        }
    }
}
