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
            $table->unsignedTinyInteger('start_month')
                ->nullable()
                ->comment('隔月などの繰り返し処理の起点となる月（1〜12）');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recurring_transaction_plans', function (Blueprint $table) {
            $table->dropColumn('start_month');
        });
    }
};
