<?php

namespace App\Models;

use App\Models\Concerns\ToleratesUnparseableDates;
use App\Notifications\VerifyEmail;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable, ToleratesUnparseableDates;

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
        'avatar',
        'new_sites_digest_sent_at',
        'deposit_reminder_day7_sent_at',
        'deposit_reminder_day14_sent_at',
        'add_site_reminder_day3_sent_at',
        'add_site_reminder_day7_sent_at',
    ];

    /**
     * Never mass-assignable: money, staff, and verify flags must be set
     * explicitly by the service that checked authorization. Factories still
     * work (they unguard). Register/Socialite only pass name/email/password.
     *
     * - email_verified_at (see SocialiteController)
     * - can_activate_sites (see Admin\UserController::updateRoles)
     * - active_role_id (RoleController / registration)
     * - google_token / google_refresh_token (SocialiteController)
     * - stripe_customer_id / stripe_default_payment_method_id (StripeCustomerService)
     * - payout_* (PayoutProfileService)
     * - catalog_reveal_exempt* (CatalogActivityController)
     * - last_seen_at (RecordUserLastSeen)
     * - suspended_at / suspended_reason / suspended_by (Admin\UserController)
     */

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
        'last_seen_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'payout_crypto_trx_verified_at' => 'datetime',
        'payout_profile_locked_at' => 'datetime',
        'can_activate_sites' => 'boolean',
        'new_sites_digest_sent_at' => 'datetime',
        'deposit_reminder_day7_sent_at' => 'datetime',
        'deposit_reminder_day14_sent_at' => 'datetime',
        'add_site_reminder_day3_sent_at' => 'datetime',
        'add_site_reminder_day7_sent_at' => 'datetime',
        'catalog_reveal_exempt' => 'boolean',
        'catalog_reveal_exempt_until' => 'datetime',
        'catalog_copy_strike_count' => 'integer',
        'catalog_copy_warned_at' => 'datetime',
        'catalog_copy_after_id' => 'integer',
        'catalog_hide_until' => 'datetime',
        'last_seen_at' => 'datetime',
        'suspended_at' => 'datetime',
    ];

    public const ONLINE_WINDOW_SECONDS = 120;

    public const LAST_SEEN_THROTTLE_SECONDS = 60;

    /**
     * Whether this account was active within the online window.
     */
    public function isOnline(): bool
    {
        $seen = $this->last_seen_at;
        if (! $seen instanceof CarbonInterface) {
            return false;
        }

        return $seen->gte(now()->subSeconds(self::ONLINE_WINDOW_SECONDS));
    }

    /**
     * Chat header copy. Null when we have never recorded activity.
     */
    public function lastSeenLabel(): ?string
    {
        $seen = $this->last_seen_at;
        if (! $seen instanceof CarbonInterface) {
            return null;
        }

        if ($this->isOnline()) {
            return 'Online';
        }

        $seconds = (int) abs($seen->diffInSeconds(now()));
        if ($seconds < 3600) {
            return 'Last seen '.max(1, (int) floor($seconds / 60)).'m ago';
        }
        if ($seconds < 86400) {
            return 'Last seen '.(int) floor($seconds / 3600).'h ago';
        }
        if ($seen->isYesterday()) {
            return 'Last seen yesterday';
        }

        return 'Last seen '.$seen->format('M j');
    }

    /**
     * @return array{online: bool, last_seen_at: ?string, label: ?string}
     */
    public function presencePayload(): array
    {
        return [
            'online' => $this->isOnline(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'label' => $this->lastSeenLabel(),
        ];
    }

    /**
     * Stamp last_seen_at at most once per throttle window.
     */
    public function touchLastSeen(): void
    {
        try {
            if (! $this->lastSeenColumnReady()) {
                return;
            }
            $seen = $this->last_seen_at;
            if ($seen instanceof CarbonInterface
                && $seen->gt(now()->subSeconds(self::LAST_SEEN_THROTTLE_SECONDS))) {
                return;
            }

            // Presence is not a profile edit — leave updated_at alone.
            $now = now();
            $wasTimestamps = $this->timestamps;
            $this->timestamps = false;
            try {
                $this->forceFill(['last_seen_at' => $now])->saveQuietly();
            } finally {
                $this->timestamps = $wasTimestamps;
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function lastSeenColumnReady(): bool
    {
        static $ready = false;
        if ($ready) {
            return true;
        }

        try {
            $ready = Schema::hasColumn('users', 'last_seen_at');
        } catch (\Throwable) {
            return false;
        }

        return $ready;
    }

    public const CATALOG_COPY_HIDDEN = 'hidden';

    public const CATALOG_COPY_POST_HIDE = 'post_hide';

    public const CATALOG_COPY_WARNED = 'warned';

    public const CATALOG_COPY_CLEAN = 'clean';

    public function inCatalogHideMode(): bool
    {
        try {
            $until = $this->catalog_hide_until ?? null;

            return $until !== null && $until->isFuture();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Copy-strike ladder for the admin catalog-activity queue.
     *
     * Strike 2 after hide expires is "served hide" (next wave re-hides),
     * not a first warning.
     */
    public function catalogCopyStatus(): string
    {
        if ($this->inCatalogHideMode()) {
            return self::CATALOG_COPY_HIDDEN;
        }

        $strikes = (int) ($this->catalog_copy_strike_count ?? 0);

        if ($strikes >= 2) {
            return self::CATALOG_COPY_POST_HIDE;
        }

        if ($strikes >= 1) {
            return self::CATALOG_COPY_WARNED;
        }

        return self::CATALOG_COPY_CLEAN;
    }

    public function payoutProfileLocked(): bool
    {
        if ($this->payout_profile_locked_at !== null) {
            return true;
        }

        // Hostinger leftover strings cast to null. A junk lock stamp is still
        // a lock — fail-closed so publishers cannot rewrite bank details.
        $raw = $this->getAttributes()['payout_profile_locked_at'] ?? null;

        return is_string($raw) && trim($raw) !== '';
    }

    /**
     * Snapshot of locked payout destinations for withdrawal forms.
     *
     * @return array<string, mixed>
     */
    public function payoutProfile(): array
    {
        return [
            'business_name' => $this->payout_business_name,
            'paypal_email' => $this->payout_paypal_email,
            'wise_email' => $this->payout_wise_email,
            'bank_holder_name' => $this->payout_bank_holder_name,
            'bank_name' => $this->payout_bank_name,
            'bank_account' => $this->payout_bank_account,
            'bank_swift' => $this->payout_bank_swift,
            'crypto_wallet' => $this->payout_crypto_trx_wallet,
            'crypto_trx_wallet' => $this->payout_crypto_trx_wallet,
            'crypto_type' => $this->payout_crypto_type,
            'crypto_trx_verified' => $this->payout_crypto_trx_verified_at !== null,
            'preferred_method' => $this->payout_preferred_method,
            'locked' => $this->payoutProfileLocked(),
            'locked_at' => optional($this->payout_profile_locked_at)?->toIso8601String(),
        ];
    }

    /**
     * Override: Google users are automatically verified
     */
    public function hasVerifiedEmail()
    {
        if ($this->google_id) {
            return true;
        }

        return ! is_null($this->email_verified_at);
    }

    /**
     * Parseable email_verified_at in the Gregorian window.
     * Leftover Hostinger strings compare as verified in SQL (`IS NOT NULL`)
     * but PHP hasVerifiedEmail() is false after ToleratesUnparseableDates.
     *
     * Google users with a null stamp stay unverified here — same as the
     * previous whereNotNull filter. Do not treat leftover as verified.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeWhereEmailVerified($query)
    {
        return $query->whereNotNull('email_verified_at')
            ->where('email_verified_at', '>=', static::PLAUSIBLE_SQL_DATETIME_FLOOR)
            ->where('email_verified_at', '<=', static::PLAUSIBLE_SQL_DATETIME_CEIL);
    }

    /**
     * Missing or leftover email_verified_at (same as PHP null after cast).
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeWhereEmailUnverified($query)
    {
        return $query->where(function ($inner) {
            $inner->whereNull('email_verified_at')
                ->orWhere('email_verified_at', '>', static::PLAUSIBLE_SQL_DATETIME_CEIL)
                ->orWhere('email_verified_at', '<', static::PLAUSIBLE_SQL_DATETIME_FLOOR);
        });
    }

    /**
     * Leftover Hostinger reminder clocks are not a real send. whereNull
     * misses them, so deposit / add-site nudges stay silenced forever.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeWhereOnboardingReminderUnsent($query, string $column)
    {
        $allowed = [
            'deposit_reminder_day7_sent_at',
            'deposit_reminder_day14_sent_at',
            'add_site_reminder_day3_sent_at',
            'add_site_reminder_day7_sent_at',
        ];
        if (! in_array($column, $allowed, true)) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where(function ($inner) use ($column) {
            $inner->whereNull($column)
                ->orWhere($column, '>', static::PLAUSIBLE_SQL_DATETIME_CEIL)
                ->orWhere($column, '<', static::PLAUSIBLE_SQL_DATETIME_FLOOR);
        });
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
        try {
            if (! Schema::hasTable('roles') || ! Schema::hasTable('role_user')) {
                return false;
            }

            return $this->roles()->where('name', $role)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    public function activeRoleRelation()
    {
        return $this->belongsTo(Role::class, 'active_role_id');
    }

    public function activeRoleModel(): ?Role
    {
        $active = null;
        try {
            if (! Schema::hasTable('roles')) {
                return null;
            }

            $active = $this->activeRoleRelation()->first();

            // belongsTo does not check the role pivot — ignore stale active_role_id
            // values that point at a role the user no longer has.
            if ($active && Schema::hasTable('role_user')
                && $this->roles()->where('roles.id', $active->id)->exists()) {
                return $active;
            }

            if (! Schema::hasTable('role_user')) {
                return $active;
            }

            return $this->roles()->first() ?: $active;
        } catch (\Throwable) {
            return $active;
        }
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

    /**
     * Staff profile in the admin panel (360 view).
     */
    public function adminShowUrl(): string
    {
        return route('admin.users.show', $this);
    }

    public function isSuspended(): bool
    {
        if (! static::hasUsersColumn('suspended_at')) {
            return false;
        }

        try {
            return $this->suspended_at !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    public function adminNotes()
    {
        return $this->hasMany(UserAdminNote::class)->latest('id');
    }

    public function staffTwoFactor()
    {
        return $this->hasOne(StaffTwoFactor::class);
    }

    public function isMarketing(): bool
    {
        return $this->isActiveRole('marketing');
    }

    /**
     * Admin and marketing can activate/deactivate sites (shared Sites Management UI).
     */
    public function canActivateSites(): bool
    {
        return $this->isAdmin() || $this->isMarketing();
    }

    /**
     * Hostinger sometimes misses the can_activate_sites migration.
     * Best-effort ADD COLUMN so Marketing role grants do not 500 on save.
     */
    public static function ensureCanActivateSitesColumn(): bool
    {
        static $ensured = false;
        if ($ensured) {
            return Schema::hasColumn('users', 'can_activate_sites');
        }
        $ensured = true;

        try {
            if (! Schema::hasTable('users')) {
                return false;
            }
            if (Schema::hasColumn('users', 'can_activate_sites')) {
                return true;
            }

            $driver = Schema::getConnection()->getDriverName();
            if (! in_array($driver, ['mysql', 'mariadb'], true)) {
                Schema::table('users', function ($table) {
                    $table->boolean('can_activate_sites')->default(false);
                });

                return Schema::hasColumn('users', 'can_activate_sites');
            }

            DB::statement('ALTER TABLE `users` ADD COLUMN `can_activate_sites` TINYINT(1) NOT NULL DEFAULT 0 AFTER `active_role_id`');
        } catch (\Throwable $e) {
            Log::warning('Could not add users.can_activate_sites', [
                'error' => $e->getMessage(),
                'hint' => 'Run database/sql/add_users_can_activate_sites.sql in phpMyAdmin',
            ]);
        }

        return Schema::hasColumn('users', 'can_activate_sites');
    }

    public function hasCanActivateSitesColumn(): bool
    {
        return self::ensureCanActivateSitesColumn();
    }

    public static function hasUsersColumn(string $column): bool
    {
        try {
            return Schema::hasTable('users') && Schema::hasColumn('users', $column);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function existingAttributes(array $attributes): array
    {
        $kept = [];
        foreach ($attributes as $column => $value) {
            if (is_string($column) && $column !== '' && static::hasUsersColumn($column)) {
                $kept[$column] = $value;
            }
        }

        return $kept;
    }

    /** Staff roles that share the admin panel (with different permissions). */
    public function isStaff(): bool
    {
        return in_array($this->activeRole(), ['admin', 'marketing'], true);
    }

    public function sites()
    {
        return $this->hasMany(Site::class, 'publisher_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function depositRequests()
    {
        return $this->hasMany(DepositRequest::class);
    }

    public function emailNotificationPreferences()
    {
        return $this->hasMany(EmailNotificationPreference::class);
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
        // Relative paths so post-login redirects stay on the current host
        // even when APP_URL is misconfigured as localhost.
        return match ($this->activeRole()) {
            'admin' => route('admin.dashboard', absolute: false),
            'marketing' => route('marketing.dashboard', absolute: false),
            'advertiser' => route('advertiser.dashboard', absolute: false),
            'publisher' => route('publisher.dashboard', absolute: false),
            default => '/',
        };
    }
}
