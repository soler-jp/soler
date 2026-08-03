<?php

use App\Models\Account;
use App\Models\BusinessUnit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $accountName = BusinessUnit::UNCLASSIFIED_EXPENSE_ACCOUNT_NAME;
        $subAccountName = BusinessUnit::UNCLASSIFIED_EXPENSE_SUB_ACCOUNT_NAME;
        $now = now();

        DB::transaction(function () use ($accountName, $subAccountName, $now) {
            DB::table('business_units')->orderBy('id')->each(function ($businessUnit) use ($accountName, $subAccountName, $now) {
                $accountId = DB::table('accounts')
                    ->where('business_unit_id', $businessUnit->id)
                    ->where('name', $accountName)
                    ->value('id');

                if ($accountId === null) {
                    $accountId = DB::table('accounts')->insertGetId([
                        'business_unit_id' => $businessUnit->id,
                        'name' => $accountName,
                        'type' => Account::TYPE_EXPENSE,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                DB::table('sub_accounts')->updateOrInsert(
                    ['account_id' => $accountId, 'name' => $subAccountName],
                    ['updated_at' => $now, 'created_at' => $now],
                );
            });
        });
    }

    public function down(): void
    {
        $accountName = BusinessUnit::UNCLASSIFIED_EXPENSE_ACCOUNT_NAME;

        DB::transaction(function () use ($accountName) {
            $accountIds = DB::table('accounts')
                ->where('name', $accountName)
                ->pluck('id');

            DB::table('sub_accounts')->whereIn('account_id', $accountIds)->delete();
            DB::table('accounts')->whereIn('id', $accountIds)->delete();
        });
    }
};
