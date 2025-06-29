<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Models\BusinessUnit;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'current_business_unit_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }



    public function businessUnits()
    {
        return $this->hasMany(BusinessUnit::class);
    }

    public function createBusinessUnitWithDefaults(array $attributes): BusinessUnit
    {
        return BusinessUnit::createWithDefaultAccounts(
            array_merge($attributes, ['user_id' => $this->id])
        );
    }

    public function selectedBusinessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'current_business_unit_id');
    }


    public function setSelectedBusinessUnit(BusinessUnit $unit): void
    {
        if ($unit->user_id !== $this->id) {
            throw new \InvalidArgumentException('他人の事業体は選択できません');
        }

        $this->update([
            'current_business_unit_id' => $unit->id,
        ]);
    }
}
