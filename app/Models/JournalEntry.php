<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Transaction;
use App\Models\Account;
use App\Models\SubAccount;

class JournalEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'account_id',
        'sub_account_id',
        'type',
        'amount',
        'tax_amount',
        'tax_type',
        'is_effective',
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
    public const TAX_TYPE_NON_TAXABLE = 'non_taxable';
    public const TAX_TYPE_TAX_FREE = 'tax_free';

    public const TAX_TYPES = [
        self::TAX_TYPE_TAXABLE_SALES_10,
        self::TAX_TYPE_TAXABLE_SALES_8,
        self::TAX_TYPE_TAXABLE_PURCHASES_10,
        self::TAX_TYPE_NON_TAXABLE,
        self::TAX_TYPE_TAX_FREE,
    ];

    // リレーション
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function account(): Attribute
    {
        return Attribute::get(fn() => $this->subAccount?->account);
    }

    public function subAccount(): BelongsTo
    {
        return $this->belongsTo(SubAccount::class);
    }
}
