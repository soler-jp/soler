<?php

use App\Models\SubAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const TARGET_ACCOUNT_NAMES = [
        '現金',
        '当座預金',
        '定期預金',
        'その他の預金',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (self::TARGET_ACCOUNT_NAMES as $accountName) {
                DB::table('sub_accounts')
                    ->whereIn('account_id', DB::table('accounts')->where('name', $accountName)->select('id'))
                    ->where('name', $accountName)
                    ->update(['visibility' => SubAccount::VISIBILITY_HIDDEN]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach (self::TARGET_ACCOUNT_NAMES as $accountName) {
                DB::table('sub_accounts')
                    ->whereIn('account_id', DB::table('accounts')->where('name', $accountName)->select('id'))
                    ->where('name', $accountName)
                    ->update(['visibility' => SubAccount::VISIBILITY_EXPANDED]);
            }
        });
    }
};
