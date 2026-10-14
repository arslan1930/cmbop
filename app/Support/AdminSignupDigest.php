<?php

namespace App\Support;

use App\Jobs\SendAdminSignupDigestJob;
use App\Mail\AdminNewUsersDigest;
use App\Models\User;
use App\Services\EmailNotificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Batch admin "new user registered" mail so Hostinger is not hit per signup.
 */
class AdminSignupDigest
{
    public const CACHE_KEY = 'admin_signup_digest_user_ids';

    public static function enabled(): bool
    {
        return (bool) config('email_notifications.admin_signup_digest.enabled', true);
    }

    public static function windowMinutes(): int
    {
        return max(1, (int) config('email_notifications.admin_signup_digest.window_minutes', 15));
    }

    public function remember(User $user): void
    {
        if (! $user->id) {
            return;
        }

        $ids = $this->pendingIds();
        $ids[] = (int) $user->id;
        $ids = array_values(array_unique(array_filter($ids)));
        Cache::put(self::CACHE_KEY, $ids, now()->addHours(6));

        SendAdminSignupDigestJob::dispatch()->delay(now()->addMinutes(self::windowMinutes()));
    }

    /**
     * @return list<int>
     */
    public function pendingIds(): array
    {
        $ids = Cache::get(self::CACHE_KEY, []);
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    public function flush(): int
    {
        $ids = $this->pendingIds();
        Cache::forget(self::CACHE_KEY);
        if ($ids === []) {
            return 0;
        }

        $users = User::query()->whereIn('id', $ids)->orderBy('id')->get();
        if ($users->isEmpty()) {
            return 0;
        }

        $mailer = app(EmailNotificationService::class);
        $sent = 0;
        foreach ($mailer->staffAdminUsers() as $admin) {
            $email = MailAddress::normalize($admin->email);
            if ($email === null) {
                Log::warning('Skipped admin signup digest — invalid admin email', [
                    'admin_id' => $admin->id,
                    'email' => $admin->email,
                ]);

                continue;
            }

            try {
                $mailable = new AdminNewUsersDigest($admin, $users);
                Mail::to($email)->send($mailable);
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('Admin signup digest send failed', [
                    'admin_id' => $admin->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($sent === 0) {
            $fallback = MailAddress::normalize(
                config('mail.admin_email') ?: config('email_notifications.brand.support_email')
            );
            if ($fallback) {
                try {
                    Mail::to($fallback)->send(new AdminNewUsersDigest(null, $users));
                    $sent++;
                } catch (\Throwable $e) {
                    Log::warning('Fallback admin signup digest failed', ['error' => $e->getMessage()]);
                }
            }
        }

        return $sent;
    }
}
