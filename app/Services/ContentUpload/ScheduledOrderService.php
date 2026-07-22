<?php

namespace App\Services\ContentUpload;

use App\Models\Order;
use App\Services\EmailNotificationService;
use App\Services\InAppNotificationService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ScheduledOrderService
{
    public function __construct(
        private ContentUploadService $uploads,
        private EmailNotificationService $emails,
        private InAppNotificationService $inApp,
    ) {}

    public function maxScheduleAt(?Carbon $from = null): Carbon
    {
        $cfg = $this->uploads->effectiveConfig();
        $months = max(1, (int) ($cfg['scheduling']['max_months'] ?? 3));

        return ($from ?? now())->copy()->addMonthsNoOverflow($months)->endOfDay();
    }

    /**
     * Validate schedule fields from checkout.
     *
     * @return array{ok:bool, mode:string, at:?Carbon, timezone:string, message?:string}
     */
    public function normalizeSchedule(?string $mode, ?string $date, ?string $time, ?string $timezone): array
    {
        $cfg = $this->uploads->effectiveConfig();
        $schedulingOn = (bool) ($cfg['scheduling']['enabled'] ?? true);
        $tz = $timezone ?: ($cfg['scheduling']['default_timezone'] ?? 'UTC');

        try {
            new \DateTimeZone($tz);
        } catch (\Throwable) {
            $tz = 'UTC';
        }

        $mode = ($mode === 'scheduled' && $schedulingOn) ? 'scheduled' : 'immediate';

        if ($mode !== 'scheduled') {
            return ['ok' => true, 'mode' => 'immediate', 'at' => null, 'timezone' => $tz];
        }

        if (! $date) {
            return ['ok' => false, 'mode' => 'scheduled', 'at' => null, 'timezone' => $tz, 'message' => 'Publication date is required for scheduled orders.'];
        }

        $time = $time ?: '09:00';
        try {
            $at = Carbon::createFromFormat('Y-m-d H:i', $date.' '.$time, $tz)->utc();
        } catch (\Throwable) {
            return ['ok' => false, 'mode' => 'scheduled', 'at' => null, 'timezone' => $tz, 'message' => 'Invalid publication date or time.'];
        }

        if ($at->lessThanOrEqualTo(now('UTC'))) {
            return ['ok' => false, 'mode' => 'scheduled', 'at' => null, 'timezone' => $tz, 'message' => 'Publication must be scheduled in the future.'];
        }

        if ($at->greaterThan($this->maxScheduleAt())) {
            return [
                'ok' => false,
                'mode' => 'scheduled',
                'at' => null,
                'timezone' => $tz,
                'message' => 'Publication can be scheduled at most 3 months ahead.',
            ];
        }

        return ['ok' => true, 'mode' => 'scheduled', 'at' => $at, 'timezone' => $tz];
    }

    /**
     * Release due scheduled orders into the publisher queue.
     *
     * @return Collection<int, Order>
     */
    public function releaseDueOrders(): Collection
    {
        // Reminder-only: orders are already visible to publishers and charged in advance.
        $due = Order::query()
            ->with(['user', 'items.site'])
            ->where('publication_mode', 'scheduled')
            ->whereNotNull('scheduled_publish_at')
            ->where('scheduled_publish_at', '<=', now())
            ->whereNull('schedule_released_at')
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->limit(100)
            ->get();

        $released = collect();

        foreach ($due as $order) {
            try {
                $order->update([
                    'schedule_released_at' => now(),
                ]);

                $this->notifyReleased($order->fresh(['user', 'items.site']));
                try {
                    $this->inApp->notifyScheduledPublishDue($order->fresh(['user', 'items.site']), false);
                } catch (\Throwable $e) {
                    Log::warning('Schedule-due bell failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                }
                $released->push($order);
            } catch (\Throwable $e) {
                Log::error('Failed releasing scheduled order', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $released;
    }

    /**
     * Send 24h reminders for upcoming scheduled publications.
     */
    public function sendUpcomingReminders(): int
    {
        $cfg = $this->uploads->effectiveConfig();
        $hours = max(1, (int) ($cfg['scheduling']['reminder_hours_before'] ?? 24));
        $windowStart = now();
        $windowEnd = now()->addHours($hours);

        $orders = Order::query()
            ->with(['user', 'items.site'])
            ->where('publication_mode', 'scheduled')
            ->whereNull('schedule_reminder_sent_at')
            ->whereNull('schedule_released_at')
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->whereBetween('scheduled_publish_at', [$windowStart, $windowEnd])
            ->limit(100)
            ->get();

        $sent = 0;
        foreach ($orders as $order) {
            try {
                if ($order->user) {
                    $this->emails->notifyOrderLifecycle(
                        order: $order,
                        changeKind: 'status',
                        previousValue: 'scheduled',
                        newValue: 'scheduled',
                        description: 'Reminder: your scheduled publication begins within 24 hours.',
                    );
                }
                try {
                    $this->inApp->notifyScheduledPublishDue($order, true);
                } catch (\Throwable $e) {
                    Log::warning('Schedule-reminder bell failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                }
                $order->update(['schedule_reminder_sent_at' => now()]);
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('Scheduled reminder failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            }
        }

        return $sent;
    }

    public function publishImmediately(Order $order): void
    {
        if (($order->publication_mode ?? '') !== 'scheduled') {
            return;
        }

        $order->update([
            'publication_mode' => 'immediate',
            'scheduled_publish_at' => null,
            'schedule_released_at' => now(),
        ]);
    }

    public function cancelSchedule(Order $order): void
    {
        if (($order->publication_mode ?? '') !== 'scheduled') {
            return;
        }

        $order->update([
            'status' => 'cancelled',
            'schedule_released_at' => null,
        ]);
    }

    public function reschedule(Order $order, Carbon $atUtc, string $timezone): void
    {
        if (($order->publication_mode ?? '') !== 'scheduled') {
            throw new \RuntimeException('Only scheduled orders can be rescheduled.');
        }

        if ($atUtc->lessThanOrEqualTo(now('UTC')) || $atUtc->greaterThan($this->maxScheduleAt())) {
            throw new \InvalidArgumentException('Publication date must be in the future and within 3 months.');
        }

        $order->update([
            'scheduled_publish_at' => $atUtc,
            'schedule_timezone' => $timezone,
            'schedule_reminder_sent_at' => null,
        ]);
    }

    protected function notifyReleased(Order $order): void
    {
        try {
            $this->emails->notifyOrderLifecycle(
                order: $order,
                changeKind: 'status',
                previousValue: 'scheduled',
                newValue: (string) $order->status,
                description: 'Scheduled publication date has arrived. Please publish the article today.',
            );
        } catch (\Throwable $e) {
            Log::warning('Schedule-date reminder email failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }
    }
}
