<?php

use App\Models\Account;
use App\Models\BusinessUnit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            if (! Schema::hasColumn('accounts', 'example')) {
                $table->text('example')
                    ->nullable()
                    ->after('type')
                    ->comment('この勘定科目に該当する取引の具体例。複数項目は改行区切り。');
            }

            if (! Schema::hasColumn('accounts', 'caution')) {
                $table->text('caution')
                    ->nullable()
                    ->after('example')
                    ->comment('この勘定科目を選ぶ際の注意事項（必要経費にならないもの等）。');
            }
        });

        DB::transaction(function (): void {
            // 既存ユーザーの Account に、BusinessUnit::$defaultAccounts に定義された
            // example / caution をバックフィルする。
            // ユーザーが自分で編集した値を上書きしないよう、両方 null の行だけを対象にする。
            foreach (BusinessUnit::$defaultAccounts as $account) {
                $update = [];

                if (array_key_exists('example', $account)) {
                    $update['example'] = $account['example'];
                }

                if (array_key_exists('caution', $account)) {
                    $update['caution'] = $account['caution'];
                }

                if ($update === []) {
                    continue;
                }

                DB::table('accounts')
                    ->where('name', $account['name'])
                    ->where('type', $account['type'])
                    ->whereNull('example')
                    ->whereNull('caution')
                    ->update($update);
            }
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn(['example', 'caution']);
        });
    }
};
