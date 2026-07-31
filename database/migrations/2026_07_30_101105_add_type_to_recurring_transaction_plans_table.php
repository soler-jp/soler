<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_transaction_plans', function (Blueprint $table) {
            $table->enum('type', ['expense', 'income'])
                ->default('expense')
                ->after('day_of_month')
                ->comment('収支区分');
        });

        DB::table('recurring_transaction_plans')
            ->where('is_income', true)
            ->update(['type' => 'income']);

        DB::table('recurring_transaction_plans')
            ->where('is_income', false)
            ->update(['type' => 'expense']);

        Schema::table('recurring_transaction_plans', function (Blueprint $table) {
            $table->dropColumn('is_income');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_transaction_plans', function (Blueprint $table) {
            $table->boolean('is_income')
                ->default(false)
                ->after('day_of_month')
                ->comment('収入か支出か（true: 収入）');
        });

        DB::table('recurring_transaction_plans')
            ->where('type', 'income')
            ->update(['is_income' => true]);

        DB::table('recurring_transaction_plans')
            ->where('type', 'expense')
            ->update(['is_income' => false]);

        Schema::table('recurring_transaction_plans', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
