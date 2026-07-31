<?php

namespace App\Setup\Initializers;

use App\Models\BusinessUnit;
use App\Models\InitialSetupData;
use App\Models\User;
use App\Services\OpeningEntryRegistrar;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class GeneralBusinessInitializer
{
    public function initialize(User $user, array $inputs): BusinessUnit
    {
        if ($inputs['is_tax_exclusive'] ?? false) {
            throw new InvalidArgumentException('現時点では税抜経理は対応していません。');
        }

        return DB::transaction(function () use ($user, $inputs): BusinessUnit {
            $unit = $user->createBusinessUnitWithDefaults([
                'name' => $inputs['name'],
                'type' => $inputs['type'] ?? BusinessUnit::TYPE_GENERAL,
            ]);

            $initialSetupData = $unit->initialSetupData()->create([
                'year' => $inputs['year'],
                'opening_context' => $inputs['opening_context'] ?? InitialSetupData::OPENING_CONTEXT_FIRST_YEAR,
                'is_taxable' => $inputs['is_taxable'],
                'bank_account_answer' => $inputs['bank_account_answer'] ?? InitialSetupData::ANSWER_NO,
                'cash_on_hand_answer' => $inputs['cash_on_hand_answer'] ?? InitialSetupData::ANSWER_NO,
                'fixed_asset_answer' => $inputs['fixed_asset_answer'] ?? InitialSetupData::ANSWER_NO,
                'recurring_expense_answer' => $inputs['recurring_expense_answer'] ?? InitialSetupData::ANSWER_NO,
                'recurring_income_answer' => $inputs['recurring_income_answer'] ?? InitialSetupData::ANSWER_NO,
                'counterparty_answer' => $inputs['counterparty_answer'] ?? InitialSetupData::ANSWER_NO,
                'completed_at' => now(),
            ]);

            $initializerInputs = $initialSetupData->toGeneralBusinessInitializerInputs();
            $initializerInputs['opening_entries'] = $inputs['opening_entries'] ?? [];
            $initializerInputs['revenue_sub_accounts'] = $inputs['revenue_sub_accounts'] ?? [];

            $fiscalYear = $unit->createFiscalYear($initializerInputs['year'], $user);

            $fiscalYear->update([
                'is_active' => true,
                'is_closed' => false,
                'is_taxable' => $initializerInputs['is_taxable'],
                'is_tax_exclusive' => $initializerInputs['is_tax_exclusive'],
                'opening_context' => $initializerInputs['opening_context'],
            ]);

            app(OpeningEntryRegistrar::class)->register($fiscalYear, $initializerInputs['opening_entries'], $user);

            $revenueAccount = $unit->getAccountByName('売上高');

            if ($revenueAccount) {
                foreach ($initializerInputs['revenue_sub_accounts'] as $subAccount) {
                    $revenueAccount->addCustomSubAccount($subAccount['name'], $user);
                }
            }

            return $unit->refresh();
        });
    }
}
