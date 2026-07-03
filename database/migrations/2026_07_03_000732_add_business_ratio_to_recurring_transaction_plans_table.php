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
            $table->unsignedTinyInteger('business_ratio')
                ->nullable()
                ->after('tax_type')
                ->comment('事業割合（%）');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recurring_transaction_plans', function (Blueprint $table) {
            $table->dropColumn('business_ratio');
        });
    }
};
