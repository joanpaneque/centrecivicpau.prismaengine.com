<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property UserRole $role
 * @property string $locale
 * @property string|null $color
 * @property string|null $tax_id
 * @property bool $active
 * @property bool $is_admin
 * @property bool $must_change_password
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property string|null $pin_hash
 * @property string|null $pin_digest
 * @property string|null $login_token
 * @property string|null $login_token_hash
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'is_admin', 'role', 'locale', 'color', 'tax_id', 'active'])]
#[Hidden(['password', 'pin_hash', 'pin_digest', 'login_token', 'login_token_hash', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_admin' => false,
        'must_change_password' => false,
        'role' => 'staff',
        'locale' => 'ca',
        'active' => true,
    ];

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->isDirty('role')) {
                $user->is_admin = $user->role === UserRole::Admin;
            } elseif ($user->isDirty('is_admin')) {
                $user->role = $user->is_admin ? UserRole::Admin : ($user->role === UserRole::Admin ? UserRole::Staff : $user->role);
            }
        });
    }

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
            'two_factor_confirmed_at' => 'datetime',
            'is_admin' => 'boolean',
            'must_change_password' => 'boolean',
            'active' => 'boolean',
            'role' => UserRole::class,
            'login_token' => 'encrypted',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isKitchen(): bool
    {
        return $this->role === UserRole::Kitchen;
    }

    public function setPin(?string $pin): void
    {
        if ($pin === null || $pin === '') {
            $this->pin_hash = null;
            $this->pin_digest = null;

            return;
        }

        $this->pin_hash = Hash::make($pin);
        $this->pin_digest = self::pinDigest($this->id, $pin);
    }

    public function checkPin(string $pin): bool
    {
        return $this->pin_hash !== null && Hash::check($pin, $this->pin_hash);
    }

    /**
     * Digest the tablets use to validate the PIN while offline (same formula in resources/js/pos/pin.ts).
     */
    public static function pinDigest(int $userId, string $pin): string
    {
        return hash('sha256', "tpv-pin:{$userId}:{$pin}");
    }

    public function regenerateLoginToken(): string
    {
        $token = Str::random(48);
        $this->login_token = $token;
        $this->login_token_hash = hash('sha256', $token);
        $this->save();

        return $token;
    }

    /**
     * @return HasMany<TimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * @return HasMany<Shift, $this>
     */
    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }
}
