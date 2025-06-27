<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Services\TransactionRegistrar;
use App\Models\Transaction;

class FiscalYear extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_unit_id',
        'year',
        'is_active',
        'is_closed',    // 決算済フラグ
        'is_tax_exclusive',
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

    public function registerTransaction(
        array $transactionData,
        array $journalEntriesData,
        ?TransactionRegistrar $registrar = null
    ): Transaction {
        $registrar ??= app(TransactionRegistrar::class);

        return $registrar->register($this, $transactionData, $journalEntriesData);
    }
}
