<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->boolean('is_taxable')
                ->default(false)
                ->after('is_active')
                ->comment('この年度が課税事業者かどうか（true:課税、false:免税）');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->dropColumn('is_taxable');
        });
    }
};
