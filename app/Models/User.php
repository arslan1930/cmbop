<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Casts for attributes
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

    /** ------------------ Roles ------------------ */

    /**
     * Many-to-Many Roles relation
     */
    public function roles()
    {   
        
        return $this->belongsToMany(Role::class, 'role_user')->withTimestamps();
    }

    /**
     * Assign a role to the user
     */
    public function assignRole(string $role): void
    {
        $roleModel = Role::where('name', $role)->firstOrFail();
        $this->roles()->syncWithoutDetaching([$roleModel->id]);
    }

    /**
     * Check if user has a specific role
     */
    public function hasRole(string $role): bool
    {
        return $this->roles()->where('name', $role)->exists();
    }

    /** Relation to active role stored in DB */
    public function activeRoleRelation()
    {
        return $this->belongsTo(Role::class, 'active_role_id');
    }

    /**
     * Get full Role model of active role
     */
    public function activeRoleModel(): ?Role
    {
        // ✅ Updated: properly fetch the active role
        return $this->activeRoleRelation()->first() ?? $this->roles()->first();
    }

    /**
     * Get active role name
     */
    public function activeRole(): ?string
    {
        return $this->activeRoleModel()?->name;
    }

    /**
     * Check if current active role matches
     */
    public function isActiveRole(string $role): bool
    {
        return $this->activeRole() === $role;
    }

    public function sites()
{
    return $this->hasMany(\App\Models\Site::class, 'publisher_id');
}


    /** ------------------ Wallets ------------------ */

    /**
     * All wallets (per role)
     */
    public function wallets()
    {
        return $this->hasMany(Wallet::class);
    }

    /**
     * Active wallet based on active_role_id
     */
    public function activeWallet(): ?Wallet
    {
        return $this->wallets()->where('role_id', $this->active_role_id)->first();
    }

    /** ------------------ Other Relations ------------------ */

    /**
     * One-to-One Consent relation
     */
    public function consent()
    {
        return $this->hasOne(UserConsent::class);
    }

    /** ------------------ Helper ------------------ */

    /**
     * Get dashboard route based on active role
     */
    public function getDashboardRoute(): string
    {
        return match ($this->activeRole()) {
            'admin'      => route('admin.dashboard'),
            'advertiser' => route('advertiser.dashboard'),
            'publisher'  => route('publisher.dashboard'),
            default      => url('/'),
        };
    }

}