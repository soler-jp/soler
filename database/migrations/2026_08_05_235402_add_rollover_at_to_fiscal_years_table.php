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
        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->timestamp('rollover_at')
                ->nullable()
                ->after('closed_by')
                ->comment('翌期繰越を実行した日時');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->dropColumn('rollover_at');
        });
    }
};
