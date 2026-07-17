<?php

namespace App\Models;

use App\Notifications\VerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;

class User extends Authenticatable implements MustVerifyEmail
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
        'google_id',
        'google_token',
        'google_refresh_token',
        'avatar',
        'active_role_id',
        'email_verified_at'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'google_token',
        'google_refresh_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * Override: Google users are automatically verified
     */
    public function hasVerifiedEmail()
    {
        if ($this->google_id) {
            return true;
        }

        return !is_null($this->email_verified_at);
    }

    /**
     * Send the email verification notification (branded, sync — not queued).
     */
    public function sendEmailVerificationNotification(): void
    {
        if ($this->hasVerifiedEmail()) {
            return;
        }

        try {
            $this->notify(new VerifyEmail);
            Log::info('Email verification notification sent', [
                'user_id' => $this->id,
                'email' => $this->email,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send email verification notification', [
                'user_id' => $this->id,
                'email' => $this->email,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /** ------------------ Roles ------------------ */

    public function roles()
    {   
        return $this->belongsToMany(Role::class, 'role_user')->withTimestamps();
    }

    public function assignRole(string $role): void
    {
        $roleModel = Role::where('name', $role)->firstOrFail();
        $this->roles()->syncWithoutDetaching([$roleModel->id]);
    }

    public function hasRole(string $role): bool
    {
        return $this->roles()->where('name', $role)->exists();
    }

    public function activeRoleRelation()
    {
        return $this->belongsTo(Role::class, 'active_role_id');
    }

    public function activeRoleModel(): ?Role
    {
        return $this->activeRoleRelation()->first() ?? $this->roles()->first();
    }

    public function activeRole(): ?string
    {
        return $this->activeRoleModel()?->name;
    }

    public function isActiveRole(string $role): bool
    {
        return $this->activeRole() === $role;
    }

    public function isAdmin(): bool
    {
        return $this->isActiveRole('admin');
    }

    public function isMarketing(): bool
    {
        return $this->isActiveRole('marketing');
    }

    /** Staff roles that share the admin panel (with different permissions). */
    public function isStaff(): bool
    {
        return in_array($this->activeRole(), ['admin', 'marketing'], true);
    }

    public function sites()
    {
        return $this->hasMany(\App\Models\Site::class, 'publisher_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /** ------------------ Wallets ------------------ */

    public function wallets()
    {
        return $this->hasMany(Wallet::class);
    }

    public function activeWallet(): ?Wallet
    {
        return $this->wallets()->where('role_id', $this->active_role_id)->first();
    }

    /** ------------------ Other Relations ------------------ */

    public function consent()
    {
        return $this->hasOne(UserConsent::class);
    }

    /** ------------------ Helper ------------------ */

    public function getDashboardRoute(): string
    {
        return match ($this->activeRole()) {
            'admin'      => route('admin.dashboard'),
            'marketing'  => route('admin.dashboard'),
            'advertiser' => route('advertiser.dashboard'),
            'publisher'  => route('publisher.dashboard'),
            default      => url('/'),
        };
    }
}