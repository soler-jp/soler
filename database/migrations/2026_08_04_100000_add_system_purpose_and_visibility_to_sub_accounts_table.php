<?php

use App\Models\BusinessUnit;
use App\Models\SubAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sub_accounts', function (Blueprint $table): void {
            if (! Schema::hasColumn('sub_accounts', 'system_purpose')) {
                $table->string('system_purpose')
                    ->nullable()
                    ->after('name')
                    ->comment('システム予約用途（unclassified / household_allocation など）。null はユーザー・標準生成の通常補助科目。');
                $table->index('system_purpose');
            }

            if (! Schema::hasColumn('sub_accounts', 'visibility')) {
                $table->string('visibility')
                    ->default(SubAccount::VISIBILITY_STANDARD)
                    ->after('system_purpose')
                    ->comment('UI 表示区分。standard は既定表示、expanded は展開時のみ表示。');
            }
        });

        DB::transaction(function (): void {
            // すべての既存 sub_account をひとまず standard 扱いにする。
            // ユーザーが自分で追加した補助科目・標準的に使う既定補助科目はここで確定。
            DB::table('sub_accounts')->update([
                'visibility' => SubAccount::VISIBILITY_STANDARD,
            ]);

            // 既定シードのうち、標準リストに含まれないものは expanded に降格する。
            foreach (BusinessUnit::$defaultAccounts as $accountDef) {
                $accountName = $accountDef['name'];
                $subAccountNames = BusinessUnit::$defaultSubAccounts[$accountName] ?? [$accountName];

                foreach ($subAccountNames as $subAccountName) {
                    if (in_array($subAccountName, BusinessUnit::$standardDefaultSubAccounts, true)) {
                        continue;
                    }

                    DB::table('sub_accounts')
                        ->whereIn('account_id', DB::table('accounts')->where('name', $accountName)->select('id'))
                        ->where('name', $subAccountName)
                        ->update(['visibility' => SubAccount::VISIBILITY_EXPANDED]);
                }
            }

            // 予約 SubAccount の system_purpose を後埋め。
            DB::table('sub_accounts')
                ->whereIn(
                    'account_id',
                    DB::table('accounts')
                        ->where('name', BusinessUnit::UNCLASSIFIED_EXPENSE_ACCOUNT_NAME)
                        ->select('id')
                )
                ->where('name', BusinessUnit::UNCLASSIFIED_EXPENSE_SUB_ACCOUNT_NAME)
                ->update(['system_purpose' => SubAccount::PURPOSE_UNCLASSIFIED]);

            DB::table('sub_accounts')
                ->where('name', BusinessUnit::HOUSEHOLD_ALLOCATION_SUB_ACCOUNT_NAME)
                ->update(['system_purpose' => SubAccount::PURPOSE_HOUSEHOLD_ALLOCATION]);
        });
    }

    public function down(): void
    {
        Schema::table('sub_accounts', function (Blueprint $table): void {
            $table->dropIndex(['system_purpose']);
            $table->dropColumn(['system_purpose', 'visibility']);
        });
    }
};
