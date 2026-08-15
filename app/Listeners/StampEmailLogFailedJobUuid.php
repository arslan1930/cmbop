<?php

namespace App\Listeners;

use App\Models\EmailLog;
use App\Support\MailJobPayload;
use Illuminate\Queue\Events\JobFailed;

class StampEmailLogFailedJobUuid
{
    public function handle(JobFailed $event): void
    {
        try {
            $this->stamp($event);
        } catch (\Throwable) {
            // Never break Laravel's fail pipeline (failed_jobs already written).
        }
    }

    protected function stamp(JobFailed $event): void
    {
        $job = $event->job;
        if (! is_object($job) || ! method_exists($job, 'uuid') || ! method_exists($job, 'getRawBody')) {
            return;
        }

        $uuid = $job->uuid();
        $payload = (string) $job->getRawBody();

        if (! is_string($uuid) || $uuid === '' || ! MailJobPayload::isQueuedMailable($payload)) {
            return;
        }

        $emails = MailJobPayload::emails($payload);
        $dedupe = MailJobPayload::dedupeKey($payload);
        $hours = max(
            1,
            (int) config('email_notifications.max_age_hours', 24),
            (int) config('email_notifications.campaign_max_age_hours', 72)
        );
        $since = now()->subHours($hours);

        $matches = EmailLog::query()
            ->whereIn('status', [EmailLog::STATUS_FAILED, EmailLog::STATUS_PENDING])
            ->when(
                $dedupe,
                fn ($q) => $q->where('dedupe_key', $dedupe),
                fn ($q) => $q->where('updated_at', '>=', $since)
                    ->when($emails !== [], fn ($emailQuery) => $emailQuery->whereIn('to_email', $emails))
            )
            ->latest('id')
            ->limit(50)
            ->get()
            ->filter(fn (EmailLog $log) => MailJobPayload::matchesEmailLog($payload, $log, requireToken: true));

        if ($matches->count() !== 1) {
            return;
        }

        $log = $matches->first();
        $meta = (array) $log->meta;
        if (($meta['failed_job_uuid'] ?? null) === $uuid) {
            return;
        }

        $meta['failed_job_uuid'] = $uuid;
        $log->update(['meta' => $meta]);
    }
}
