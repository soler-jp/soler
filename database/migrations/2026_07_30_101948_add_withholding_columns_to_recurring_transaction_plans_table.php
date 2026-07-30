<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_transaction_plans', function (Blueprint $table) {
            $table->boolean('is_withholding')
                ->default(false)
                ->after('type')
                ->comment('源泉徴収付き収入かどうか');
            $table->unsignedBigInteger('withholding_tax_amount')
                ->nullable()
                ->after('business_ratio')
                ->comment('源泉徴収税額');
            $table->foreignId('withholding_sub_account_id')
                ->nullable()
                ->after('withholding_tax_amount')
                ->constrained('sub_accounts')
                ->cascadeOnDelete()
                ->comment('源泉徴収用借方補助科目');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_transaction_plans', function (Blueprint $table) {
            $table->dropForeign(['withholding_sub_account_id']);
            $table->dropColumn([
                'is_withholding',
                'withholding_tax_amount',
                'withholding_sub_account_id',
            ]);
        });
    }
};
