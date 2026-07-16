<?php

namespace App\Services;

use App\Mail\AdminNewUserRegistered;
use App\Mail\MonthlySpendingSummary;
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
        $adminRole = Role::where('name', 'admin')->first();
        if (!$adminRole) {
            return collect();
        }

        return User::query()
            ->whereHas('roles', fn ($q) => $q->where('roles.id', $adminRole->id))
            ->whereNotNull('email')
            ->get();
    }
}
