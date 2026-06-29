<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('credit_card_import_batch_id')
                ->nullable()
                ->after('created_by')
                ->constrained('credit_card_import_batches')
                ->nullOnDelete()
                ->comment('関連するクレジットカード取込バッチ');

            $table->index('credit_card_import_batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['credit_card_import_batch_id']);
            $table->dropConstrainedForeignId('credit_card_import_batch_id');
        });
    }
};
