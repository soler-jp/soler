<?php

namespace App\Models;

use App\Contracts\ResolvesBusinessUnit;
use Database\Factories\CreditCardFactory;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CreditCard extends Model implements ResolvesBusinessUnit
{
    public const OWNERSHIP_TYPE_BUSINESS = 'business';

    public const OWNERSHIP_TYPE_PERSONAL = 'personal';

    public const OWNERSHIP_TYPES = [
        self::OWNERSHIP_TYPE_BUSINESS,
        self::OWNERSHIP_TYPE_PERSONAL,
    ];

    /** @use HasFactory<CreditCardFactory> */
    use HasFactory;

    protected $fillable = [
        'business_unit_id',
        'name',
        'issuer_name',
        'network',
        'last_four',
        'ownership_type',
        'parser_key',
        'liability_sub_account_id',
        'owner_draw_sub_account_id',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public static function validator(array $attributes): ValidatorContract
    {
        $businessUnit = isset($attributes['business_unit_id'])
            ? BusinessUnit::find($attributes['business_unit_id'])
            : null;

        $validator = Validator::make($attributes, [
            'business_unit_id' => ['required', 'exists:business_units,id'],
            'name' => ['required', 'string', 'max:255'],
            'issuer_name' => ['required', 'string', 'max:255'],
            'network' => ['nullable', 'string', 'max:255'],
            'last_four' => ['nullable', 'string', 'size:4'],
            'ownership_type' => ['required', Rule::in(self::OWNERSHIP_TYPES)],
            'parser_key' => ['required', Rule::in([
                'orico_csv_v1',
                'aeon_csv_v1',
                'rakuten_csv_v1',
                'generic_csv_v1',
            ])],
            'liability_sub_account_id' => ['nullable', 'exists:sub_accounts,id'],
            'owner_draw_sub_account_id' => ['nullable', 'exists:sub_accounts,id'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $validator->after(function ($validator) use ($attributes) {
            if (! empty($attributes['name']) && ! empty($attributes['business_unit_id'])) {
                $exists = self::query()
                    ->where('business_unit_id', $attributes['business_unit_id'])
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

            foreach (['liability_sub_account_id', 'owner_draw_sub_account_id'] as $field) {
                $subAccountId = $attributes[$field] ?? null;

                if ($subAccountId && ! $businessUnit->hasSubAccount((int) $subAccountId)) {
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

    public function getDisplayLabelAttribute(): string
    {
        $parts = array_filter([
            $this->issuer_name,
            $this->network ? strtoupper((string) $this->network) : null,
            $this->last_four ? '****'.$this->last_four : null,
        ]);

        return implode(' ', $parts);
    }

    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    public function resolveBusinessUnit(): BusinessUnit
    {
        $this->loadMissing('businessUnit');

        return $this->businessUnit;
    }

    public function liabilitySubAccount(): BelongsTo
    {
        return $this->belongsTo(SubAccount::class, 'liability_sub_account_id');
    }

    public function ownerDrawSubAccount(): BelongsTo
    {
        return $this->belongsTo(SubAccount::class, 'owner_draw_sub_account_id');
    }

    public function statements(): HasMany
    {
        return $this->hasMany(CreditCardStatement::class);
    }

    public function requiresFullRegistration(): bool
    {
        return $this->ownership_type === self::OWNERSHIP_TYPE_BUSINESS;
    }

    public function allowsSelectiveRegistration(): bool
    {
        return $this->ownership_type === self::OWNERSHIP_TYPE_PERSONAL;
    }

    public function defaultCreditSubAccountId(): ?int
    {
        return match ($this->ownership_type) {
            self::OWNERSHIP_TYPE_BUSINESS => $this->liability_sub_account_id,
            self::OWNERSHIP_TYPE_PERSONAL => $this->owner_draw_sub_account_id,
            default => null,
        };
    }
}
