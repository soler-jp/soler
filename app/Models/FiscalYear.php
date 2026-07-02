<?php

namespace App\Models;

use App\Services\FiscalYearSummaryCalculator;
use App\Services\OpeningEntryRegistrar;
use App\Services\TransactionRegistrar;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FiscalYear extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_unit_id',
        'year',
        'is_active',
        'is_closed',    // 決算済フラグ
        'is_taxable',   // 課税事業者ならtrue, 免税事業者なfalse
        'is_tax_exclusive',  // 税抜経理ならtrue, 税込経理ならfalse
        'start_date',
        'end_date',

    ];

    protected $casts = [
        'is_taxable' => 'boolean',
        'is_tax_exclusive' => 'boolean',
        'is_active' => 'boolean',
        'is_closed' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function businessUnit()
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function journalEntries()
    {
        return $this->hasManyThrough(
            JournalEntry::class,
            Transaction::class
        );
    }

    public function registerTransaction(
        array $transactionData,
        array $journalEntriesData,
        ?TransactionRegistrar $registrar = null
    ): Transaction {
        $registrar ??= app(TransactionRegistrar::class);

        return $registrar->register($this, $transactionData, $journalEntriesData);
    }

    public function calculateSummary(): array
    {
        return app(FiscalYearSummaryCalculator::class)->calculate($this);
    }

    public function calculateAmountSummary(): array
    {
        return app(FiscalYearSummaryCalculator::class)->calculateAmountSummary($this);
    }

    public function registerOpeningEntry(array $entries): ?Transaction
    {
        return app(OpeningEntryRegistrar::class)->register($this, $entries);
    }
}
