<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('business_units', function (Blueprint $table) {
            $table->foreignId('current_fiscal_year_id')
                ->nullable()
                ->constrained('fiscal_years')
                ->nullOnDelete()
                ->comment('現在選択中の会計年度');
        });
    }

    public function down(): void
    {
        Schema::table('business_units', function (Blueprint $table) {
            $table->dropForeign(['current_fiscal_year_id']);
            $table->dropColumn('current_fiscal_year_id');
        });
    }
};
