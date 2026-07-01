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
        Schema::table('counterparties', function (Blueprint $table) {
            $table->string('qualification_status')->default('unknown')->after('registration_number');
            $table->dropColumn('is_qualified_invoice_issuer');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('counterparties', function (Blueprint $table) {
            $table->dropColumn('qualification_status');
            $table->boolean('is_qualified_invoice_issuer')->default(false)->after('registration_number');
        });
    }
};
