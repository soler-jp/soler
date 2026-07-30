<?php

namespace App\Models;

use App\Concerns\AuthorizesBusinessUnitAccess;
use App\Contracts\ResolvesBusinessUnit;
use App\Services\PlannedTransactionConfirmer;
use App\Services\TransactionRegistrar;
use Database\Factories\RecurringTransactionPlanFactory;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RecurringTransactionPlan extends Model implements ResolvesBusinessUnit
{
    /** @use HasFactory<RecurringTransactionPlanFactory> */
    use AuthorizesBusinessUnitAccess, HasFactory;

    public const TYPE_EXPENSE = 'expense';

    public const TYPE_INCOME = 'income';

    public const TYPES = [
        self::TYPE_EXPENSE,
        self::TYPE_INCOME,
    ];

    public const WITHHOLDING_SUB_ACCOUNT_NAME = '源泉徴収';

    protected $fillable = [
        'business_unit_id',
        'name',
        'interval', // 'monthly', 'bimonthly', 'yearly'
        'month_of_year', // for 'yearly' interval
        'start_month', // for 'bimonthly' interval
        'day_of_month',
        'type',
        'is_withholding',
        'debit_sub_account_id',
        'credit_sub_account_id',
        'amount',
        'tax_amount',
        'tax_type', //
        'business_ratio',
        'withholding_tax_amount',
        'withholding_sub_account_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'type' => 'string',
        'is_withholding' => 'boolean',
        'day_of_month' => 'integer',
        'amount' => 'integer',
        'tax_amount' => 'integer',
        'business_ratio' => 'integer',
        'withholding_tax_amount' => 'integer',
    ];

    protected function grossAmount(): Attribute
    {
        return Attribute::get(fn () => $this->amount + (int) $this->tax_amount);
    }

    public function businessUnit()
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    public function resolveBusinessUnit(): BusinessUnit
    {
        $this->loadMissing('businessUnit');

        return $this->businessUnit;
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
                'type' => ['required', 'in:'.implode(',', self::TYPES)],
                'is_withholding' => ['boolean'],
                'debit_sub_account_id' => ['required', 'exists:sub_accounts,id'],
                'credit_sub_account_id' => ['required', 'exists:sub_accounts,id'],
                'amount' => ['required', 'integer', 'min:1'],
                'tax_amount' => ['nullable', 'integer', 'min:0'],
                'tax_type' => ['nullable', 'string', 'max:50'],
                'business_ratio' => ['nullable', 'integer', 'min:1', 'max:100'],
                'withholding_tax_amount' => ['nullable', 'integer', 'min:0'],
                'withholding_sub_account_id' => ['nullable', 'exists:sub_accounts,id'],
                'is_active' => ['boolean'],
                'business_unit_id' => ['required', 'exists:business_units,id'],
            ]
        );

        $validator->after(function ($validator) use ($attributes) {
            if (! empty($attributes['name']) && ! empty($attributes['business_unit_id'])) {
                $exists = RecurringTransactionPlan::where('business_unit_id', $attributes['business_unit_id'])
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
            if (! $businessUnit) {
                return;
            }

            foreach (['debit_sub_account_id', 'credit_sub_account_id', 'withholding_sub_account_id'] as $field) {
                $subAccountId = $attributes[$field] ?? null;

                if ($subAccountId && ! $businessUnit->hasSubAccount((int) $subAccountId)) {
                    $validator->errors()->add($field, '選択中の事業体に属する補助科目を指定してください。');
                }
            }
        });

        $validator->after(function ($validator) use ($attributes) {
            $type = $attributes['type'] ?? null;
            $isWithholding = (bool) ($attributes['is_withholding'] ?? false);
            $businessRatio = $attributes['business_ratio'] ?? null;
            $withholdingTaxAmount = $attributes['withholding_tax_amount'] ?? null;
            $withholdingSubAccountId = $attributes['withholding_sub_account_id'] ?? null;
            $grossAmount = (int) ($attributes['amount'] ?? 0) + (int) ($attributes['tax_amount'] ?? 0);

            if ($type === self::TYPE_INCOME && $businessRatio !== null) {
                $validator->errors()->add('business_ratio', '収入計画では事業割合を指定できません。');
            }

            if ($type === self::TYPE_EXPENSE && $isWithholding) {
                $validator->errors()->add('is_withholding', '支出計画では源泉徴収を指定できません。');
            }

            if (! $isWithholding) {
                if (! in_array($withholdingTaxAmount, [null, 0, '0'], true)) {
                    $validator->errors()->add('withholding_tax_amount', '源泉徴収税額は源泉徴収ありの収入計画でのみ指定できます。');
                }

                if ($withholdingSubAccountId !== null) {
                    $validator->errors()->add('withholding_sub_account_id', '源泉徴収補助科目は源泉徴収ありの収入計画でのみ指定できます。');
                }

                return;
            }

            if ($type !== self::TYPE_INCOME) {
                return;
            }

            if ((int) $withholdingTaxAmount < 1) {
                $validator->errors()->add('withholding_tax_amount', '源泉徴収税額は1以上で指定してください。');
            }

            if ($withholdingSubAccountId === null) {
                $validator->errors()->add('withholding_sub_account_id', '源泉徴収補助科目を指定してください。');
            }

            if ((int) $withholdingTaxAmount >= $grossAmount) {
                $validator->errors()->add('withholding_tax_amount', '源泉徴収税額は税込金額より小さくしてください。');
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
        $taxType = $this->defaultTaxType();

        if ($this->type === self::TYPE_EXPENSE) {
            $entries = [
                [
                    'sub_account_id' => $this->debit_sub_account_id,
                    'type' => JournalEntry::TYPE_DEBIT,
                    'gross_amount' => $this->gross_amount,
                    'tax_type' => $taxType,
                    'business_ratio' => $this->business_ratio,
                ],
                [
                    'sub_account_id' => $this->credit_sub_account_id,
                    'type' => JournalEntry::TYPE_CREDIT,
                    'net_amount' => $this->gross_amount,
                ],
            ];
        } elseif ($this->is_withholding) {
            $entries = [
                [
                    'sub_account_id' => $this->debit_sub_account_id,
                    'type' => JournalEntry::TYPE_DEBIT,
                    'net_amount' => $this->gross_amount - (int) $this->withholding_tax_amount,
                ],
                [
                    'sub_account_id' => $this->withholding_sub_account_id,
                    'type' => JournalEntry::TYPE_DEBIT,
                    'net_amount' => (int) $this->withholding_tax_amount,
                ],
                [
                    'sub_account_id' => $this->credit_sub_account_id,
                    'type' => JournalEntry::TYPE_CREDIT,
                    'gross_amount' => $this->gross_amount,
                    'tax_type' => $taxType,
                ],
            ];
        } else {
            $entries = [
                [
                    'sub_account_id' => $this->debit_sub_account_id,
                    'type' => JournalEntry::TYPE_DEBIT,
                    'net_amount' => $this->gross_amount,
                ],
                [
                    'sub_account_id' => $this->credit_sub_account_id,
                    'type' => JournalEntry::TYPE_CREDIT,
                    'gross_amount' => $this->gross_amount,
                    'tax_type' => $taxType,
                ],
            ];
        }

        return [
            'transaction' => [
                'date' => $date->toDateString(),
                'description' => $this->name,
                'remarks' => null,
                'is_planned' => true,
                'recurring_transaction_plan_id' => $this->id,
            ],
            'entries' => $entries,
        ];
    }

    public function confirmTransaction(int $transactionId, array $attributes, User $actor): ?Transaction
    {
        $this->authorizeBusinessUnitAccess($this, $actor, 'この定期取引を確定する権限がありません。');

        $transaction = $this->transactions()
            ->with('journalEntries')
            ->whereKey($transactionId)
            ->first();

        if (! $transaction || ! $transaction->is_planned) {
            return null;
        }

        $creditEntry = $transaction->journalEntries->firstWhere('type', 'credit');

        if (! $creditEntry) {
            return null;
        }

        $overrides = [
            'date' => $attributes['date'] ?? $transaction->date?->toDateString(),
            'description' => $transaction->description,
            'remarks' => $transaction->remarks,
            'counterparty_id' => $transaction->counterparty_id,
            'revision_reason' => $transaction->revision_reason,
            'amount' => (int) ($attributes['amount'] ?? ((int) $creditEntry->net_amount + (int) $creditEntry->tax_amount)),
        ];

        if ($this->type === self::TYPE_EXPENSE) {
            $debitEntry = $transaction->journalEntries->first(function ($entry): bool {
                return $entry->type === JournalEntry::TYPE_DEBIT && $entry->business_ratio !== null;
            }) ?? $transaction->journalEntries->firstWhere('type', JournalEntry::TYPE_DEBIT);

            if (! $debitEntry) {
                return null;
            }

            $creditSubAccountId = (int) ($attributes['credit_sub_account_id'] ?? $creditEntry->sub_account_id);

            if (! $this->businessUnit->hasSubAccount($creditSubAccountId)) {
                throw ValidationException::withMessages([
                    'credit_sub_account_id' => ['選択中の事業体に属する補助科目を指定してください。'],
                ]);
            }

            $overrides['credit_sub_account_id'] = $creditSubAccountId;

            $businessRatio = $attributes['business_ratio'] ?? $debitEntry->business_ratio ?? $this->business_ratio;

            if ($businessRatio !== null) {
                $overrides['business_ratio'] = $businessRatio;
            }
        } else {
            if (array_key_exists('business_ratio', $attributes) && $attributes['business_ratio'] !== null) {
                throw ValidationException::withMessages([
                    'business_ratio' => ['収入計画の確定では事業割合を指定できません。'],
                ]);
            }

            if ($this->is_withholding && (int) $overrides['amount'] <= (int) $this->withholding_tax_amount) {
                throw ValidationException::withMessages([
                    'amount' => ['源泉徴収税額より大きい税込金額を指定してください。'],
                ]);
            }

            $primaryDebitEntry = $transaction->journalEntries
                ->where('type', JournalEntry::TYPE_DEBIT)
                ->firstWhere('sub_account_id', '!=', $this->withholding_sub_account_id)
                ?? $transaction->journalEntries->firstWhere('type', JournalEntry::TYPE_DEBIT);

            if (! $primaryDebitEntry) {
                return null;
            }

            $debitSubAccountId = (int) ($attributes['debit_sub_account_id'] ?? $primaryDebitEntry->sub_account_id);

            if (! $this->businessUnit->hasSubAccount($debitSubAccountId)) {
                throw ValidationException::withMessages([
                    'debit_sub_account_id' => ['選択中の事業体に属する補助科目を指定してください。'],
                ]);
            }

            $overrides['debit_sub_account_id'] = $debitSubAccountId;
        }

        $confirmed = app(PlannedTransactionConfirmer::class)->confirm(
            $transaction,
            $actor,
            $overrides,
            app(TransactionRegistrar::class)->buildPlannedJournalEntries($transaction, $overrides),
        );

        if (array_key_exists('date', $attributes) && $attributes['date'] !== null) {
            $confirmed->newQuery()
                ->where('id', $confirmed->getKey())
                ->update([
                    'date' => $attributes['date'],
                    'updated_at' => now(),
                ]);

            return $confirmed->fresh(['journalEntries', 'fiscalYear']);
        }

        return $confirmed;
    }

    public function defaultTaxType(): string
    {
        if ($this->tax_type !== null) {
            return $this->tax_type;
        }

        if ((int) $this->tax_amount === 0) {
            return JournalEntry::TAX_TYPE_OUT_OF_SCOPE;
        }

        return $this->type === self::TYPE_INCOME
            ? JournalEntry::TAX_TYPE_TAXABLE_SALES_10
            : JournalEntry::TAX_TYPE_TAXABLE_PURCHASES_10;
    }
}
