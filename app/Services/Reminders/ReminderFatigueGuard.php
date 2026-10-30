<?php

namespace App\Services\Reminders;

use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Ceiling on how many nudges one person can receive in a rolling day.
 *
 * The reminder tracks each hold their own state, so none of them can see the
 * others. Without a shared cap, a publisher who is late on four orders and has
 * two unaccepted ones gets six emails in a morning and mutes us — which costs
 * more than the late orders did.
 *
 * Transactional mail is never counted or blocked; only the types listed here.
 */
class ReminderFatigueGuard
{
    /**
     * Notification types this guard governs.
     *
     * @var list<string>
     */
    public const REMINDER_TYPES = [
        'publisher_accept_nudge',
        'publisher_publish_nudge',
        'advertiser_review_nudge',
        'advertiser_order_stalled',
        'new_sites_digest',
        'deposit_reminder',
        'publisher_add_site_reminder',
        'abandoned_cart_reminder',
    ];

    /** @var array<int, int> */
    private array $sentThisRun = [];

    public function allows(User|int $user): bool
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;
        $cap = (int) config('reminders.daily_cap_per_user', 2);

        if ($cap <= 0) {
            return true;
        }

        return ($this->sentToday($userId) + ($this->sentThisRun[$userId] ?? 0)) < $cap;
    }

    /**
     * Count a send the guard just permitted.
     *
     * Delivery is asynchronous, so EmailLog will not show this send for a while;
     * a command sending to the same person twice in one run has to be counted
     * in memory or the cap does nothing.
     */
    public function record(User|int $user): void
    {
        $userId = $user instanceof User ? (int) $user->id : (int) $user;
        $this->sentThisRun[$userId] = ($this->sentThisRun[$userId] ?? 0) + 1;
    }

    private function sentToday(int $userId): int
    {
        try {
            if (! Schema::hasTable((new EmailLog)->getTable())) {
                return 0;
            }

            $email = User::whereKey($userId)->value('email');

            if (! $email) {
                return 0;
            }

            return EmailLog::query()
                ->where('to_email', $email)
                ->whereIn('notification_type', self::REMINDER_TYPES)
                ->where('created_at', '>=', now()->subDay())
                ->count();
        } catch (\Throwable $e) {
            // Fail open: a broken log must not silence every reminder.
            Log::warning('Reminder fatigue check failed; allowing send', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }
}
