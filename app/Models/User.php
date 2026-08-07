<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Exceptions\PhysicalDeletionNotAllowed;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        // User の物理削除は初期実装ではサポートしない。
        // 監査ログ (actor_id) や会計データが長期保存対象になるため、
        // 削除は「退会」「無効化」「匿名化」の別フローとして設計する必要がある。
        // 現状はドメイン上の禁則として fail-closed で拒否する。
        static::deleting(function (User $user): void {
            throw new PhysicalDeletionNotAllowed(
                'User の物理削除は許可されていません。'
                .'退会や無効化は将来の専用フローで扱います。',
            );
        });
    }

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

        return \DB::transaction(function () use ($attributes) {
            $bu = BusinessUnit::createWithDefaultAccounts(
                array_merge($attributes, ['user_id' => $this->id])
            );

            $this->current_business_unit_id = $bu->id;
            $this->save();

            return $bu;
        });
    }

    public function selectedBusinessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'current_business_unit_id');
    }

    public function selectedBusinessUnitOrFail(): BusinessUnit
    {
        $businessUnit = $this->selectedBusinessUnit;

        if ($businessUnit === null) {
            throw new AuthorizationException('選択中の事業体がありません。');
        }

        return $businessUnit;
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
