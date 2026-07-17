<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'sub_account_id',
        'type',
        'net_amount',
        'tax_amount',
        'tax_type',
        'tax_amount_source',
        'business_ratio',
        'allocation_group_id',
        'is_effective',
    ];

    protected $casts = [
        'net_amount' => 'integer',
        'tax_amount' => 'integer',
        'business_ratio' => 'integer',
        'is_effective' => 'boolean',
    ];

    // 定数: 借方・貸方
    public const TYPE_DEBIT = 'debit';

    public const TYPE_CREDIT = 'credit';

    public const TYPES = [
        self::TYPE_DEBIT,
        self::TYPE_CREDIT,
    ];

    // 定数: 税区分
    public const TAX_TYPE_TAXABLE_SALES_10 = 'taxable_sales_10';

    public const TAX_TYPE_TAXABLE_SALES_8 = 'taxable_sales_8';

    public const TAX_TYPE_TAXABLE_PURCHASES_10 = 'taxable_purchases_10';

    public const TAX_TYPE_TAXABLE_PURCHASES_8 = 'taxable_purchases_8';

    public const TAX_TYPE_DEEMED_TAXABLE_SALES_10 = 'deemed_taxable_sales_10';

    public const TAX_TYPE_DEEMED_TAXABLE_PURCHASES_10 = 'deemed_taxable_purchases_10';

    // exempt: 非課税。課税売上割合の計算では売上高に含まれるが、消費税は発生しない。
    public const TAX_TYPE_EXEMPT = 'exempt';

    // out_of_scope: 不課税。消費税の課税対象外で、課税売上割合の計算にも含めない。
    public const TAX_TYPE_OUT_OF_SCOPE = 'out_of_scope';

    // zero_rated: 免税。課税取引だが税率は 0% で、典型例は輸出売上。
    public const TAX_TYPE_ZERO_RATED = 'zero_rated';

    public const TAX_TYPES = [
        self::TAX_TYPE_TAXABLE_SALES_10,
        self::TAX_TYPE_TAXABLE_SALES_8,
        self::TAX_TYPE_TAXABLE_PURCHASES_10,
        self::TAX_TYPE_TAXABLE_PURCHASES_8,
        self::TAX_TYPE_DEEMED_TAXABLE_SALES_10,
        self::TAX_TYPE_DEEMED_TAXABLE_PURCHASES_10,
        self::TAX_TYPE_EXEMPT,
        self::TAX_TYPE_OUT_OF_SCOPE,
        self::TAX_TYPE_ZERO_RATED,
    ];

    public const TAX_AMOUNT_SOURCE_USER_INPUT = 'user_input';

    public const TAX_AMOUNT_SOURCE_DEFAULTED = 'defaulted';

    public const TAX_AMOUNT_SOURCE_COMPUTED_FROM_GROSS = 'computed_from_gross';

    public const TAX_AMOUNT_SOURCES = [
        self::TAX_AMOUNT_SOURCE_USER_INPUT,
        self::TAX_AMOUNT_SOURCE_DEFAULTED,
        self::TAX_AMOUNT_SOURCE_COMPUTED_FROM_GROSS,
    ];

    // リレーション
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function account(): Attribute
    {
        return Attribute::get(fn () => $this->subAccount?->account);
    }

    public function netAmount(): Attribute
    {
        return Attribute::make(
            get: fn () => (int) ($this->attributes['net_amount'] ?? 0),
        );
    }

    public function grossAmount(): Attribute
    {
        return Attribute::get(
            fn () => $this->net_amount + (int) ($this->attributes['tax_amount'] ?? 0)
        );
    }

    public function subAccount(): BelongsTo
    {
        return $this->belongsTo(SubAccount::class);
    }
}
