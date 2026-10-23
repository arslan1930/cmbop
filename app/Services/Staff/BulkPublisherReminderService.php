<?php

namespace App\Services\Staff;

use App\Mail\BulkSiteRequestPublisherReminder;
use App\Models\ActivityLog;
use App\Models\BulkSiteRequest;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\EmailNotificationService;
use App\Services\InAppNotificationService;
use App\Support\MarketingOpsQueues;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class BulkPublisherReminderService
{
    public const ACTION = 'bulk_request.publisher_reminded';

    public function __construct(
        private EmailNotificationService $mailer,
        private InAppNotificationService $notifications,
    ) {}

    public function autoEnabled(): bool
    {
        return (bool) config('bulk_site_requests.publisher_reminder.auto_enabled', false);
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, Carbon>
     */
    public function lastRemindedMap(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id) => $id > 0)));
        if ($ids === [] || ! Schema::hasTable((new ActivityLog)->getTable())) {
            return [];
        }

        try {
            $rows = ActivityLog::query()
                ->where('action', self::ACTION)
                ->where(function ($q) use ($ids) {
                    $q->where(function ($inner) use ($ids) {
                        $inner->where('subject_type', BulkSiteRequest::class)
                            ->whereIn('subject_id', $ids);
                    })->orWhereIn('properties->bulk_site_request_id', $ids);
                })
                ->orderByDesc('id')
                ->get(['subject_id', 'properties', 'created_at']);
        } catch (\Throwable $e) {
            Log::notice('Bulk reminder history lookup skipped', ['error' => $e->getMessage()]);

            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $id = (int) ($row->subject_id ?: 0);
            if ($id < 1) {
                $id = (int) (data_get($row->properties, 'bulk_site_request_id') ?: 0);
            }
            if ($id < 1 || isset($out[$id]) || ! $row->created_at) {
                continue;
            }
            $out[$id] = $row->created_at instanceof Carbon
                ? $row->created_at
                : Carbon::parse($row->created_at);
        }

        return $out;
    }

    public function lastRemindedAt(BulkSiteRequest $bulk): ?Carbon
    {
        return $this->lastRemindedMap([(int) $bulk->id])[(int) $bulk->id] ?? null;
    }

    /**
     * @return array{sent: int, skipped: int, errors: int}
     */
    public function remindMany(Collection $requests, ?User $actor = null, bool $automatic = false): array
    {
        $sent = 0;
        $skipped = 0;
        $errors = 0;
        foreach ($requests as $bulk) {
            if (! $bulk instanceof BulkSiteRequest) {
                $skipped++;

                continue;
            }
            $result = $this->remind($bulk, $actor, $automatic);
            if ($result === 'sent') {
                $sent++;
            } elseif ($result === 'error') {
                $errors++;
            } else {
                $skipped++;
            }
        }

        return compact('sent', 'skipped', 'errors');
    }

    /**
     * @return 'sent'|'skipped'|'error'
     */
    public function remind(BulkSiteRequest $bulk, ?User $actor = null, bool $automatic = false): string
    {
        $bulk->loadMissing('publisher');
        if (! $bulk->canRemindPublisher()) {
            return 'skipped';
        }

        $publisher = $bulk->publisher;
        if (! $publisher?->email) {
            return 'skipped';
        }

        $queued = false;
        try {
            $mailable = new BulkSiteRequestPublisherReminder($bulk, $publisher, $automatic);
            $mailable->skipUserPreference = ! $automatic;
            $queued = $this->mailer->sendReminder($publisher, $mailable);
        } catch (\Throwable $e) {
            Log::warning('Bulk publisher reminder email failed', [
                'bulk_site_request_id' => $bulk->id,
                'error' => $e->getMessage(),
            ]);

            return 'error';
        }

        if (! $queued) {
            return 'skipped';
        }

        try {
            $this->notifications->notifyPublisherBulkRequestReminder($bulk);
        } catch (\Throwable $e) {
            Log::warning('Bulk publisher reminder in-app failed', [
                'bulk_site_request_id' => $bulk->id,
                'error' => $e->getMessage(),
            ]);
        }

        $actorName = $automatic
            ? 'Scheduler'
            : ($actor?->name ?? 'Staff');

        ActivityLogger::tryLog(
            self::ACTION,
            $actorName.' reminded the publisher on bulk request #'.$bulk->id,
            $bulk,
            [
                'bulk_site_request_id' => $bulk->id,
                'publisher_id' => $bulk->publisher_id,
                'automatic' => $automatic,
            ],
            'Bulk request #'.$bulk->id
        );

        return 'sent';
    }

    /**
     * Waiting-on-publisher batches old enough, past the cooldown, for auto send.
     *
     * @return Collection<int, BulkSiteRequest>
     */
    public function eligibleForAutomatic(): Collection
    {
        $minAge = max(1, (int) config('bulk_site_requests.publisher_reminder.min_age_days', 3));
        $cooldown = max(1, (int) config('bulk_site_requests.publisher_reminder.cooldown_days', 7));
        $cutoff = now()->subDays($minAge);
        $cooldownAfter = now()->subDays($cooldown);

        $candidates = MarketingOpsQueues::bulkWaitingOnPublisher()
            ->with('publisher')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit(100)
            ->get();

        if ($candidates->isEmpty()) {
            return $candidates;
        }

        $last = $this->lastRemindedMap($candidates->modelKeys());

        return $candidates->filter(function (BulkSiteRequest $bulk) use ($last, $cooldownAfter) {
            if (! $bulk->canRemindPublisher() || ! $bulk->publisher?->email) {
                return false;
            }
            $prev = $last[(int) $bulk->id] ?? null;

            return $prev === null || $prev->lt($cooldownAfter);
        })->values();
    }
}
