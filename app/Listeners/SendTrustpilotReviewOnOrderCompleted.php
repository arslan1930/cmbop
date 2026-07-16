<?php

namespace App\Listeners;

use App\Models\Order;
use App\Services\EmailNotificationService;
use Illuminate\Support\Facades\Log;

/**
 * Observes order completion via model updated event (registered in AppServiceProvider).
 * Only sends Trustpilot request — does not replace publisher completion emails.
 */
class SendTrustpilotReviewOnOrderCompleted
{
    public function __construct(private EmailNotificationService $emails)
    {
    }

    public function handle(Order $order): void
    {
        if ($order->status !== 'completed') {
            return;
        }

        if (!$order->wasChanged('status')) {
            return;
        }

        $user = $order->user;
        if (!$user?->email) {
            return;
        }

        try {
            $this->emails->sendTrustpilotReview($user, $order);
        } catch (\Throwable $e) {
            Log::warning('Trustpilot review email skipped', ['error' => $e->getMessage()]);
        }
    }
}
