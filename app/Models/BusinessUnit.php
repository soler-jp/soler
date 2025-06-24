<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BusinessUnit extends Model
{
    use HasFactory;

    public const TYPE_GENERAL = 'general';
    public const TYPE_AGRICULTURE = 'agriculture';
    public const TYPE_REAL_ESTATE = 'real_estate';

    public const TYPES = [
        self::TYPE_GENERAL,
        self::TYPE_AGRICULTURE,
        self::TYPE_REAL_ESTATE,
    ];

    public const TYPE_LABELS = [
        self::TYPE_GENERAL => '一般',
        self::TYPE_AGRICULTURE => '農業',
        self::TYPE_REAL_ESTATE => '不動産',
    ];

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'notes',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function fiscalYears()
    {
        return $this->hasMany(FiscalYear::class);
    }

    public function accounts()
    {
        return $this->hasMany(Account::class);
    }

}
