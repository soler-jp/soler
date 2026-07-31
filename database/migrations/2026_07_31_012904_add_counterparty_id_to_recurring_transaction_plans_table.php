<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_transaction_plans', function (Blueprint $table) {
            $table->foreignId('counterparty_id')
                ->nullable()
                ->after('business_unit_id')
                ->constrained('counterparties')
                ->nullOnDelete()
                ->comment('生成する取引に付与する取引先');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_transaction_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('counterparty_id');
        });
    }
};
