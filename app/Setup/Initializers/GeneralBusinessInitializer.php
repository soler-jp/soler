<?php

namespace App\Setup\Initializers;

use App\Models\BusinessUnit;
use App\Models\User;
use InvalidArgumentException;

class GeneralBusinessInitializer
{
    public function initialize(User $user, array $inputs): BusinessUnit
    {
        if ($inputs['is_taxable'] || $inputs['is_tax_exclusive']) {
            throw new InvalidArgumentException('現時点では免税事業者・税込経理のみ対応しています。');
        }

        $unit = $user->createBusinessUnitWithDefaults([
            'name' => $inputs['name'],
            'type' => 'general',
            'is_taxable_supplier' => $inputs['is_taxable'],
            'is_tax_exclusive' => $inputs['is_tax_exclusive'],
        ]);

        $fiscalYear = $unit->createFiscalYear($inputs['year']);

        $fiscalYear->update([
            'is_active' => true,
            'is_closed' => false,
            'is_taxable' => $inputs['is_taxable'],
            'is_tax_exclusive' => $inputs['is_tax_exclusive'],
        ]);

        $fiscalYear->registerOpeningEntry($inputs['opening_entries'] ?? []);

        $revenueAccount = $unit->getAccountByName('売上高');

        if (isset($inputs['revenue_sub_accounts']) && $revenueAccount) {
            foreach ($inputs['revenue_sub_accounts'] as $subAccount) {
                $revenueAccount->subAccounts()->create([
                    'name' => $subAccount['name'],
                ]);
            }
        }


        return $unit->refresh();
    }
}
