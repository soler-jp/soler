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
            if (! Schema::hasColumn('sub_accounts', 'sort_order')) {
                $table->integer('sort_order')
                    ->default(SubAccount::SORT_ORDER_DEFAULT)
                    ->after('visibility')
                    ->comment('UI 表示の並び順。小さいほど上。優先リストは 10 刻み、既定は 1000。');
                $table->index('sort_order');
            }
        });

        DB::transaction(function (): void {
            // すべての既存 sub_account をひとまず既定値へ揃える。
            DB::table('sub_accounts')->update([
                'sort_order' => SubAccount::SORT_ORDER_DEFAULT,
            ]);

            // 優先リストに含まれる既定 SubAccount に順序値を割り当てる。
            foreach (BusinessUnit::$prioritizedDefaultSubAccounts as $index => $subAccountName) {
                $sortOrder = ($index + 1) * 10;

                DB::table('sub_accounts')
                    ->where('name', $subAccountName)
                    ->update(['sort_order' => $sortOrder]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('sub_accounts', function (Blueprint $table): void {
            $table->dropIndex(['sort_order']);
            $table->dropColumn('sort_order');
        });
    }
};
