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
        Schema::table('credit_card_statement_lines', function (Blueprint $table) {
            $table->dropColumn('posted_on');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('credit_card_statement_lines', function (Blueprint $table) {
            $table->date('posted_on')
                ->nullable()
                ->comment('カード会社側の計上日')
                ->after('used_on');
        });
    }
};
