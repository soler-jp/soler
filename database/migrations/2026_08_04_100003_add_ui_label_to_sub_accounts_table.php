<?php

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
            if (! Schema::hasColumn('sub_accounts', 'ui_label')) {
                $table->string('ui_label')
                    ->nullable()
                    ->after('name')
                    ->comment('UI 表示用のカスタムラベル。null の場合は name をそのまま表示する。');
            }
        });

        DB::transaction(function (): void {
            // 既定シードで作られる SubAccount に UI 用の口語ラベルを付ける。
            // {account_name => [sub_account_name => ui_label]}
            $namedLabels = [
                '売掛金' => ['売掛金' => '後日入金予定'],
                '事業主貸' => ['事業主貸' => '個人の財布に入金'],
                '事業主借' => ['事業主借' => '個人の財布・個人のクレジットカードで支払い'],
                '買掛金' => ['買掛金' => '後日支払い予定'],
            ];

            foreach ($namedLabels as $accountName => $subAccountLabels) {
                foreach ($subAccountLabels as $subAccountName => $label) {
                    $ids = DB::table('sub_accounts')
                        ->join('accounts', 'sub_accounts.account_id', '=', 'accounts.id')
                        ->where('accounts.name', $accountName)
                        ->where('sub_accounts.name', $subAccountName)
                        ->pluck('sub_accounts.id');

                    if ($ids->isNotEmpty()) {
                        DB::table('sub_accounts')
                            ->whereIn('id', $ids)
                            ->update(['ui_label' => $label]);
                    }
                }
            }

            // 未分類 SubAccount は system_purpose で狙う（名前ではなく意味で識別）。
            DB::table('sub_accounts')
                ->where('system_purpose', SubAccount::PURPOSE_UNCLASSIFIED)
                ->update(['ui_label' => '後から決める']);
        });
    }

    public function down(): void
    {
        Schema::table('sub_accounts', function (Blueprint $table): void {
            $table->dropColumn('ui_label');
        });
    }
};
