<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('recurring_transaction_plans', function (Blueprint $table) {
            // 外部キー削除
            $table->dropForeign(['debit_account_id']);
            $table->dropForeign(['credit_account_id']);

            // カラム削除
            $table->dropColumn(['debit_account_id', 'credit_account_id']);

            // 新しい補助科目のカラム追加
            $table->foreignId('debit_sub_account_id')
                ->after('is_income')
                ->constrained('sub_accounts')
                ->cascadeOnDelete()
                ->comment('借方補助科目');

            $table->foreignId('credit_sub_account_id')
                ->after('debit_sub_account_id')
                ->constrained('sub_accounts')
                ->cascadeOnDelete()
                ->comment('貸方補助科目');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_transaction_plans', function (Blueprint $table) {
            // 新しいカラム削除
            $table->dropForeign(['debit_sub_account_id']);
            $table->dropForeign(['credit_sub_account_id']);
            $table->dropColumn(['debit_sub_account_id', 'credit_sub_account_id']);

            // 元のアカウントカラムを復元
            $table->foreignId('debit_account_id')
                ->constrained('accounts')
                ->comment('借方の勘定科目');

            $table->foreignId('credit_account_id')
                ->constrained('accounts')
                ->comment('貸方の勘定科目');
        });
    }
};
