<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('recurring_transaction_plans', function (Blueprint $table) {
            $table->integer('month_of_year')
                ->nullable()
                ->after('day_of_month')
                ->comment('intervalがyearlの場合の月の指定（1-12）');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recurring_transaction_plans', function (Blueprint $table) {
            $table->dropColumn('month_of_year');
        });
    }
};
