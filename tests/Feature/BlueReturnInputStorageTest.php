<?php

namespace Tests\Feature;

use App\Models\BlueReturnInput;
use App\Models\User;
use App\Services\TransactionRegistrar;
use App\Setup\Initializers\GeneralBusinessInitializer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BlueReturnInputStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_blue_return_inputs_can_be_saved_and_updated(): void
    {
        $user = User::factory()->create();
        $businessUnit = (new GeneralBusinessInitializer)->initialize($user, [
            'name' => '青色申告テスト',
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => false,
            'is_tax_exclusive' => false,
            'cash_balance' => null,
            'bank_accounts' => [],
            'fixed_assets' => [],
            'recurring_templates' => [],
        ]);

        $fiscalYear = $businessUnit->fiscalYears()->firstOrFail();
        $cash = $businessUnit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $familyEmployeeSalaries = $businessUnit->getAccountByName('専従者給与')->subAccounts()->firstOrFail();

        app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-01-15',
            'description' => '専従者給与テスト',
        ], [
            [
                'sub_account_id' => $familyEmployeeSalaries->id,
                'type' => 'debit',
                'net_amount' => 1_200_000,
                'tax_amount' => 0,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'credit',
                'net_amount' => 1_200_000,
                'tax_amount' => 0,
            ],
        ], $user);

        $savedInputs = $fiscalYear->saveBlueReturnInputs([
            BlueReturnInput::KEY_FAMILY_EMPLOYEE_SALARIES => [
                'rows' => [
                    [
                        'name' => '国税 太郎',
                        'age' => 42,
                        'months' => 12,
                        'salary' => 1_000_000,
                        'bonus' => 200_000,
                        'withheld_tax_amount' => 0,
                    ],
                ],
            ],
            BlueReturnInput::KEY_RENT_EXPENSES => [
                'rows' => [
                    [
                        'address' => '東京都千代田区1-1-1',
                        'name' => '株式会社サンプル',
                        'rent_amount' => 120_000,
                        'deductible_amount' => 90_000,
                        'allocation_group_id' => 'group-1',
                    ],
                ],
            ],
        ], $user);

        $this->assertCount(2, $savedInputs);
        $this->assertDatabaseCount('blue_return_inputs', 2);
        $this->assertSame(
            [
                'rows' => [
                    [
                        'name' => '国税 太郎',
                        'age' => 42,
                        'months' => 12,
                        'salary' => 1_000_000,
                        'bonus' => 200_000,
                        'withheld_tax_amount' => 0,
                    ],
                ],
            ],
            $fiscalYear->blueReturnInput(BlueReturnInput::KEY_FAMILY_EMPLOYEE_SALARIES)?->value
        );

        $updatedRentInput = $fiscalYear->saveBlueReturnInput(BlueReturnInput::KEY_RENT_EXPENSES, [
            'rows' => [
                [
                    'address' => '東京都千代田区2-2-2',
                    'name' => '株式会社サンプル',
                    'rent_amount' => 150_000,
                    'deductible_amount' => 100_000,
                ],
            ],
        ], $user);

        $this->assertSame(BlueReturnInput::KEY_RENT_EXPENSES, $updatedRentInput->key);
        $this->assertDatabaseCount('blue_return_inputs', 2);
        $this->assertSame(
            [
                'rows' => [
                    [
                        'address' => '東京都千代田区2-2-2',
                        'name' => '株式会社サンプル',
                        'rent_amount' => 150_000,
                        'deductible_amount' => 100_000,
                    ],
                ],
            ],
            $fiscalYear->blueReturnInput(BlueReturnInput::KEY_RENT_EXPENSES)?->value
        );
    }

    public function test_family_employee_salary_breakdown_must_match_the_ledger_amount(): void
    {
        $user = User::factory()->create();
        $businessUnit = (new GeneralBusinessInitializer)->initialize($user, [
            'name' => '青色申告テスト',
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => false,
            'is_tax_exclusive' => false,
            'cash_balance' => null,
            'bank_accounts' => [],
            'fixed_assets' => [],
            'recurring_templates' => [],
        ]);

        $fiscalYear = $businessUnit->fiscalYears()->firstOrFail();
        $cash = $businessUnit->getAccountByName('現金')->subAccounts()->firstOrFail();
        $familyEmployeeSalaries = $businessUnit->getAccountByName('専従者給与')->subAccounts()->firstOrFail();

        app(TransactionRegistrar::class)->register($fiscalYear, [
            'date' => '2025-01-15',
            'description' => '専従者給与テスト',
        ], [
            [
                'sub_account_id' => $familyEmployeeSalaries->id,
                'type' => 'debit',
                'net_amount' => 1_200_000,
                'tax_amount' => 0,
            ],
            [
                'sub_account_id' => $cash->id,
                'type' => 'credit',
                'net_amount' => 1_200_000,
                'tax_amount' => 0,
            ],
        ], $user);

        try {
            $fiscalYear->saveBlueReturnInput(BlueReturnInput::KEY_FAMILY_EMPLOYEE_SALARIES, [
                'rows' => [
                    [
                        'name' => '国税 太郎',
                        'age' => 42,
                        'months' => 12,
                        'salary' => 900_000,
                        'bonus' => 200_000,
                        'withheld_tax_amount' => 0,
                    ],
                ],
            ], $user);

            $this->fail('ValidationException が発生するはずです。');
        } catch (ValidationException $exception) {
            $this->assertSame([
                '専従者給与内訳の合計額(1100000)が損益計算書の専従者給与(1200000)と一致しません。',
            ], $exception->errors()['rows']);
        }

        $this->assertDatabaseMissing('blue_return_inputs', [
            'fiscal_year_id' => $fiscalYear->id,
            'key' => BlueReturnInput::KEY_FAMILY_EMPLOYEE_SALARIES,
        ]);
    }

    public function test_other_user_cannot_save_blue_return_input(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $businessUnit = (new GeneralBusinessInitializer)->initialize($user, [
            'name' => '青色申告認可テスト',
            'type' => 'general',
            'year' => 2025,
            'is_taxable' => false,
            'is_tax_exclusive' => false,
            'cash_balance' => null,
            'bank_accounts' => [],
            'fixed_assets' => [],
            'recurring_templates' => [],
        ]);

        $fiscalYear = $businessUnit->fiscalYears()->firstOrFail();

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('この会計年度の決算書入力を保存する権限がありません。');

        $fiscalYear->saveBlueReturnInput(BlueReturnInput::KEY_RENT_EXPENSES, [
            'rows' => [
                [
                    'address' => '東京都千代田区1-1-1',
                    'name' => '株式会社サンプル',
                    'rent_amount' => 120_000,
                    'deductible_amount' => 90_000,
                ],
            ],
        ], $otherUser);
    }
}
