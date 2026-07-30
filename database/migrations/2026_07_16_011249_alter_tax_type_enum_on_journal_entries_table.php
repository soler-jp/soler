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

        $this->updateTaxTypeValuesForNewEnum();

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

        $this->updateTaxTypeValuesForLegacyEnum();

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

    private function updateTaxTypeValuesForNewEnum(): void
    {
        DB::update(
            'UPDATE journal_entries SET tax_type = ? WHERE tax_type = ?',
            [JournalEntry::TAX_TYPE_OUT_OF_SCOPE, 'non_taxable']
        );

        DB::update(
            'UPDATE journal_entries SET tax_type = ? WHERE tax_type = ?',
            [JournalEntry::TAX_TYPE_ZERO_RATED, 'tax_free']
        );
    }

    private function updateTaxTypeValuesForLegacyEnum(): void
    {
        DB::update(
            'UPDATE journal_entries SET tax_type = ? WHERE tax_type = ?',
            ['non_taxable', JournalEntry::TAX_TYPE_EXEMPT]
        );

        DB::update(
            'UPDATE journal_entries SET tax_type = ? WHERE tax_type = ?',
            ['non_taxable', JournalEntry::TAX_TYPE_OUT_OF_SCOPE]
        );

        DB::update(
            'UPDATE journal_entries SET tax_type = ? WHERE tax_type = ?',
            ['tax_free', JournalEntry::TAX_TYPE_ZERO_RATED]
        );
    }
};
