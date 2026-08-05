<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->foreignId('initial_opening_transaction_id')
                ->nullable()
                ->after('depreciation_method')
                ->constrained('transactions')
                ->nullOnDelete()
                ->comment('過年度取得の資産を初めて登録した年度に作成した期首振替仕訳。1資産:1本');
        });
    }

    public function down(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->dropForeign(['initial_opening_transaction_id']);
            $table->dropColumn('initial_opening_transaction_id');
        });
    }
};
