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
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('revised_from_transaction_id')
                ->nullable()
                ->unique()
                ->constrained('transactions')
                ->nullOnDelete()
                ->after('credit_card_import_batch_id');

            $table->string('revision_reason')
                ->nullable()
                ->after('revised_from_transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['revised_from_transaction_id']);
            $table->dropUnique('transactions_revised_from_transaction_id_unique');
            $table->dropColumn(['revised_from_transaction_id', 'revision_reason']);
        });
    }
};
