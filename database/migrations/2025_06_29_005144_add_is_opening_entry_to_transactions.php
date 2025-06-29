<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->boolean('is_opening_entry')
                ->default(false)
                ->before('is_adjusting_entry')
                ->comment('この取引が期首仕訳かどうか（true:期首仕訳、false:通常取引）');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('is_opening_entry');
        });
    }
};
