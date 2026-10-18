<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    protected $attributes = ['standing' => 'good', 'is_staff' => false];

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
            'is_staff' => 'boolean',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function isStaff(): bool
    {
        return $this->is_staff && $this->standing === 'good';
    }

    public function stewardships(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Stewardship::class);
    }

    /** Horses this person has been invited to and currently contributes to as a professional. */
    public function contributions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Contributor::class)->where('status', 'active');
    }

    /** Horses this person currently looks after. */
    public function horses()
    {
        return Horse::whereHas('stewardships', fn ($q) => $q->where('user_id', $this->id)->where('status', '!=', 'ended'));
    }
}
