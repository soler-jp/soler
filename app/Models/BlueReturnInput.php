<?php

namespace App\Models;

use App\Contracts\ResolvesBusinessUnit;
use Database\Factories\BlueReturnInputFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlueReturnInput extends Model implements ResolvesBusinessUnit
{
    public const KEY_FAMILY_EMPLOYEE_SALARIES = 'family_employee_salaries';

    public const KEY_RENT_EXPENSES = 'rent_expenses';

    public const KEYS = [
        self::KEY_FAMILY_EMPLOYEE_SALARIES,
        self::KEY_RENT_EXPENSES,
    ];

    /** @use HasFactory<BlueReturnInputFactory> */
    use HasFactory;

    protected $fillable = [
        'fiscal_year_id',
        'key',
        'value',
    ];

    protected $casts = [
        'value' => 'json:unicode',
    ];

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function resolveBusinessUnit(): BusinessUnit
    {
        $this->loadMissing('fiscalYear.businessUnit');

        return $this->fiscalYear->businessUnit;
    }

    public function isFamilyEmployeeSalaries(): bool
    {
        return $this->key === self::KEY_FAMILY_EMPLOYEE_SALARIES;
    }

    public function isRentExpenses(): bool
    {
        return $this->key === self::KEY_RENT_EXPENSES;
    }
}
