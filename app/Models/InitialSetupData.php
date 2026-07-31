<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InitialSetupData extends Model
{
    use HasFactory;

    public const ANSWER_YES = 'yes';

    public const ANSWER_NO = 'no';

    public const BINARY_ANSWERS = [
        self::ANSWER_YES,
        self::ANSWER_NO,
    ];

    public const OPENING_CONTEXT_FIRST_YEAR = 'first_year';

    public const OPENING_CONTEXT_CARRY_FORWARD = 'carry_forward';

    public const OPENING_CONTEXTS = [
        self::OPENING_CONTEXT_FIRST_YEAR,
        self::OPENING_CONTEXT_CARRY_FORWARD,
    ];

    public const KEY_BANK_ACCOUNT = 'bank_account';

    public const KEY_CASH_ON_HAND = 'cash_on_hand';

    public const KEY_FIXED_ASSET = 'fixed_asset';

    public const KEY_RECURRING_EXPENSE = 'recurring_expense';

    public const KEY_RECURRING_INCOME = 'recurring_income';

    public const KEY_COUNTERPARTY = 'counterparty';

    protected $table = 'initial_setup_data';

    protected $fillable = [
        'business_unit_id',
        'year',
        'opening_context',
        'is_taxable',
        'bank_account_answer',
        'cash_on_hand_answer',
        'fixed_asset_answer',
        'recurring_expense_answer',
        'recurring_income_answer',
        'counterparty_answer',
        'completed_at',
    ];

    protected $casts = [
        'is_taxable' => 'boolean',
        'completed_at' => 'datetime',
    ];

    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class);
    }

    /**
     * @return array{
     *     name: string,
     *     type: string,
     *     year: int,
     *     is_taxable: bool,
     *     is_tax_exclusive: bool,
     *     opening_context: string
     * }
     */
    public function toGeneralBusinessInitializerInputs(): array
    {
        $this->loadMissing('businessUnit');

        return [
            'name' => $this->businessUnit->name,
            'type' => $this->businessUnit->type,
            'year' => $this->year,
            'is_taxable' => (bool) $this->is_taxable,
            'is_tax_exclusive' => false,
            'opening_context' => $this->opening_context,
        ];
    }
}
