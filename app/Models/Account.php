<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Account extends Model
{
    use HasFactory;

    // 勘定科目の5分類（type）
    public const TYPE_ASSET     = 'asset';     // 資産
    public const TYPE_LIABILITY = 'liability'; // 負債
    public const TYPE_EQUITY    = 'equity';    // 資本
    public const TYPE_REVENUE   = 'revenue';   // 収益
    public const TYPE_EXPENSE   = 'expense';   // 費用

    public const TYPES = [
        self::TYPE_ASSET,
        self::TYPE_LIABILITY,
        self::TYPE_EQUITY,
        self::TYPE_REVENUE,
        self::TYPE_EXPENSE,
    ];

    protected $fillable = [
        'business_unit_id',
        'name',
        'type',
    ];

    public function businessUnit()
    {
        return $this->belongsTo(BusinessUnit::class);
    }
}
