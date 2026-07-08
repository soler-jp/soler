<?php

namespace App\Services;

use App\Models\BlueReturnInput;
use App\Models\FiscalYear;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BlueReturnInputRegistrar
{
    /**
     * @param  array<string, array<string, mixed>>  $inputs
     * @return Collection<int, BlueReturnInput>
     */
    public function saveMany(FiscalYear $fiscalYear, array $inputs): Collection
    {
        return DB::transaction(function () use ($fiscalYear, $inputs): Collection {
            return collect($inputs)->map(
                fn (array $value, string $key): BlueReturnInput => $this->save($fiscalYear, $key, $value)
            );
        });
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public function save(FiscalYear $fiscalYear, string $key, array $value): BlueReturnInput
    {
        if (! in_array($key, BlueReturnInput::KEYS, true)) {
            throw ValidationException::withMessages([
                'key' => ['未対応の決算書入力です。'],
            ]);
        }

        $validatedValue = match ($key) {
            BlueReturnInput::KEY_FAMILY_EMPLOYEE_SALARIES => $this->validateFamilyEmployeeSalaries(
                $fiscalYear,
                $value
            ),
            BlueReturnInput::KEY_RENT_EXPENSES => $this->validateRentExpenses($value),
        };

        return $fiscalYear->blueReturnInputs()->updateOrCreate(
            ['key' => $key],
            ['value' => $validatedValue]
        );
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    protected function validateFamilyEmployeeSalaries(FiscalYear $fiscalYear, array $value): array
    {
        $validated = Validator::make($value, [
            'rows' => ['required', 'array'],
            'rows.*.name' => ['required', 'string', 'max:255'],
            'rows.*.age' => ['nullable', 'integer', 'min:0', 'max:120'],
            'rows.*.months' => ['nullable', 'integer', 'min:0', 'max:12'],
            'rows.*.salary' => ['required', 'integer', 'min:0'],
            'rows.*.bonus' => ['nullable', 'integer', 'min:0'],
            'rows.*.withheld_tax_amount' => ['nullable', 'integer', 'min:0'],
        ])->validate();

        $expectedAmount = (int) $fiscalYear->calculateBlueReturnStatement(0)['profit_and_loss']['family_employee_salaries'];
        $actualAmount = collect($validated['rows'] ?? [])->sum(
            fn (array $row): int => (int) $row['salary'] + (int) ($row['bonus'] ?? 0)
        );

        if ($actualAmount !== $expectedAmount) {
            throw ValidationException::withMessages([
                'rows' => [sprintf(
                    '専従者給与内訳の合計額(%d)が損益計算書の専従者給与(%d)と一致しません。',
                    $actualAmount,
                    $expectedAmount
                )],
            ]);
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    protected function validateRentExpenses(array $value): array
    {
        return Validator::make($value, [
            'rows' => ['required', 'array'],
            'rows.*.address' => ['required', 'string', 'max:255'],
            'rows.*.name' => ['required', 'string', 'max:255'],
            'rows.*.rent_amount' => ['required', 'integer', 'min:0'],
            'rows.*.deductible_amount' => ['required', 'integer', 'min:0'],
            'rows.*.allocation_group_id' => ['nullable', 'string', 'max:255'],
        ])->validate();
    }
}
