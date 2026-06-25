<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\BusinessUnit;
use App\Models\Transaction;
use Illuminate\Support\Carbon;

class RecurringTransactionPlan extends Model
{
    /** @use HasFactory<\Database\Factories\RecurringTransactionPlanFactory> */
    use HasFactory;

    protected $fillable = [
        'business_unit_id',
        'name',
        'interval', // 'monthly', 'bimonthly', 'yearly'
        'month_of_year', // for 'yearly' interval
        'start_month', // for 'bimonthly' interval
        'day_of_month',
        'is_income',
        'debit_sub_account_id',
        'credit_sub_account_id',
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
        $businessUnit = isset($attributes['business_unit_id'])
            ? BusinessUnit::find($attributes['business_unit_id'])
            : null;

        $validator = Validator::make(
            $attributes,
            [
                'name' => ['required', 'string', 'max:255'],
                'interval' => ['required', 'in:monthly,bimonthly,yearly'],
                'day_of_month' => ['required', 'integer', 'min:1', 'max:31'],
                'month_of_year' => ['nullable', 'integer', 'min:1', 'max:12'],
                'start_month' => ['nullable', 'integer', 'min:1', 'max:12'],
                'is_income' => ['required', 'boolean'],
                'debit_sub_account_id' => ['required', 'exists:sub_accounts,id'],
                'credit_sub_account_id' => ['required', 'exists:sub_accounts,id'],
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

        $validator->after(function ($validator) use ($attributes, $businessUnit) {
            if (!$businessUnit) {
                return;
            }

            foreach (['debit_sub_account_id', 'credit_sub_account_id'] as $field) {
                $subAccountId = $attributes[$field] ?? null;

                if ($subAccountId && !$businessUnit->hasSubAccount((int) $subAccountId)) {
                    $validator->errors()->add($field, '選択中の事業体に属する補助科目を指定してください。');
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

        if ($this->interval === 'yearly') {
            $month = $this->month_of_year ?? 1; // デフォルト: 1月
            $day = $this->day_of_month ?? 1;    // デフォルト: 1日

            $day = min($day, Carbon::create($fiscalYear->year, $month, 1)->daysInMonth);

            $dates->push(Carbon::create($fiscalYear->year, $month, $day));

            return $dates;
        }

        $day = $this->day_of_month ?? 1;

        $startDate = Carbon::parse($fiscalYear->start_date)->startOfMonth();
        $endDate = Carbon::parse($fiscalYear->end_date);

        if ($this->interval === 'bimonthly') {

            $year = $startDate->year;

            if (is_null($this->start_month)) {
                $first = Carbon::create($year, 1, 1);
            } else {
                $first = Carbon::create($year, $this->start_month, 1);
            }

            // その月以降、2ヶ月おきに追加
            $date = $first->copy();

            while ($date->lessThanOrEqualTo($endDate)) {
                $dayToUse = min($day, $date->daysInMonth);
                $dates->push($date->copy()->day($dayToUse));
                $date->addMonths(2);
            }

            return $dates;
        }

        // monthly（毎月）の場合
        $date = $startDate->copy();

        while ($date->lessThanOrEqualTo($endDate)) {
            $dayToUse = min($day, $date->daysInMonth);
            $dates->push($date->copy()->day($dayToUse));
            $date->addMonth()->startOfMonth();
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
                    'sub_account_id' => $this->debit_sub_account_id,
                    'type' => 'debit',
                    'net_amount' => $this->amount,
                    'tax_amount' => $this->tax_amount,
                    'tax_type' => $this->tax_type,
                ],
                [
                    'sub_account_id' => $this->credit_sub_account_id,
                    'type' => 'credit',
                    'net_amount' => $this->amount + (int) $this->tax_amount,
                ],
            ],
        ];
    }

    public function confirmTransaction(int $transactionId, array $attributes): ?Transaction
    {
        $transaction = $this->transactions()
            ->with('journalEntries')
            ->whereKey($transactionId)
            ->first();

        if (!$transaction || !$transaction->is_planned) {
            return null;
        }

        $creditSubAccountId = (int) $attributes['credit_sub_account_id'];

        if (!$this->businessUnit->hasSubAccount($creditSubAccountId)) {
            throw ValidationException::withMessages([
                'credit_sub_account_id' => ['選択中の事業体に属する補助科目を指定してください。'],
            ]);
        }

        $debitEntry = $transaction->journalEntries->firstWhere('type', 'debit');
        $creditEntry = $transaction->journalEntries->firstWhere('type', 'credit');

        if (!$debitEntry || !$creditEntry) {
            return null;
        }

        $debitEntry->net_amount = $attributes['amount'];
        $debitEntry->save();

        $creditEntry->net_amount = $attributes['amount'];
        $creditEntry->sub_account_id = $creditSubAccountId;
        $creditEntry->save();

        $transaction->is_planned = false;

        if (!empty($attributes['date'])) {
            $transaction->date = Carbon::parse($attributes['date']);
        }

        $transaction->save();

        return $transaction->fresh(['journalEntries']);
    }
}
