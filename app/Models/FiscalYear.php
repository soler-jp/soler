<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Services\TransactionRegistrar;
use App\Models\Transaction;
use App\Models\BusinessUnit;
use App\Models\JournalEntry;


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
        'is_active' => 'boolean',
        'is_closed'  => 'boolean',
        'start_date' => 'date',
        'end_date'   => 'date',
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
        // 実績（is_planned = false）
        $actualIncome = $this->journalEntries()
            ->whereHas('transaction', fn($q) => $q->where('is_planned', false))
            ->whereHas('account', fn($q) => $q->where('type', 'revenue'))
            ->where('type', 'credit')
            ->sum(\DB::raw('amount + COALESCE(tax_amount, 0)'));

        $actualExpense = $this->journalEntries()
            ->whereHas('transaction', fn($q) => $q->where('is_planned', false))
            ->whereHas('account', fn($q) => $q->where('type', 'expense'))
            ->where('type', 'debit')
            ->sum(\DB::raw('amount + COALESCE(tax_amount, 0)'));

        // 予定（is_planned = true）
        $plannedIncome = $this->journalEntries()
            ->whereHas('transaction', fn($q) => $q->where('is_planned', true))
            ->whereHas('account', fn($q) => $q->where('type', 'revenue'))
            ->where('type', 'credit')
            ->sum(\DB::raw('amount + COALESCE(tax_amount, 0)'));

        $plannedExpense = $this->journalEntries()
            ->whereHas('transaction', fn($q) => $q->where('is_planned', true))
            ->whereHas('account', fn($q) => $q->where('type', 'expense'))
            ->where('type', 'debit')
            ->sum(\DB::raw('amount + COALESCE(tax_amount, 0)'));

        return [
            'actual' => [
                'total_income' => (int) $actualIncome,
                'total_expense' => (int) $actualExpense,
                'profit' => (int) ($actualIncome - $actualExpense),
            ],
            'planned' => [
                'total_income' => (int) $plannedIncome,
                'total_expense' => (int) $plannedExpense,
                'profit' => (int) ($plannedIncome - $plannedExpense),
            ],
        ];
    }
}
