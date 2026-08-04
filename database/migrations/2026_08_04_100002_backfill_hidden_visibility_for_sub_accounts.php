<?php

use App\Models\BusinessUnit;
use App\Models\SubAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            // 既定シードのうち内部利用専用の SubAccount を hidden に降格する。
            // 親勘定名と補助科目名の (accountName, subName) 組合わせで一致するもののみ対象。
            foreach (BusinessUnit::$defaultAccounts as $accountDef) {
                $accountName = $accountDef['name'];
                $subAccountNames = BusinessUnit::$defaultSubAccounts[$accountName] ?? [$accountName];

                foreach ($subAccountNames as $subAccountName) {
                    if (! in_array($subAccountName, BusinessUnit::$hiddenDefaultSubAccounts, true)) {
                        continue;
                    }

                    DB::table('sub_accounts')
                        ->whereIn('account_id', DB::table('accounts')->where('name', $accountName)->select('id'))
                        ->where('name', $subAccountName)
                        ->update(['visibility' => SubAccount::VISIBILITY_HIDDEN]);
                }
            }
        });
    }

    public function down(): void
    {
        // 元の visibility (standard / expanded) は復元できない。
        // ロールバック時は hidden のものを expanded にフォールバックする。
        DB::table('sub_accounts')
            ->where('visibility', SubAccount::VISIBILITY_HIDDEN)
            ->update(['visibility' => SubAccount::VISIBILITY_EXPANDED]);
    }
};
