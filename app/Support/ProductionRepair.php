<?php

namespace App\Support;

use App\Models\BillingRuleSetting;
use App\Models\LegalPageOverride;
use App\Models\StaffCapability;
use App\Models\StaffTwoFactor;
use App\Models\WelcomeBonusClaim;
use App\Models\WelcomeBonusSetting;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The Hostinger steps this agent cannot SSH in to run: migrate, MEDIA_PATH,
 * public/storage, APP_URL, and roles. Safe to call from artisan or a web
 * request (locked by HealHostingerProduction).
 */
class ProductionRepair
{
    /**
     * @return list<string>
     */
    public function run(bool $persistEnv = true): array
    {
        $notes = [];

        $wroteEnv = false;

        $this->migrate($notes);
        $this->seedRoles($notes);
        $this->ensureMedia($notes, $persistEnv, $wroteEnv);
        $this->ensureAppUrl($notes, $persistEnv, $wroteEnv);
        $this->ensurePaypalLiveMode($notes, $persistEnv, $wroteEnv);
        $this->ensureStorageLink($notes);

        if ($wroteEnv) {
            $this->refreshCachedConfig();
        }

        return $notes;
    }

    /**
     * @param  list<string>  $notes
     */
    private function migrate(array &$notes): void
    {
        try {
            if ($this->needsOrderedBootstrap()) {
                foreach ($this->bootstrapMigrationFiles() as $file) {
                    Artisan::call('migrate', [
                        '--force' => true,
                        '--path' => 'database/migrations/'.$file,
                    ]);
                }
                $notes[] = 'bootstrapped users/sites/orders/order_items for Hostinger migrate order';
            }

            $code = Artisan::call('migrate', ['--force' => true]);
            $notes[] = $code === 0
                ? 'migrate --force completed'
                : 'migrate --force exited '.$code;
        } catch (\Throwable $e) {
            $notes[] = 'migrate failed: '.$e->getMessage();
            Log::error('Production repair migrate failed', ['error' => $e->getMessage()]);
        }

        $this->ensureWelcomeBonusMigrations($notes);
        $this->ensureBillingRuleSettings($notes);
        $this->ensureStaffTwoFactor($notes);
        $this->ensureStaffCapabilities($notes);
        $this->ensureLegalPageOverrides($notes);
    }

    /**
     * Web heal must not cache a 6-hour skip when migrate did not finish.
     * A 1059 (or any later) failure is swallowed above so MEDIA_PATH still
     * runs — the flag has to look at notes, not whether run() threw.
     *
     * @param  list<string>  $notes
     */
    public static function migrateCompleted(array $notes): bool
    {
        $sawCompleted = false;

        foreach ($notes as $note) {
            if (! is_string($note)) {
                continue;
            }

            if (str_starts_with($note, 'migrate failed:')
                || str_starts_with($note, 'migrate --force exited')) {
                return false;
            }

            if ($note === 'migrate --force completed') {
                $sawCompleted = true;
            }
        }

        return $sawCompleted;
    }

