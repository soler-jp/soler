<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\BusinessUnit;
use App\Models\Transaction;

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

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public static function validator(array $attributes): ValidatorContract
    {
        $validator = Validator::make(
            $attributes,
            [
                'name' => ['required', 'string', 'max:255'],
                'interval' => ['required', 'in:monthly,bimonthly'],
                'day_of_month' => ['required', 'integer', 'min:1', 'max:31'],
                'is_income' => ['required', 'boolean'],
                'debit_account_id' => ['required', 'exists:accounts,id'],
                'credit_account_id' => ['required', 'exists:accounts,id'],
                'amount' => ['required', 'integer', 'min:1'],
                'tax_amount' => ['nullable', 'integer', 'min:0'],
                'tax_type' => ['nullable', 'string', 'max:50'],
                'is_active' => ['boolean'],
                'business_unit_id' => ['required', 'exists:business_units,id'],
            ]
        );

        $validator->after(function ($validator) use ($attributes) {
            if (!empty($attributes['name']) && !empty($attributes['business_unit_id'])) {
                $exists = \App\Models\RecurringTransactionPlan::where('business_unit_id', $attributes['business_unit_id'])
                    ->where('name', $attributes['name'])
                    ->exists();

                if ($exists) {
                    $validator->errors()->add(
                        'name',
                        "【{$attributes['name']}】はすでに使われているので使用できません"
                    );
                }
            }
        });

        return $validator;
    }


    public static function validate(array $attributes): array
    {
        $validator = self::validator($attributes);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    public function getPlannedDatesIn(FiscalYear $fiscalYear): Collection
    {
        $dates = collect();
        $date = Carbon::parse($fiscalYear->start_date)->startOfMonth();

        while ($date->lessThanOrEqualTo(Carbon::parse($fiscalYear->end_date))) {
            $day = min($this->day_of_month, $date->daysInMonth);
            $dates->push($date->copy()->day($day));

            $date->addMonths($this->interval === 'bimonthly' ? 2 : 1)->startOfMonth();
        }

        return $dates;
    }

    public function toTransactionData(Carbon $date): array
    {
        return [
            'transaction' => [
                'date' => $date->toDateString(),
                'description' => $this->name,
                'remarks' => null,
                'is_planned' => true,
                'recurring_transaction_plan_id' => $this->id,
            ],
            'entries' => [
                [
                    'account_id' => $this->debit_account_id,
                    'type' => 'debit',
                    'amount' => $this->amount,
                    'tax_amount' => $this->tax_amount,
                    'tax_type' => $this->tax_type,
                ],
                [
                    'account_id' => $this->credit_account_id,
                    'type' => 'credit',
                    'amount' => $this->amount + (int) $this->tax_amount,
                ],
            ],
        ];
    }
}
