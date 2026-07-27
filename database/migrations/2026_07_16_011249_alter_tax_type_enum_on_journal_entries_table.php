<?php

use App\Models\JournalEntry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        $taxTypes = implode("','", JournalEntry::TAX_TYPES);

        DB::statement(
            "ALTER TABLE journal_entries MODIFY tax_type ENUM('{$taxTypes}') NULL COMMENT '税区分（課税・非課税など）'"
        );
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        $legacyTaxTypes = implode("','", [
            JournalEntry::TAX_TYPE_TAXABLE_SALES_10,
            JournalEntry::TAX_TYPE_TAXABLE_SALES_8,
            JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10,
            JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_8,
            JournalEntry::TAX_TYPE_DEEMED_TAXABLE_SALES_10,
            JournalEntry::TAX_TYPE_DEEMED_TAXABLE_PURCHASES_10,
            'non_taxable',
            'tax_free',
        ]);

        DB::statement(
            "ALTER TABLE journal_entries MODIFY tax_type ENUM('{$legacyTaxTypes}') NULL COMMENT '税区分（課税・非課税など）'"
        );
    }
};
