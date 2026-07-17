<?php

namespace App\Services;

use App\Mail\AdminNewUserRegistered;
use App\Mail\MonthlySpendingSummary;
use App\Mail\OrderStatusChanged;
use App\Mail\PlatformMailable;
use App\Mail\TrustpilotReviewRequest;
use App\Mail\WeeklyActivitySummary;
use App\Mail\WelcomeEmail;
use App\Models\EmailNotificationSetting;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Central entry point for NEW / gap-fill notifications.
 * Does not replace existing controller Mail::send calls — those keep working
 * via PlatformMailable policy checks.
 */
class EmailNotificationService
{
    public function sendWelcome(User $user): void
    {
        $this->dispatch('welcome', $user, new WelcomeEmail($user), 'welcome:user:' . $user->id);
    }

    public function sendTrustpilotReview(User $user, ?Order $order = null): void
    {
        $key = 'trustpilot:user:' . $user->id . ':order:' . ($order?->id ?? 'none');
        $this->dispatch('trustpilot_review', $user, new TrustpilotReviewRequest($user, $order), $key);
    }

    public function notifyAdminsNewUser(User $user): void
    {
        foreach ($this->adminUsers() as $admin) {
            $this->dispatch(
                'admin_new_user',
                $admin,
                new AdminNewUserRegistered($user, $admin),
                'admin_new_user:' . $user->id . ':admin:' . $admin->id
            );
        }

        $fallback = config('mail.admin_email') ?: config('email_notifications.brand.support_email');
        if ($this->adminUsers()->isEmpty() && filled($fallback)) {
            $mailable = new AdminNewUserRegistered($user, null);
            $mailable->notificationType = 'admin_new_user';
            $mailable->dedupeKey = 'admin_new_user:' . $user->id . ':fallback';
            Mail::to($fallback)->send($mailable);
        }
    }

    public function sendWeeklySummary(User $user, array $payload): void
    {
        $this->dispatch(
            'weekly_activity_summary',
            $user,
            new WeeklyActivitySummary($user, $payload),
            'weekly_summary:' . $user->id . ':' . ($payload['week_key'] ?? now()->format('o-\WW'))
        );
    }

    public function sendMonthlySummary(User $user, array $payload): void
    {
        $this->dispatch(
            'monthly_spending_summary',
            $user,
            new MonthlySpendingSummary($user, $payload),
            'monthly_summary:' . $user->id . ':' . ($payload['month_key'] ?? now()->format('Y-m'))
        );
    }

    /**
     * Fan-out order lifecycle email to Advertiser, Publisher(s), Marketing, and Admin.
     */
    public function notifyOrderLifecycle(
        Order $order,
        string $changeKind,
        ?string $previousValue,
        string $newValue,
        ?string $description = null,
    ): void {
        if (!$this->isTypeEnabled('order_status_changed')) {
            return;
        }

        $order->loadMissing(['user', 'items.site.publisher']);
        $recipients = $this->orderLifecycleRecipients($order);

        foreach ($recipients as $row) {
            /** @var User $user */
            $user = $row['user'];
            $audience = $row['audience'];

            $dedupe = implode(':', [
                'order_status_changed',
                $order->id,
                $changeKind,
                (string) $previousValue,
                $newValue,
                $audience,
                $user->id,
            ]);

            $mailable = new OrderStatusChanged(
                order: $order,
                recipient: $user,
                audience: $audience,
                changeKind: $changeKind,
                previousValue: $previousValue,
                newValue: $newValue,
                description: $description,
            );

            // Staff always receive operational order emails
            if (in_array($audience, ['admin', 'marketing'], true)) {
                $mailable->skipUserPreference = true;
            }

            $this->dispatch('order_status_changed', $user, $mailable, $dedupe);
        }
    }

    /**
     * @return array<int, array{user: User, audience: string}>
     */
    protected function orderLifecycleRecipients(Order $order): array
    {
        $rows = [];
        $seen = [];

        $add = function (?User $user, string $audience) use (&$rows, &$seen) {
            if (!$user?->id || !$user->email) {
                return;
            }
            $key = $audience . ':' . $user->id;
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $rows[] = ['user' => $user, 'audience' => $audience];
        };

        $add($order->user, 'advertiser');

        // Publishers are notified immediately — including scheduled orders (publish on the date).
        foreach ($order->items as $item) {
            $publisher = $item->site?->publisher;
            if (!$publisher && $item->site?->publisher_id) {
                $publisher = User::query()->find($item->site->publisher_id);
            }
            $add($publisher, 'publisher');
        }

        foreach ($this->usersWithRole('admin') as $admin) {
            $add($admin, 'admin');
        }

        foreach ($this->usersWithRole('marketing') as $marketer) {
            $add($marketer, 'marketing');
        }

        // Fallback admin inbox if no admin users
        if ($this->usersWithRole('admin')->isEmpty()) {
            $fallback = config('mail.admin_email') ?: config('email_notifications.brand.support_email');
            if (filled($fallback)) {
                $ghost = new User(['name' => 'Admin', 'email' => $fallback]);
                $ghost->id = 0;
                // Direct send without user prefs
                try {
                    $mailable = new OrderStatusChanged(
                        order: $order,
                        recipient: $ghost,
                        audience: 'admin',
                        changeKind: 'status',
                        previousValue: null,
                        newValue: (string) $order->status,
                    );
                    $mailable->notificationType = 'order_status_changed';
                    $mailable->dedupeKey = 'order_status_changed:fallback:' . $order->id . ':' . $order->status;
                    Mail::to($fallback)->send($mailable);
                } catch (\Throwable $e) {
                    Log::warning('Fallback admin order email failed', ['error' => $e->getMessage()]);
                }
            }
        }

        return $rows;
    }

    public function isTypeEnabled(string $type): bool
    {
        return EmailNotificationSetting::isEnabled($type);
    }

    public function types(): array
    {
        return config('email_notifications.types', []);
    }

    protected function dispatch(string $type, ?User $recipient, PlatformMailable $mailable, string $dedupeKey): void
    {
        if (!$recipient?->email) {
            return;
        }

        $mailable->notificationType = $type;
        $mailable->dedupeKey = $dedupeKey;
        $mailable->recipientUser = $recipient;

        try {
            Mail::to($recipient->email)->send($mailable);
        } catch (\Throwable $e) {
            Log::error('EmailNotificationService dispatch failed', [
                'type' => $type,
                'to' => $recipient->email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function adminUsers(): Collection
    {
        return $this->usersWithRole('admin');
    }

    protected function usersWithRole(string $roleName): Collection
    {
        $role = Role::where('name', $roleName)->first();
        if (!$role) {
            return collect();
        }

        return User::query()
            ->whereHas('roles', fn ($q) => $q->where('roles.id', $role->id))
            ->whereNotNull('email')
            ->get();
    }
}
