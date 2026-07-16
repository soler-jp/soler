<?php

namespace Tests\Feature\Migrations;

use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AlterTaxTypeEnumOnJournalEntriesTableTest extends TestCase
{
    #[Test]
    public function upは旧tax_typeを新enumへ変換してから列定義を変更する(): void
    {
        $migration = require base_path('database/migrations/2026_07_16_011249_alter_tax_type_enum_on_journal_entries_table.php');
        $taxTypes = implode("','", JournalEntry::TAX_TYPES);

        Schema::shouldReceive('getConnection->getDriverName')->once()->andReturn('mysql');
        DB::shouldReceive('update')
            ->once()
            ->with('UPDATE journal_entries SET tax_type = ? WHERE tax_type = ?', [
                JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
                'non_taxable',
            ])
            ->andReturn(0);
        DB::shouldReceive('update')
            ->once()
            ->with('UPDATE journal_entries SET tax_type = ? WHERE tax_type = ?', [
                JournalEntry::TAX_TYPE_ZERO_RATED,
                'tax_free',
            ])
            ->andReturn(0);
        DB::shouldReceive('statement')
            ->once()
            ->with("ALTER TABLE journal_entries MODIFY tax_type ENUM('{$taxTypes}') NULL COMMENT '税区分（課税・非課税など）'")
            ->andReturnTrue();

        $migration->up();
    }

    #[Test]
    public function downは新tax_typeを旧enumへ変換してから列定義を戻す(): void
    {
        $migration = require base_path('database/migrations/2026_07_16_011249_alter_tax_type_enum_on_journal_entries_table.php');
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

        Schema::shouldReceive('getConnection->getDriverName')->once()->andReturn('mysql');
        DB::shouldReceive('update')
            ->once()
            ->with('UPDATE journal_entries SET tax_type = ? WHERE tax_type = ?', [
                'non_taxable',
                JournalEntry::TAX_TYPE_EXEMPT,
            ])
            ->andReturn(0);
        DB::shouldReceive('update')
            ->once()
            ->with('UPDATE journal_entries SET tax_type = ? WHERE tax_type = ?', [
                'non_taxable',
                JournalEntry::TAX_TYPE_OUT_OF_SCOPE,
            ])
            ->andReturn(0);
        DB::shouldReceive('update')
            ->once()
            ->with('UPDATE journal_entries SET tax_type = ? WHERE tax_type = ?', [
                'tax_free',
                JournalEntry::TAX_TYPE_ZERO_RATED,
            ])
            ->andReturn(0);
        DB::shouldReceive('statement')
            ->once()
            ->with("ALTER TABLE journal_entries MODIFY tax_type ENUM('{$legacyTaxTypes}') NULL COMMENT '税区分（課税・非課税など）'")
            ->andReturnTrue();

        $migration->down();
    }
}
