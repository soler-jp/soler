<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::table('journal_entries', function (Blueprint $table) {
            // 1. 外部キー制約の削除（SET NULL 対応を一旦解除）
            $table->dropForeign(['sub_account_id']);
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            // 2. NULL不可に変更
            $table->foreignId('sub_account_id')
                ->nullable(false)
                ->change();
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            // 3. 外部キー制約を再追加（nullを許さない制約で）
            $table->foreign('sub_account_id')
                ->references('id')
                ->on('sub_accounts')
                ->restrictOnDelete();
        });

        // 4. account_id を削除
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropColumn('account_id');
        });
    }
    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            // account_id を復元（必要に応じて）
            $table->foreignId('account_id')
                ->constrained()
                ->restrictOnDelete()
                ->comment('勘定科目');

            // sub_account_id を nullable に戻す
            $table->foreignId('sub_account_id')
                ->nullable()
                ->change();
        });
    }
};