    /**
     * Filename timestamps are out of dependency order. A clean Hostinger
     * `migrate --force` fails unless users/sites/orders/order_items exist first.
     */
    private function needsOrderedBootstrap(): bool
    {
        try {
            if (! app('migrator')->repositoryExists()) {
                return true;
            }

            return ! Schema::hasTable('users')
                || ! Schema::hasTable('sites')
                || ! Schema::hasTable('orders')
                || ! Schema::hasTable('order_items');
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @return list<string>
     */
    private function bootstrapMigrationFiles(): array
    {
        return [
            '0001_01_01_000000_create_users_table.php',
            '2026_04_06_094704_create_sites_table.php',
            '2026_04_21_070134_create_orders_table.php',
            '2026_04_21_070217_create_order_items_table.php',
        ];
    }

    /**
     * @param  list<string>  $notes
     */
    private function seedRoles(array &$notes): void
    {
        try {
            (new RolesTableSeeder)->run();
            $notes[] = 'roles seeded (advertiser, publisher, admin, marketing)';
        } catch (\Throwable $e) {
            $notes[] = 'roles seed failed: '.$e->getMessage();
            Log::error('Production repair roles seed failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param  list<string>  $notes
     */
    private function ensureMedia(array &$notes, bool $persistEnv, bool &$wroteEnv): void
    {
        $path = HostingerMediaPath::ensure();
        if ($path === null) {
            $notes[] = 'MEDIA_PATH left unset (no Hostinger /home or public_html parent)';

            return;
        }

        HostingerMediaPath::applyRuntime($path);
        $wrote = $this->persistKey('MEDIA_PATH', $path, $persistEnv);
        $wroteEnv = $wroteEnv || $wrote;
        $notes[] = $wrote
            ? 'MEDIA_PATH set to '.$path
            : 'MEDIA_PATH using '.$path;
    }

    /**
     * @param  list<string>  $notes
     */
    private function ensureAppUrl(array &$notes, bool $persistEnv, bool &$wroteEnv): void
    {
        if (! app()->environment('production') && ! HostingerMediaPath::looksLikeHostinger()) {
            return;
        }

        $url = rtrim((string) config('app.url'), '/');
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        $loopback = $host === ''
            || in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.localhost');

        if (! $loopback) {
            return;
        }

        $fallback = rtrim((string) config('app.public_url'), '/');
        $fallbackHost = strtolower((string) (parse_url($fallback, PHP_URL_HOST) ?: ''));
        if ($fallback === '' || $fallbackHost === '' || in_array($fallbackHost, ['localhost', '127.0.0.1', '::1'], true)) {
            $notes[] = 'APP_URL still loopback; set PUBLIC_APP_URL or APP_URL to the public origin';

            return;
        }

        config(['app.url' => $fallback]);
        $wrote = $this->persistKey('APP_URL', $fallback, $persistEnv);
        $wroteEnv = $wroteEnv || $wrote;
        $notes[] = $wrote
            ? 'APP_URL written from PUBLIC_APP_URL ('.$fallback.')'
            : 'APP_URL runtime set from PUBLIC_APP_URL ('.$fallback.')';
    }

    /**
     * .env.example defaults PAYPAL_MODE=sandbox. Live REST keys against that
     * host return 400/401 and checkout looks "temporarily unavailable".
     *
     * @param  list<string>  $notes
     */
    private function ensurePaypalLiveMode(array &$notes, bool $persistEnv, bool &$wroteEnv): void
    {
        if (! app()->environment('production') && ! HostingerMediaPath::looksLikeHostinger()) {
            return;
        }

        $allow = config('services.paypal.allow_sandbox');
        $allowSandbox = $allow === true
            || $allow === 1
            || (is_string($allow) && in_array(strtolower(trim($allow)), ['1', 'true', 'on', 'yes'], true));
        if ($allowSandbox) {
            return;
        }

        $mode = strtolower(trim((string) config('services.paypal.mode', 'sandbox')));
        if ($mode === 'live') {
            return;
        }

        config(['services.paypal.mode' => 'live']);
        $wrote = $this->persistKey('PAYPAL_MODE', 'live', $persistEnv);
        $wroteEnv = $wroteEnv || $wrote;
        $notes[] = $wrote
            ? 'PAYPAL_MODE set to live (production)'
            : 'PAYPAL_MODE runtime set to live (production)';
    }

    private function persistKey(string $key, string $value, bool $persistEnv): bool
    {
        if (! $persistEnv || static::runningAutomatedTest()) {
            return false;
        }

        if (! DotEnvWriter::set($key, $value)) {
            return false;
        }

        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;

        return true;
    }

    /**
     * runningUnitTests() is false after tests set app.env to production
     * (Hostinger repair coverage). Still never write the real .env or
     * run web heal inside PHPUnit.
     */
    public static function runningAutomatedTest(): bool
    {
        if (app()->runningUnitTests()) {
            return true;
        }

        $env = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV');

        return is_string($env) && strtolower($env) === 'testing';
    }

    public static function promotionsStorageReady(): bool
    {
        try {
            return static::welcomeBonusStorageReady()
                && Schema::hasTable('site_announcements')
                && Schema::hasTable('ad_banners');
        } catch (\Throwable) {
            return false;
        }
    }

    public static function welcomeBonusStorageReady(): bool
    {
        try {
            return Schema::hasTable('welcome_bonus_settings')
                && Schema::hasTable('welcome_bonus_claims');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * A later unrelated migrate (FK replace, unique on dirty data) can abort
     * the batch before 2026_08_14_180000. `--path` can still create the
     * welcome-bonus tables so Promotions is not stuck on Unknown.
     *
     * @param  list<string>  $notes
     */
    public function ensureWelcomeBonusMigrations(array &$notes): void
    {
        if (static::welcomeBonusStorageReady()) {
            return;
        }

        foreach ($this->welcomeBonusMigrationFiles() as $file) {
            try {
                Artisan::call('migrate', [
                    '--force' => true,
                    '--path' => 'database/migrations/'.$file,
                ]);
            } catch (\Throwable $e) {
                $notes[] = 'welcome bonus migrate '.$file.' failed: '.$e->getMessage();
                Log::error('Welcome bonus migrate failed', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (! Schema::hasTable('welcome_bonus_settings')) {
            WelcomeBonusSetting::ensureTable();
        }

        if (! Schema::hasTable('welcome_bonus_claims')) {
            WelcomeBonusClaim::ensureTable();
        }

        if (static::welcomeBonusStorageReady()) {
            $notes[] = 'welcome bonus tables ready';
        }
    }

    /**
     * @return list<string>
     */
    private function welcomeBonusMigrationFiles(): array
    {
        return [
            '2026_08_14_180000_create_welcome_bonus_settings_table.php',
            '2026_08_14_180100_create_welcome_bonus_claims_table.php',
            '2026_08_15_103800_keep_welcome_bonus_claims_after_user_delete.php',
            '2026_08_15_110800_unique_welcome_bonus_claim_place.php',
            '2026_08_15_112000_unique_welcome_bonus_settings_key.php',
        ];
    }

    /**
     * Finance payout rules overlay. `--path` can still create the table when
     * a later unrelated migrate aborted the batch.
     *
     * @param  list<string>  $notes
     */
    public function ensureBillingRuleSettings(array &$notes): void
    {
        if (static::billingRuleStorageReady()) {
            return;
        }

        foreach ($this->billingRuleMigrationFiles() as $file) {
            try {
                Artisan::call('migrate', [
                    '--force' => true,
                    '--path' => 'database/migrations/'.$file,
                ]);
            } catch (\Throwable $e) {
                $notes[] = 'billing rules migrate '.$file.' failed: '.$e->getMessage();
                Log::error('Billing rule settings migrate failed', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (! Schema::hasTable('billing_rule_settings')) {
            BillingRuleSetting::ensureTable();
        }

        if (static::billingRuleStorageReady()) {
            $notes[] = 'billing rule settings table ready';
        }
    }

    public static function billingRuleStorageReady(): bool
    {
        try {
            return Schema::hasTable('billing_rule_settings');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private function billingRuleMigrationFiles(): array
    {
        return [
            '2026_09_14_213000_create_billing_rule_settings_table.php',
        ];
    }

    /**
     * @param  list<string>  $notes
     */
    public function ensureStaffTwoFactor(array &$notes): void
    {
        if (static::staffTwoFactorStorageReady()) {
            return;
        }

        foreach ($this->staffTwoFactorMigrationFiles() as $file) {
            try {
                Artisan::call('migrate', [
                    '--force' => true,
                    '--path' => 'database/migrations/'.$file,
                ]);
            } catch (\Throwable $e) {
                $notes[] = 'staff two-factor migrate '.$file.' failed: '.$e->getMessage();
                Log::error('Staff two-factor migrate failed', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (! Schema::hasTable('staff_two_factor')) {
            StaffTwoFactor::ensureTable();
        }

        if (static::staffTwoFactorStorageReady()) {
            $notes[] = 'staff two-factor table ready';
        }
    }

    public static function staffTwoFactorStorageReady(): bool
    {
        try {
            return Schema::hasTable('staff_two_factor');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private function staffTwoFactorMigrationFiles(): array
    {
        return [
            '2026_09_14_230000_create_staff_two_factor_table.php',
        ];
    }

    /**
     * @param  list<string>  $notes
     */
    public function ensureStaffCapabilities(array &$notes): void
    {
        if (static::staffCapabilityStorageReady()) {
            return;
        }

        foreach ($this->staffCapabilityMigrationFiles() as $file) {
            try {
                Artisan::call('migrate', [
                    '--force' => true,
                    '--path' => 'database/migrations/'.$file,
                ]);
            } catch (\Throwable $e) {
                $notes[] = 'staff capabilities migrate '.$file.' failed: '.$e->getMessage();
                Log::error('Staff capabilities migrate failed', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (! Schema::hasTable('staff_capabilities')) {
            StaffCapability::ensureTable();
        }

        if (static::staffCapabilityStorageReady()) {
            $notes[] = 'staff capabilities table ready';
        }
    }

    public static function staffCapabilityStorageReady(): bool
    {
        try {
            return Schema::hasTable('staff_capabilities');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private function staffCapabilityMigrationFiles(): array
    {
        return [
            '2026_09_15_040000_create_staff_capabilities_table.php',
        ];
    }

    /**
     * Per-locale HTML overrides for privacy/terms/cookie/refund. `--path` can
     * still create the table when a later unrelated migrate aborted the batch.
     *
     * @param  list<string>  $notes
     */
    public function ensureLegalPageOverrides(array &$notes): void
    {
        if (static::legalPageOverrideStorageReady()) {
            return;
        }

        foreach ($this->legalPageOverrideMigrationFiles() as $file) {
            try {
                Artisan::call('migrate', [
                    '--force' => true,
                    '--path' => 'database/migrations/'.$file,
                ]);
            } catch (\Throwable $e) {
                $notes[] = 'legal page overrides migrate '.$file.' failed: '.$e->getMessage();
                Log::error('Legal page overrides migrate failed', [
                    'file' => $file,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (! Schema::hasTable('legal_page_overrides')) {
            LegalPageOverride::forgetTableAvailabilityCache();
            LegalPageOverride::ensureTable();
        }

        if (static::legalPageOverrideStorageReady()) {
            $notes[] = 'legal page overrides table ready';
        }
    }

    public static function legalPageOverrideStorageReady(): bool
    {
        try {
            return Schema::hasTable('legal_page_overrides');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private function legalPageOverrideMigrationFiles(): array
    {
        return [
            '2026_09_15_051500_create_legal_page_overrides_table.php',
        ];
    }

    private function refreshCachedConfig(): void
    {
        if (! is_file(base_path('bootstrap/cache/config.php'))) {
            return;
        }

        try {
            Artisan::call('config:clear');
        } catch (\Throwable $e) {
            Log::warning('Production repair could not clear config cache', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param  list<string>  $notes
     */
    private function ensureStorageLink(array &$notes): void
    {
        $link = PublicStorageLink::ensure();
        if ($link['ok']) {
            $notes[] = $link['repaired']
                ? 'public/storage symlink repaired'
                : 'public/storage already correct';

            return;
        }

        $notes[] = $link['message'] ?? 'public/storage link failed';
    }
}
