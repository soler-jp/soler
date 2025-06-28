<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RecurringTransactionPlan extends Model
{
    /** @use HasFactory<\Database\Factories\RecurringTransactionPlanFactory> */
    use HasFactory;

    protected $fillable = [
        'business_unit_id',
        'name',
        'interval', // 'monthly', 'quarterly', 'yearly'
        'day_of_month',
        'is_income',
        'debit_account_id',
        'credit_account_id',
        'amount',
        'tax_amount',
        'tax_type', // 
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_income' => 'boolean',
        'day_of_month' => 'integer',
        'amount' => 'integer',
        'tax_amount' => 'integer',
    ];

    public function businessUnit()
    {
        return $this->belongsTo(BusinessUnit::class);
    }
}
