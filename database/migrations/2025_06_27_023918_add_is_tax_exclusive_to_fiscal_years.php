<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->boolean('is_tax_exclusive')
                ->default(false)
                ->after('is_closed')
                ->comment('税抜経理ならtrue、税込経理ならfalse');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fiscal_years', function (Blueprint $table) {
            $table->dropColumn('is_tax_exclusive');
        });
    }
};
