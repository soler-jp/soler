<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('depreciation_entries', function (Blueprint $table) {
            $table->foreignId('transaction_id')
                ->nullable()
                ->after('deductible_amount')
                ->constrained()
                ->nullOnDelete();
        });

        DB::table('depreciation_entries')
            ->orderBy('id')
            ->get(['id', 'journal_entry_id'])
            ->each(function (object $entry): void {
                if ($entry->journal_entry_id === null) {
                    return;
                }

                $transactionId = DB::table('journal_entries')
                    ->where('id', $entry->journal_entry_id)
                    ->value('transaction_id');

                DB::table('depreciation_entries')
                    ->where('id', $entry->id)
                    ->update(['transaction_id' => $transactionId]);
            });

        Schema::table('depreciation_entries', function (Blueprint $table) {
            $table->dropForeign(['journal_entry_id']);
            $table->dropColumn('journal_entry_id');
            $table->unique(['fixed_asset_id', 'fiscal_year_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('depreciation_entries', function (Blueprint $table) {
            $table->foreignId('journal_entry_id')
                ->nullable()
                ->after('deductible_amount')
                ->constrained()
                ->nullOnDelete();
        });

        DB::table('depreciation_entries')
            ->orderBy('id')
            ->get(['id', 'transaction_id'])
            ->each(function (object $entry): void {
                if ($entry->transaction_id === null) {
                    return;
                }

                $journalEntryId = DB::table('journal_entries')
                    ->where('transaction_id', $entry->transaction_id)
                    ->orderBy('id')
                    ->value('id');

                DB::table('depreciation_entries')
                    ->where('id', $entry->id)
                    ->update(['journal_entry_id' => $journalEntryId]);
            });

        Schema::table('depreciation_entries', function (Blueprint $table) {
            $table->dropUnique('depreciation_entries_fixed_asset_id_fiscal_year_id_unique');
            $table->dropForeign(['transaction_id']);
            $table->dropColumn('transaction_id');
        });
    }
};
