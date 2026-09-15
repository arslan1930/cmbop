<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendEmailCampaignJob;
use App\Mail\AudienceCampaignMail;
use App\Mail\DepositReminderMail;
use App\Mail\PlatformMailable;
use App\Mail\PublisherAddSiteReminderMail;
use App\Mail\WelcomeEmail;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailLog;
use App\Models\EmailNotificationSetting;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Admin\OpsHealthService;
use App\Support\EmailCatalog;
use App\Support\MailJobPayload;
use App\Support\UserFacingError;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EmailCenterController extends Controller
{
    /** @var array<string, bool> */
    private array $schemaTableAvailable = [];

    public function index(Request $request)
    {
        $stats = EmailLog::dashboardKpis();
        $filters = $this->recentLogFilters($request);
        $recentLogs = $this->recentEmailLogs($filters);
        $templateStats = $this->emailTemplateStats();
        $settingRows = $this->emailNotificationSettingRows();
        $preferenceLabels = collect(config('email_notifications.preference_keys', []))
            ->map(fn (array $meta) => $meta['label'] ?? null);
        $settings = collect(config('email_notifications.types', []))->map(function (array $meta, string $type) use ($settingRows, $preferenceLabels) {
            $default = (bool) ($meta['default_enabled'] ?? true);
            $preference = $meta['preference'] ?? null;

            return [
                'type' => $type,
                'name' => $meta['name'] ?? $type,
                'audience' => $meta['audience'] ?? 'user',
                'enabled' => $settingRows->has($type) ? (bool) $settingRows->get($type) : $default,
                'preference' => $preference,
                'preference_label' => $preference ? ($preferenceLabels[$preference] ?? $preference) : null,
                'framework' => (bool) ($meta['framework'] ?? false),
            ];
        })->values();

        $enabledByType = $settings->pluck('enabled', 'type');

        $templates = collect(EmailCatalog::templates())->map(function (array $meta) use ($templateStats, $enabledByType) {
            $row = $templateStats->get($meta['key']);
            $meta['last_sent_at'] = $this->parseLastSentAt($row?->last_sent_at);
            $meta['sent_count'] = (int) ($row?->sent_count ?? 0);
            $meta['enabled'] = (bool) ($enabledByType[$meta['key']] ?? true);

            return $meta;
        })->values();

        $categoryOrder = ['Users', 'Auth', 'Orders', 'Billing', 'Publishers', 'Advertisers', 'Admin', 'Growth', 'Reports', 'Other'];
        $templatesByCategory = $templates->groupBy(fn (array $tpl) => $tpl['category'] ?: 'Other')
            ->sortBy(fn ($group, $category) => array_search($category, $categoryOrder, true) !== false
                ? array_search($category, $categoryOrder, true)
                : 99);

        $smtp = [
            'mailer' => config('mail.default'),
            'host' => config('mail.mailers.smtp.host'),
            'port' => config('mail.mailers.smtp.port'),
            'username' => config('mail.mailers.smtp.username'),
            'encryption' => config('mail.mailers.smtp.scheme') ?: (config('mail.mailers.smtp.port') == 465 ? 'ssl' : 'tls'),
            'from_address' => config('mail.from.address'),
            'from_name' => config('mail.from.name'),
            'admin_email' => config('mail.admin_email'),
            'queue_connection' => config('queue.default'),
            'configured' => config('mail.default') !== 'log' && filled(config('mail.mailers.smtp.host')),
        ];

        $queue = app(OpsHealthService::class)->snapshot();

        $failedLogs = $this->failedEmailLogs();
        $recentCampaigns = $this->recentCampaigns();

        $brand = config('email_notifications.brand', []);
        $criticalTypes = ['welcome', 'order_status_changed', 'publisher_new_order', 'deposit_approved', 'admin_stalled_order'];
        $logFilters = $filters;

        return view('admin.emails.index', compact(
            'stats',
            'recentLogs',
            'templates',
            'templatesByCategory',
            'smtp',
            'queue',
            'failedLogs',
            'settings',
            'brand',
            'recentCampaigns',
            'criticalTypes',
            'logFilters'
        ));
    }

    public function updateSettings(Request $request)
    {
        if (! $this->schemaTableAvailable('email_notification_settings')) {
            return back()->with('error', 'Notification settings are unavailable because the destination table is missing.');
        }

        $types = config('email_notifications.types', []);
        $editable = collect($types)
            ->reject(fn (array $meta) => ! empty($meta['framework']))
            ->keys()
            ->all();

        $rules = ['enabled' => ['required', 'array']];
        foreach ($editable as $type) {
            $rules['enabled.'.$type] = ['required', Rule::in(['0', '1'])];
        }

        $data = $request->validate($rules);

        $before = [];
        foreach ($editable as $type) {
            $before[$type] = EmailNotificationSetting::isEnabled($type);
        }

        DB::transaction(function () use ($editable, $data) {
            foreach ($editable as $type) {
                EmailNotificationSetting::updateOrCreate(
                    ['type' => $type],
                    ['enabled' => (string) $data['enabled'][$type] === '1']
                );
            }
        });

        EmailNotificationSetting::flushCache();

        $changed = [];
        foreach ($editable as $type) {
            $to = (string) $data['enabled'][$type] === '1';
            if ($before[$type] !== $to) {
                $changed[] = [
                    'type' => $type,
                    'from' => $before[$type],
                    'to' => $to,
                ];
            }
        }

        if ($changed !== []) {
            ActivityLogger::tryLog(
                'email.settings_updated',
                ($request->user()?->name ?? 'Admin').' updated email notification settings',
                null,
                ['changed' => $changed]
            );
        }

        return back()->with('success', 'Email notification settings saved.');
    }

    public function preview(Request $request, string $key)
    {
        $template = EmailCatalog::get($key);
        abort_unless($template, 404);

        if ($html = $this->frameworkPreviewHtml($key)) {
            return response($html);
        }

        $audience = $request->query('audience');
        $mailable = EmailCatalog::makeMailable($key, array_filter([
            'audience' => is_string($audience) ? $audience : null,
        ]));
        abort_unless($mailable, 404);

        return response($mailable->render());
    }

    public function showLog(EmailLog $emailLog)
    {
        $relatedUser = User::query()->where('email', $emailLog->to_email)->first();

        return view('admin.emails.log', [
            'log' => $emailLog,
            'relatedUser' => $relatedUser,
        ]);
    }

    public function sendTest(Request $request)
    {
        $adminEmail = (string) $request->user()->email;
        $data = $request->validate([
            'template' => ['required', 'string', Rule::in(array_keys(EmailCatalog::templates()))],
            'email' => ['required', 'email', Rule::in([$adminEmail])],
        ]);

        $key = $data['template'];
        $template = EmailCatalog::get($key);
        abort_unless($template, 404);

        $dedupe = 'email_center_test:'.$key.':'.(string) Str::uuid();
        $mailable = null;
        if (! $this->frameworkPreviewHtml($key)) {
            $mailable = EmailCatalog::makeMailable($key);
            abort_unless($mailable, 404);
        }

        try {
            if ($mailable) {
                if ($mailable instanceof PlatformMailable) {
                    $mailable->forceSend = true;
                    $mailable->skipUserPreference = true;
                    $mailable->dedupeKey = $dedupe;
                }
                Mail::to($adminEmail)->sendNow($mailable);
            } else {
                $this->sendFrameworkTestHtml($key, $adminEmail, $dedupe);
            }

            ActivityLogger::tryLog(
                'email_center.test_sent',
                (auth()->user()?->name ?? 'Admin').' sent a test email ('.$key.') to '.$adminEmail,
                null,
                ['template' => $key, 'email' => $adminEmail]
            );

            return back()->with(
                'success',
                'Test email sent to '.$adminEmail.' (synthetic preview — ignores global disable).'
            );
        } catch (\Throwable $e) {
            $this->recordTestSendFailure($template, $key, $adminEmail, $dedupe, $e);

            return back()->with('error', UserFacingError::message($e, 'Failed to send test email. Please try again.'));
        }
    }

    /**
     * @param  array<string, mixed>  $template
     */
    protected function recordTestSendFailure(array $template, string $key, string $adminEmail, string $dedupe, \Throwable $e): void
    {
        if (! $this->schemaTableAvailable('email_logs')) {
            return;
        }

        $payload = [
            'mailable' => $template['mailable'] ?? null,
            'template_key' => $key,
            'notification_type' => $key,
            'dedupe_key' => $dedupe,
            'to_email' => $adminEmail,
            'subject' => ($template['name'] ?? $key).' (Test)',
            'status' => EmailLog::STATUS_FAILED,
            'error' => $e->getMessage(),
            'meta' => ['source' => 'email_center_test'],
        ];

        try {
            $open = EmailLog::openByDedupe($dedupe);
            if ($open->isNotEmpty()) {
                foreach ($open as $existing) {
                    $existing->fill($payload);
                    $existing->attempts = max(1, (int) $existing->attempts) + 1;
                    $existing->save();
                }

                return;
            }

            EmailLog::create(array_merge($payload, [
                'uuid' => (string) Str::uuid(),
                'attempts' => 1,
            ]));
        } catch (\Throwable) {
            // Leftover Hostinger: missing email_logs must not 500 the test-send flash.
        }
    }

    public function retryFailed(Request $request)
    {
        if (! $this->schemaTableAvailable('email_logs')) {
            return back()->with('error', 'Email logs are unavailable because the destination table is missing.');
        }

        $data = $request->validate([
            'log_id' => ['nullable', 'integer', 'exists:email_logs,id'],
        ]);

        if (! empty($data['log_id'])) {
            return $this->retryFailedLog((int) $data['log_id']);
        }

        $uuids = $this->mailFailedJobUuids();

        if ($uuids === []) {
            return back()->with('success', 'No failed mail jobs to retry.');
        }

        $payloads = $this->failedJobPayloadsByUuid($uuids);
        [$closed, $closedLeftovers] = $this->closeFailedLogsAlreadyDelivered();
        $uuids = array_values(array_filter(
            $uuids,
            fn (string $uuid) => ! $this->shouldSkipRetryForClosedLeftover(
                $uuid,
                (string) ($payloads[$uuid] ?? ''),
                $closedLeftovers
            ) && ! $this->payloadAlreadyDelivered((string) ($payloads[$uuid] ?? ''))
        ));

        if ($uuids === []) {
            return back()->with(
                'success',
                $closed > 0
                    ? 'Closed '.$closed.' leftover failed log(s) that already delivered. No jobs were re-queued.'
                    : 'No failed mail jobs to retry.'
            );
        }

        try {
            foreach ($uuids as $uuid) {
                $this->refreshFailedJobQueuedAt($uuid);
                $fresh = DB::table('failed_jobs')->where('uuid', $uuid)->value('payload');
                if (is_string($fresh) && $fresh !== '') {
                    $payloads[$uuid] = $fresh;
                }
            }
            Artisan::call('queue:retry', ['id' => $uuids]);
        } catch (\Throwable $e) {
            return back()->with('error', UserFacingError::message($e, 'Could not retry mail jobs. Please try again.'));
        }

        if ($this->queueRetryMissedEveryJob(Artisan::output())) {
            return back()->with('error', 'Could not retry mail jobs. Please try again.');
        }

        $retried = $this->actuallyRetriedJobUuids($uuids);
        $this->markRetriedMailLogsPending($retried, $payloads);

        if ($retried === []) {
            return back()->with('success', 'No failed mail jobs were re-queued.');
        }

        ActivityLogger::tryLog(
            'email.retried',
            ($request->user()?->name ?? 'Admin').' retried '.count($retried).' failed mail job(s)',
            null,
            ['count' => count($retried)]
        );

        return back()->with('success', 'Retried '.count($retried).' failed mail job(s). Other failed jobs were left untouched.');
    }

    protected function retryFailedLog(int $logId)
    {
        $log = EmailLog::query()->findOrFail($logId);
        if ($log->status !== EmailLog::STATUS_FAILED) {
            return back()->with('error', 'That email log is not failed.');
        }

        if ($this->closeFailedLogAlreadyDelivered($log)) {
            return back()->with('success', 'This send already delivered — closed the leftover failed log.');
        }

        if ($this->shouldRebuildAsTest($log)) {
            return $this->retryTestLog($log);
        }

        $uuid = $this->failedJobUuidForLog($log);
        if ($uuid) {
            try {
                $this->refreshFailedJobQueuedAt($uuid);
                Artisan::call('queue:retry', ['id' => [$uuid]]);
            } catch (\Throwable $e) {
                return back()->with('error', UserFacingError::message($e, 'Could not retry the mail job. Please try again.'));
            }

            if ($this->queueRetryMissedJob(Artisan::output())
                || $this->actuallyRetriedJobUuids([$uuid]) === []) {
                // Only rebuild when the identified job is gone. A no-op
                // queue:retry that left the row in failed_jobs must not
                // also dispatch a second send.
                if (! $this->failedJobStillQueued($uuid)) {
                    $rebuilt = $this->retryRebuildableProductionLogWithoutJob($log);
                    if ($rebuilt !== null) {
                        return $rebuilt;
                    }
                }

                return back()->with('error', 'Cannot rebuild production payload — retry the queue job.');
            }

            $log->update([
                'status' => EmailLog::STATUS_PENDING,
                'error' => null,
                'attempts' => max(1, (int) $log->attempts) + 1,
            ]);
            $this->requeueFailedCampaignRecipient($log);

            ActivityLogger::tryLog(
                'email.retried',
                (auth()->user()?->name ?? 'Admin').' retried failed mail log #'.$log->id,
                null,
                ['count' => 1, 'log_id' => $log->id, 'template' => $log->template_key]
            );

            return back()->with('success', 'Re-queued the failed mail job for this log.');
        }

        $rebuilt = $this->retryRebuildableProductionLogWithoutJob($log);
        if ($rebuilt !== null) {
            return $rebuilt;
        }

        return back()->with('error', 'Cannot rebuild production payload — retry the queue job.');
    }

    /**
     * Rebuild a production send from stored campaign + user, or from the
     * real recipient for user-only templates. Catalog sample users / sample
     * orders are never used — that would send the wrong letter.
     */
    protected function retryRebuildableProductionLogWithoutJob(EmailLog $log): mixed
    {
        $campaign = $this->retryCampaignLogWithoutJob($log);
        if ($campaign !== null) {
            return $campaign;
        }

        return $this->retryUserOnlyProductionLogWithoutJob($log);
    }

    /**
     * Campaign mail can be rebuilt from the stored campaign + user.
     */
    protected function retryCampaignLogWithoutJob(EmailLog $log): mixed
    {
        $campaignId = $this->campaignIdFromLog($log);
        $userId = $this->userIdFromLog($log);
        if ($campaignId < 1 || $userId < 1) {
            return null;
        }

        $isCampaign = $log->mailable === AudienceCampaignMail::class
            || $log->template_key === 'audience_campaign'
            || str_starts_with((string) $log->dedupe_key, 'audience_campaign:');
        if (! $isCampaign) {
            return null;
        }

        $campaign = EmailCampaign::query()->find($campaignId);
        $user = User::query()->find($userId);
        if (! $campaign || ! $user) {
            return back()->with('error', 'That campaign recipient is no longer available.');
        }

        try {
            if (Schema::hasTable((new EmailCampaignRecipient)->getTable())) {
                EmailCampaignRecipient::query()
                    ->where('email_campaign_id', $campaignId)
                    ->where('user_id', $userId)
                    ->where(function ($query) {
                        $query->where('status', EmailCampaignRecipient::STATUS_FAILED)
                            ->orWhere(function ($skipped) {
                                $skipped->where('status', EmailCampaignRecipient::STATUS_SKIPPED)
                                    ->where('skip_reason', EmailCampaignRecipient::SKIP_STALE);
                            });
                    })
                    ->update([
                        'status' => EmailCampaignRecipient::STATUS_PENDING,
                        'skip_reason' => null,
                        'email_log_id' => null,
                    ]);
            }

            if ($campaign->status === EmailCampaign::STATUS_FAILED) {
                $campaign->clearFailStreak();
                $campaign->update([
                    'status' => EmailCampaign::STATUS_SENDING,
                    'sent_at' => null,
                ]);
            } else {
                $campaign->touch();
            }
            $campaign->recountRecipientTotals();

            $log->update([
                'status' => EmailLog::STATUS_PENDING,
                'error' => null,
                'attempts' => max(1, (int) $log->attempts) + 1,
            ]);

            SendEmailCampaignJob::dispatch($campaign->id);
        } catch (\Throwable $e) {
            return back()->with('error', UserFacingError::message($e, 'Could not retry this campaign email. Please try again.'));
        }

        ActivityLogger::tryLog(
            'email.retried',
            (auth()->user()?->name ?? 'Admin').' rebuilt campaign mail log #'.$log->id,
            null,
            ['count' => 1, 'log_id' => $log->id, 'campaign_id' => $campaign->id]
        );

        return back()->with('success', 'Re-queued this campaign email.');
    }

    /**
     * Welcome / add-site / deposit reminders only need the real User.
     * Order, invoice, password, and Google-temp-password mail stay refused
     * when the queue job is gone — those need tokens or live IDs we do not store.
     */
    protected function retryUserOnlyProductionLogWithoutJob(EmailLog $log): mixed
    {
        $user = $this->recipientUserForProductionRebuild($log);
        if (! $user) {
            return null;
        }

        $mailable = $this->rebuildUserOnlyProductionMailable($log, $user);
        if (! $mailable instanceof PlatformMailable) {
            return null;
        }

        $dedupe = trim((string) $log->dedupe_key);
        if ($dedupe === '') {
            $dedupe = (string) ($mailable->dedupeKey ?: implode('|', array_filter([
                (string) ($log->template_key ?: 'mail'),
                (string) $user->email,
                class_basename($mailable::class),
            ])));
            $log->update(['dedupe_key' => $dedupe]);
        }
        $mailable->dedupeKey = $dedupe;
        $mailable->skipUserPreference = true;
        $mailable->queuedAt = now()->toIso8601String();

        try {
            Mail::to($user->email)->sendNow($mailable);
        } catch (\Throwable $e) {
            return back()->with('error', UserFacingError::message($e, 'Could not retry this email. Please try again.'));
        }

        $fresh = $log->fresh();
        if ($fresh && $fresh->status === EmailLog::STATUS_DELIVERED) {
            ActivityLogger::tryLog(
                'email.retried',
                (auth()->user()?->name ?? 'Admin').' rebuilt production mail log #'.$log->id,
                null,
                ['count' => 1, 'log_id' => $log->id, 'template' => $log->template_key]
            );

            return back()->with('success', 'Re-sent this email to '.$user->email.'.');
        }

        if (is_string($mailable->suppressReason) && $mailable->suppressReason !== '') {
            return back()->with('error', 'Could not resend this email ('.$mailable->suppressReason.').');
        }

        return back()->with('error', 'Could not resend this email. Please try again.');
    }

    protected function recipientUserForProductionRebuild(EmailLog $log): ?User
    {
        $userId = $this->userIdFromLog($log);
        if ($userId > 0) {
            $user = User::query()->find($userId);
            if ($user) {
                return $user;
            }
        }

        $email = trim((string) $log->to_email);
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return User::query()->where('email', $email)->first();
    }

    protected function rebuildUserOnlyProductionMailable(EmailLog $log, User $user): ?PlatformMailable
    {
        $key = (string) $log->template_key;
        $class = (string) $log->mailable;

        if ($key === 'welcome' || $class === WelcomeEmail::class) {
            return new WelcomeEmail($user);
        }

        if ($key === 'publisher_add_site_reminder' || $class === PublisherAddSiteReminderMail::class) {
            $step = $this->reminderStepFromLog(
                $log,
                [PublisherAddSiteReminderMail::STEP_DAY3, PublisherAddSiteReminderMail::STEP_DAY7],
                PublisherAddSiteReminderMail::STEP_DAY3
            );

            return new PublisherAddSiteReminderMail($user, $step);
        }

        if ($key === 'deposit_reminder' || $class === DepositReminderMail::class) {
            $step = $this->reminderStepFromLog(
                $log,
                [DepositReminderMail::STEP_DAY7, DepositReminderMail::STEP_DAY14],
                DepositReminderMail::STEP_DAY14
            );

            return new DepositReminderMail($user, $step);
        }

        return null;
    }

    /**
     * @param  list<string>  $allowed
     */
    protected function reminderStepFromLog(EmailLog $log, array $allowed, string $default): string
    {
        $haystack = strtolower(implode(' ', array_filter([
            (string) $log->dedupe_key,
            (string) $log->subject,
            (string) data_get($log->meta, 'step'),
        ])));

        foreach ($allowed as $step) {
            if (str_contains($haystack, strtolower($step))) {
                return $step;
            }
        }

        return $default;
    }

    protected function retryTestLog(EmailLog $log)
    {
        $key = (string) $log->template_key;
        $template = EmailCatalog::get($key);
        if (! $template) {
            return back()->with('error', 'Cannot rebuild production payload — retry the queue job.');
        }

        $adminEmail = (string) request()->user()->email;
        $dedupe = 'email_center_test:'.$key.':retry:'.$log->id;
        $log->update(['dedupe_key' => $dedupe]);

        try {
            if ($this->frameworkPreviewHtml($key)) {
                $this->sendFrameworkTestHtml($key, $adminEmail, $dedupe);
            } else {
                $mailable = EmailCatalog::makeMailable($key);
                abort_unless($mailable, 404);
                if ($mailable instanceof PlatformMailable) {
                    $mailable->forceSend = true;
                    $mailable->skipUserPreference = true;
                    $mailable->dedupeKey = $dedupe;
                }
                Mail::to($adminEmail)->sendNow($mailable);
            }

            ActivityLogger::tryLog(
                'email_center.test_sent',
                (auth()->user()?->name ?? 'Admin').' retried a test email ('.$key.') to '.$adminEmail,
                null,
                ['template' => $key, 'email' => $adminEmail, 'retry' => true, 'log_id' => $log->id]
            );

            return back()->with('success', 'Retried the Email Center test send to '.$adminEmail.'.');
        } catch (\Throwable $e) {
            $log->update([
                'status' => EmailLog::STATUS_FAILED,
                'error' => $e->getMessage(),
                'attempts' => max(1, (int) $log->attempts) + 1,
            ]);

            return back()->with('error', UserFacingError::message($e, 'Failed to retry the test email. Please try again.'));
        }
    }

    /**
     * queue:retry prints "Pushing..." whenever the ID list is non-empty, even
     * if every UUID is already gone. Only treat jobs that left failed_jobs
     * as actually requeued.
     *
     * @param  list<string>  $uuids
     * @return list<string>
     */
    protected function failedJobStillQueued(string $uuid): bool
    {
        if ($uuid === '' || ! Schema::hasTable('failed_jobs')) {
            return false;
        }

        return DB::table('failed_jobs')->where('uuid', $uuid)->exists();
    }

    protected function actuallyRetriedJobUuids(array $uuids): array
    {
        if ($uuids === [] || ! Schema::hasTable('failed_jobs')) {
            return [];
        }

        $stillFailed = DB::table('failed_jobs')
            ->whereIn('uuid', $uuids)
            ->pluck('uuid')
            ->map(fn ($uuid) => (string) $uuid)
            ->all();

        return array_values(array_diff($uuids, $stillFailed));
    }

    /**
     * @param  list<string>  $uuids
     * @return array<string, string>
     */
    protected function failedJobPayloadsByUuid(array $uuids): array
    {
        if ($uuids === [] || ! Schema::hasTable('failed_jobs')) {
            return [];
        }

        return DB::table('failed_jobs')
            ->whereIn('uuid', $uuids)
            ->pluck('payload', 'uuid')
            ->map(fn ($payload) => (string) $payload)
            ->all();
    }

    /**
     * @param  list<string>  $uuids
     * @param  array<string, string>  $payloadsByUuid
     */
    protected function markRetriedMailLogsPending(array $uuids, array $payloadsByUuid = []): void
    {
        if ($uuids === []) {
            return;
        }

        $failed = EmailLog::query()
            ->where('status', EmailLog::STATUS_FAILED)
            ->orderByDesc('id')
            ->get();
        $marked = [];
        $claimedUuids = [];

        foreach ($failed as $log) {
            $stored = (string) data_get($log->meta, 'failed_job_uuid');
            if ($stored === '' || ! in_array($stored, $uuids, true) || ! empty($claimedUuids[$stored])) {
                continue;
            }

            // A stale stamp from an unidentified Welcome job must not
            // pending-mark a different recipient. Same token rule as search.
            $payload = (string) ($payloadsByUuid[$stored] ?? '');
            if ($payload === '' || ! MailJobPayload::matchesEmailLog($payload, $log, requireToken: true)) {
                continue;
            }

            if ($this->closeFailedLogAlreadyDelivered($log)) {
                $marked[$log->id] = true;
                $claimedUuids[$stored] = true;

                continue;
            }

            $this->pendingMarkRetriedLog($log);
            $marked[$log->id] = true;
            $claimedUuids[$stored] = true;
        }

        foreach ($uuids as $uuid) {
            if (! empty($claimedUuids[$uuid])) {
                continue;
            }

            $payload = (string) ($payloadsByUuid[$uuid] ?? '');
            if ($payload === '') {
                continue;
            }

            $matches = $failed->filter(
                fn (EmailLog $log) => empty($marked[$log->id])
                    && MailJobPayload::matchesEmailLog($payload, $log, requireToken: true)
            );
            if ($matches->count() !== 1) {
                continue;
            }

            $log = $matches->first();
            if ($this->closeFailedLogAlreadyDelivered($log)) {
                $marked[$log->id] = true;
                $claimedUuids[$uuid] = true;

                continue;
            }

            $this->pendingMarkRetriedLog($log);
            $marked[$log->id] = true;
            $claimedUuids[$uuid] = true;
        }
    }

    /**
     * Leftover failed rows after a real delivery. Retrying the queue job
     * would send a second campaign / welcome once the 10-minute window lapses.
     * Also return the leftover logs so an unidentified campaign jobs row
     * (no extractable dedupeKey) can still be dropped when that leftover
     * owns it. A stale failed_job_uuid stamp must not also suppress a
     * Welcome job that actually owns the UUID.
     *
     * @return array{0: int, 1: list<EmailLog>}
     */
    protected function closeFailedLogsAlreadyDelivered(): array
    {
        $closed = 0;
        $leftovers = [];

        foreach (EmailLog::query()
            ->where('status', EmailLog::STATUS_FAILED)
            ->whereNotNull('dedupe_key')
            ->where('dedupe_key', '!=', '')
            ->orderByDesc('id')
            ->get() as $log) {
            if ($this->closeFailedLogAlreadyDelivered($log)) {
                $closed++;
                $leftovers[] = $log;
            }
        }

        return [$closed, $leftovers];
    }

    /**
     * Drop a leftover campaign job even when the payload has no extractable
     * dedupeKey. A stale failed_job_uuid stamp on that leftover must not
     * also suppress a Welcome (or other-campaign) job that actually owns
     * the UUID. Already-closed leftovers (previous retry) use the same
     * ownership gate so a later bulk click does not re-queue that send.
     *
     * @param  list<EmailLog>  $leftovers
     */
    protected function shouldSkipRetryForClosedLeftover(string $uuid, string $payload, array $leftovers): bool
    {
        foreach ($leftovers as $leftover) {
            $stored = (string) data_get($leftover->meta, 'failed_job_uuid');
            if ($stored === $uuid) {
                return $this->leftoverOwnsFailedJob($leftover, $payload);
            }

            if ($stored === '' && $this->leftoverOwnsFailedJob($leftover, $payload)) {
                return true;
            }
        }

        if ($uuid === '') {
            return false;
        }

        foreach ($this->alreadyClosedLeftoversForFailedJob($uuid) as $leftover) {
            if ($this->leftoverOwnsFailedJob($leftover, $payload)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<EmailLog>
     */
    protected function alreadyClosedLeftoversForFailedJob(string $uuid): array
    {
        return EmailLog::query()
            ->where('status', EmailLog::STATUS_DELIVERED)
            ->where('meta', 'like', '%'.$uuid.'%')
            ->get()
            ->filter(function (EmailLog $log) use ($uuid) {
                return (string) data_get($log->meta, 'failed_job_uuid') === $uuid
                    && $this->isOneShotCampaignLog($log);
            })
            ->values()
            ->all();
    }

    protected function leftoverOwnsFailedJob(EmailLog $leftover, string $payload): bool
    {
        if ($payload === '') {
            return true;
        }

        [$campaignId, $userId] = EmailLog::campaignUserIds($leftover);
        $dedupe = MailJobPayload::dedupeKey($payload);
        $leftoverDedupe = (string) $leftover->dedupe_key;
        $keys = MailJobPayload::campaignDedupeKeys($payload);

        if ($campaignId > 0 && $userId > 0) {
            if (in_array($userId, MailJobPayload::campaignMailUserIds($payload, $campaignId), true)) {
                return true;
            }
            $canonical = EmailCampaignRecipient::dedupeKey($campaignId, $userId);
            if (is_string($dedupe) && $dedupe === $canonical) {
                return true;
            }
            if ($canonical !== '' && in_array($canonical, $keys, true)) {
                return true;
            }
        }

        // Shared `audience_campaign|{email}|…` is not one-shot across
        // campaigns. Exact-key match used to drop campaign 2's job after
        // campaign 1's leftover closed under the same string.
        if (str_starts_with($leftoverDedupe, 'audience_campaign|')
            || (is_string($dedupe) && str_starts_with($dedupe, 'audience_campaign|'))) {
            return false;
        }

        if (is_string($dedupe) && $dedupe !== '') {
            return $dedupe === $leftoverDedupe;
        }

        if ($leftoverDedupe !== '' && in_array($leftoverDedupe, $keys, true)) {
            return true;
        }

        $class = (string) ($leftover->mailable ?: '');
        if ($class !== ''
            && MailJobPayload::containsMailable($payload, $class)
            && MailJobPayload::containsToken($payload, (string) $leftover->to_email)) {
            return true;
        }

        // Leftover jobs queued before the constructor stamped a key still
        // serialize campaign+user ModelIdentifiers. Email-only matching
        // missed those and bulk retry re-queued a send that already went out.
        return $campaignId > 0
            && $userId > 0
            && in_array($userId, MailJobPayload::campaignMailUserIds($payload, $campaignId), true);
    }

    protected function closeFailedLogAlreadyDelivered(EmailLog $log): bool
    {
        if ($log->status !== EmailLog::STATUS_FAILED || ! filled($log->dedupe_key)) {
            return false;
        }

        $delivered = null;
        $dedupe = (string) $log->dedupe_key;
        // Exact-key lookup on the shared generic key hits another
        // campaign's delivery and closed this leftover as "already sent".
        if ($dedupe !== '' && ! str_starts_with($dedupe, 'audience_campaign|')) {
            $delivered = EmailLog::latestDeliveredByDedupe($dedupe);
            if ($delivered && (int) $delivered->id === (int) $log->id) {
                $delivered = null;
            }
        }
        if (! $delivered) {
            [$campaignId, $userId] = EmailLog::campaignUserIds($log);
            $delivered = EmailLog::latestDeliveredForCampaignUser($campaignId, $userId);
        }
        if (! $delivered || (int) $delivered->id === (int) $log->id) {
            return false;
        }

        if ($this->isOneShotCampaignLog($log)) {
            // Generic default keys are per email, not per campaign. A prior
            // campaign delivery to the same address must not swallow a later
            // campaign's leftover — retry would never fire.
            if (! $this->deliveredSiblingIsSameCampaignSend($log, $delivered)) {
                return false;
            }
        } elseif (! $this->isSameAttemptLeftover($log, $delivered)) {
            return false;
        }

        $log->update([
            'status' => EmailLog::STATUS_DELIVERED,
            'error' => null,
            'sent_at' => $delivered->sent_at ?? $log->sent_at ?? now(),
            'meta' => array_filter(array_merge((array) $log->meta, [
                'suppressed' => 'duplicate',
                'superseded_by' => (int) $delivered->id,
            ])),
        ]);
        $this->syncCampaignRecipientFromDeliveredLog($delivered);

        return true;
    }

    /**
     * Canonical `audience_campaign:{id}:user:{id}` already names one send.
     * The generic default key (`audience_campaign|{email}|…`) is reused
     * across campaigns, so the delivered sibling must be the same
     * campaign (and user when both rows have one).
     */
    protected function deliveredSiblingIsSameCampaignSend(EmailLog $leftover, EmailLog $delivered): bool
    {
        $leftoverKey = (string) $leftover->dedupe_key;
        if (str_starts_with($leftoverKey, 'audience_campaign:')
            && $leftoverKey === (string) $delivered->dedupe_key) {
            return true;
        }

        $leftoverCampaign = $this->campaignIdFromLog($leftover);
        $deliveredCampaign = $this->campaignIdFromLog($delivered);
        if ($leftoverCampaign < 1 || $deliveredCampaign < 1) {
            return false;
        }

        if ($leftoverCampaign !== $deliveredCampaign) {
            return false;
        }

        $leftoverUser = $this->userIdFromLog($leftover);
        $deliveredUser = $this->userIdFromLog($delivered);
        if ($leftoverUser > 0 && $deliveredUser > 0 && $leftoverUser !== $deliveredUser) {
            return false;
        }

        return true;
    }

    protected function campaignIdFromLog(EmailLog $log): int
    {
        $campaignId = (int) data_get($log->meta, 'campaign_id');
        if ($campaignId > 0) {
            return $campaignId;
        }

        if (preg_match('/^audience_campaign:(\d+):user:(\d+)$/', (string) $log->dedupe_key, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    protected function userIdFromLog(EmailLog $log): int
    {
        $userId = (int) data_get($log->meta, 'user_id');
        if ($userId > 0) {
            return $userId;
        }

        if (preg_match('/^audience_campaign:(\d+):user:(\d+)$/', (string) $log->dedupe_key, $matches)) {
            return (int) $matches[2];
        }

        return 0;
    }

    /**
     * A worker timeout after SMTP invents a leftover failed Welcome /
     * order row. created_at (or updated_at when failed() reused the
     * pending row) sits next to that delivery. A later real failure
     * is hours or days after the old Welcome and must still retry.
     */
    protected function isSameAttemptLeftover(EmailLog $leftover, EmailLog $delivered): bool
    {
        $deliveredAt = $delivered->sent_at ?? $delivered->created_at;
        if (! $deliveredAt) {
            return false;
        }

        $minutes = (int) config('email_notifications.dedupe_window_minutes', 10);
        $window = max(60, $minutes * 60);

        foreach ([$leftover->created_at, $leftover->updated_at] as $at) {
            if ($at && abs((int) $at->diffInSeconds($deliveredAt)) <= $window) {
                return true;
            }
        }

        return false;
    }

    protected function payloadAlreadyDelivered(string $payload): bool
    {
        if ($payload === '') {
            return false;
        }

        foreach (MailJobPayload::campaignDedupeKeys($payload) as $key) {
            if (EmailLog::latestDeliveredByDedupe($key) !== null) {
                return true;
            }
            if (preg_match('/^audience_campaign:(\d+):user:(\d+)$/', $key, $matches)
                && EmailLog::latestDeliveredForCampaignUser((int) $matches[1], (int) $matches[2]) !== null) {
                return true;
            }
        }

        $dedupe = MailJobPayload::dedupeKey($payload);
        if (is_string($dedupe) && str_starts_with($dedupe, 'audience_campaign:')) {
            if (EmailLog::latestDeliveredByDedupe($dedupe) !== null) {
                return true;
            }
            if (preg_match('/^audience_campaign:(\d+):user:(\d+)$/', $dedupe, $matches)
                && EmailLog::latestDeliveredForCampaignUser((int) $matches[1], (int) $matches[2])) {
                return true;
            }
        }

        // Leftover jobs may only serialize ModelIdentifier ids, or the
        // shared generic key. That key is per email, not per campaign —
        // only campaign+user identity is safe.
        if (str_contains($payload, 'AudienceCampaignMail')
            || str_contains($payload, 'audience_campaign')) {
            $campaignIds = MailJobPayload::modelIdentifierIds($payload, EmailCampaign::class);
            if (preg_match_all('/audience_campaign:(\d+):user:(\d+)/', $payload, $pairs, PREG_SET_ORDER)) {
                foreach ($pairs as $pair) {
                    $campaignIds[] = (int) $pair[1];
                }
            }
            $campaignIds = array_values(array_unique(array_filter(
                $campaignIds,
                static fn (int $id): bool => $id > 0
            )));

            foreach ($campaignIds as $campaignId) {
                foreach (MailJobPayload::campaignMailUserIds($payload, $campaignId) as $userId) {
                    if (EmailLog::latestDeliveredForCampaignUser($campaignId, $userId)) {
                        return true;
                    }
                }
            }

            return false;
        }

        if (! is_string($dedupe) || $dedupe === '') {
            return false;
        }

        $delivered = EmailLog::latestDeliveredByDedupe($dedupe);
        if (! $delivered) {
            return false;
        }

        return $this->payloadQueuedAtAlreadyDelivered($payload, $delivered);
    }

    /**
     * Unstamped leftover Welcome jobs still retry after the leftover
     * log is closed. Skip when this job attempt already delivered
     * (sent_at >= queuedAt). An older Welcome must still retry.
     */
    protected function payloadQueuedAtAlreadyDelivered(string $payload, EmailLog $delivered): bool
    {
        $queuedAt = MailJobPayload::queuedAt($payload);
        $deliveredAt = $delivered->sent_at ?? $delivered->created_at;
        if (! $queuedAt || ! $deliveredAt) {
            return false;
        }

        return $deliveredAt->greaterThanOrEqualTo($queuedAt->copy()->subSeconds(5));
    }

    protected function isOneShotCampaignLog(EmailLog $log): bool
    {
        if (str_starts_with((string) $log->dedupe_key, 'audience_campaign:')) {
            return true;
        }

        return (string) $log->template_key === 'audience_campaign'
            || (string) $log->notification_type === 'audience_campaign';
    }

    protected function syncCampaignRecipientFromDeliveredLog(EmailLog $delivered): void
    {
        $campaignId = (int) data_get($delivered->meta, 'campaign_id');
        $userId = (int) data_get($delivered->meta, 'user_id');
        if ($campaignId < 1 || $userId < 1) {
            if (! preg_match('/^audience_campaign:(\d+):user:(\d+)$/', (string) $delivered->dedupe_key, $matches)) {
                return;
            }
            $campaignId = (int) $matches[1];
            $userId = (int) $matches[2];
        }

        try {
            if (! Schema::hasTable((new EmailCampaignRecipient)->getTable())) {
                return;
            }

            $updated = EmailCampaignRecipient::query()
                ->where('email_campaign_id', $campaignId)
                ->where('user_id', $userId)
                ->where(function ($query) {
                    $query->whereIn('status', [
                        EmailCampaignRecipient::STATUS_PENDING,
                        EmailCampaignRecipient::STATUS_QUEUED,
                        EmailCampaignRecipient::STATUS_FAILED,
                    ])->orWhere(function ($skipped) {
                        $skipped->where('status', EmailCampaignRecipient::STATUS_SKIPPED)
                            ->where('skip_reason', EmailCampaignRecipient::SKIP_STALE);
                    });
                })
                ->update([
                    'status' => EmailCampaignRecipient::STATUS_DELIVERED,
                    'skip_reason' => null,
                    'email_log_id' => (int) $delivered->id,
                ]);

            if ($updated) {
                EmailCampaign::query()->find($campaignId)?->recountRecipientTotals();
            }
        } catch (\Throwable) {
        }
    }

    protected function pendingMarkRetriedLog(EmailLog $log): void
    {
        $log->update([
            'status' => EmailLog::STATUS_PENDING,
            'error' => null,
            'attempts' => max(1, (int) $log->attempts) + 1,
        ]);
        $this->requeueFailedCampaignRecipient($log);
    }

    protected function requeueFailedCampaignRecipient(EmailLog $log): void
    {
        $campaignId = (int) data_get($log->meta, 'campaign_id');
        $userId = (int) data_get($log->meta, 'user_id');
        if ($campaignId < 1 || $userId < 1) {
            if (! preg_match('/^audience_campaign:(\d+):user:(\d+)$/', (string) $log->dedupe_key, $matches)) {
                return;
            }
            $campaignId = (int) $matches[1];
            $userId = (int) $matches[2];
        }

        try {
            if (! Schema::hasTable((new EmailCampaignRecipient)->getTable())) {
                return;
            }

            $updated = EmailCampaignRecipient::query()
                ->where('email_campaign_id', $campaignId)
                ->where('user_id', $userId)
                ->where(function ($query) {
                    $query->where('status', EmailCampaignRecipient::STATUS_FAILED)
                        ->orWhere(function ($skipped) {
                            // Expire parks lost mail as skipped/stale and
                            // fails the leftover pending log. Retry must
                            // reclaim that skip or the next recoverStalled()
                            // immediately fails the log again.
                            $skipped->where('status', EmailCampaignRecipient::STATUS_SKIPPED)
                                ->where('skip_reason', EmailCampaignRecipient::SKIP_STALE);
                        });
                })
                ->update([
                    'status' => EmailCampaignRecipient::STATUS_QUEUED,
                    'skip_reason' => null,
                    // Expire/reconcile only touch queued rows with no log FK.
                    // Leaving the failed log attached parked the retry forever.
                    'email_log_id' => null,
                ]);

            if ($updated) {
                $campaign = EmailCampaign::query()->find($campaignId);
                if ($campaign?->status === EmailCampaign::STATUS_FAILED) {
                    // Same trap as recover's FAILED revival: leave MAX in
                    // cache and the next recoverStalled() give-up wipes
                    // leftover pending beside this retried mailable.
                    $campaign->clearFailStreak();
                    $campaign->update([
                        'status' => EmailCampaign::STATUS_SENDING,
                        'sent_at' => null,
                    ]);
                } else {
                    // Already sending: bump updated_at so recover does not
                    // treat this as a stale orphan and reclaim beside the
                    // mailable queue:retry just pushed.
                    $campaign?->touch();
                }
                $campaign?->recountRecipientTotals();
            }
        } catch (\Throwable) {
            // Delivery sync on success still flips failed → delivered.
        }
    }

    protected function failedJobUuidForLog(EmailLog $log): ?string
    {
        if (! Schema::hasTable('failed_jobs')) {
            return null;
        }

        $stored = data_get($log->meta, 'failed_job_uuid');
        if (is_string($stored) && $stored !== '') {
            $storedPayload = DB::table('failed_jobs')->where('uuid', $stored)->value('payload');
            if (is_string($storedPayload) && $this->failedJobMatchesLog($storedPayload, $log)) {
                return $stored;
            }
        }

        $catalog = EmailCatalog::get((string) $log->template_key) ?? [];
        $class = (string) ($log->mailable ?: ($catalog['mailable'] ?? ''));
        if ($class === '') {
            return null;
        }

        $basename = class_basename($class);
        $candidates = [];
        foreach (DB::table('failed_jobs')
            ->where($this->mailJobPayloadConstraint())
            ->where('payload', 'like', '%'.$basename.'%')
            ->orderByDesc('id')
            ->limit(100)
            ->get(['uuid', 'payload']) as $job) {
            $payload = (string) $job->payload;
            if (MailJobPayload::containsMailable($payload, $class)) {
                $candidates[] = $job;
            }
        }

        if ($candidates === []) {
            return null;
        }

        $tight = array_values(array_filter($candidates, function ($job) use ($log) {
            // Token or campaign ModelIdentifier (no stamped dedupeKey).
            // Unique class match without a recipient is how an anonymous
            // Welcome job was retried against the wrong failed log.
            return $this->failedJobMatchesLog((string) $job->payload, $log);
        }));

        if (count($tight) === 1) {
            return (string) $tight[0]->uuid;
        }

        // Unique class match without a recipient token is how an anonymous
        // Welcome job was retried against the wrong failed log.
        return null;
    }

    protected function failedJobMatchesLog(string $payload, EmailLog $log): bool
    {
        return MailJobPayload::matchesEmailLog($payload, $log, requireToken: true);
    }

    protected function refreshFailedJobQueuedAt(string $uuid): void
    {
        $payload = DB::table('failed_jobs')->where('uuid', $uuid)->value('payload');
        if (! is_string($payload) || ! str_contains($payload, 'queuedAt')) {
            return;
        }

        $refreshed = MailJobPayload::refreshQueuedAt($payload);
        if ($refreshed !== $payload) {
            DB::table('failed_jobs')->where('uuid', $uuid)->update(['payload' => $refreshed]);
        }
    }

    protected function queueRetryMissedJob(string $output): bool
    {
        return str_contains($output, 'Unable to find failed job')
            || str_contains($output, 'No retryable jobs found.');
    }

    protected function queueRetryMissedEveryJob(string $output): bool
    {
        return str_contains($output, 'No retryable jobs found.')
            || (str_contains($output, 'Unable to find failed job')
                && ! str_contains($output, 'Pushing failed queue jobs'));
    }

    protected function queuedMailJobsCount(): int
    {
        return app(OpsHealthService::class)->mailPendingCount();
    }

    protected function failedMailJobsCount(): int
    {
        return app(OpsHealthService::class)->mailFailedCount();
    }

    /**
     * @return list<string>
     */
    protected function mailFailedJobUuids(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [];
        }

        return DB::table('failed_jobs')
            ->where(app(OpsHealthService::class)->mailPayloadConstraint())
            ->pluck('uuid')
            ->filter()
            ->map(fn ($uuid) => (string) $uuid)
            ->values()
            ->all();
    }

    /**
     * @return \Closure(Builder): void
     */
    protected function mailJobPayloadConstraint(): \Closure
    {
        return app(OpsHealthService::class)->mailPayloadConstraint();
    }

    /**
     * @return array{status: ?string, template_key: ?string, to_email: ?string, date_from: ?string, date_to: ?string}
     */
    protected function recentLogFilters(Request $request): array
    {
        $status = $request->query('status');
        $template = $request->query('template_key');
        $email = $request->query('to_email');

        return [
            'status' => is_string($status) && in_array($status, ['pending', 'delivered', 'failed'], true)
                ? $status
                : null,
            'template_key' => is_string($template) && $template !== ''
                ? substr($template, 0, 80)
                : null,
            'to_email' => is_string($email) && $email !== ''
                ? substr($email, 0, 190)
                : null,
            'date_from' => $this->validFilterDate($request->query('date_from')),
            'date_to' => $this->validFilterDate($request->query('date_to')),
        ];
    }

    protected function validFilterDate(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (! $date instanceof \DateTimeImmutable) {
            return null;
        }

        return $date->format('Y-m-d') === $value ? $value : null;
    }

    protected function applyToEmailFilter($query, string $email)
    {
        $like = '%'.$this->escapeLike($email).'%';
        $driver = $query->getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            return $query->whereRaw('to_email LIKE ? ESCAPE ?', [$like, '\\']);
        }

        return $query->where('to_email', 'like', $like);
    }

    protected function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    protected function shouldRebuildAsTest(EmailLog $log): bool
    {
        if (data_get($log->meta, 'source') === 'email_center_test') {
            return true;
        }

        if (str_starts_with((string) $log->dedupe_key, 'email_center_test:')) {
            return true;
        }

        return $this->isFrameworkTemplate((string) $log->template_key)
            && strcasecmp((string) $log->to_email, (string) request()->user()?->email) === 0
            && str_contains((string) $log->subject, 'Test Preview');
    }

    protected function isFrameworkTemplate(string $key): bool
    {
        return in_array($key, ['password_reset', 'email_verification'], true);
    }

    protected function sendFrameworkTestHtml(string $key, string $adminEmail, string $dedupe): void
    {
        $html = $this->frameworkPreviewHtml($key);
        abort_unless($html, 404);

        $subject = $key === 'email_verification'
            ? 'Verify your email (Test Preview)'
            : 'Password Reset (Test Preview)';

        app()->instance('platform.mail.meta', [
            'notification_type' => $key,
            'dedupe_key' => $dedupe,
            'source' => 'email_center_test',
        ]);

        try {
            Mail::html($html, function ($message) use ($adminEmail, $key, $subject, $dedupe) {
                $message->to($adminEmail)->subject($subject);
                if (method_exists($message, 'getSymfonyMessage')) {
                    $headers = $message->getSymfonyMessage()->getHeaders();
                    $headers->addTextHeader('X-Platform-Notification-Type', $key);
                    $headers->addTextHeader('X-Platform-Dedupe-Key', $dedupe);
                    $headers->addTextHeader('X-Platform-Source', 'email_center_test');
                }
            });
        } finally {
            app()->forgetInstance('platform.mail.meta');
        }
    }

    protected function frameworkPreviewHtml(string $key): ?string
    {
        return match ($key) {
            'password_reset' => $this->renderMarkdown('emails.password-reset-preview', [
                'resetUrl' => rtrim(app_public_url(), '/').'/password/reset/preview-token',
            ]),
            'email_verification' => $this->renderMarkdown('emails.email-verification-preview', [
                'verifyUrl' => EmailCatalog::previewVerificationUrl(),
            ]),
            default => null,
        };
    }

    protected function renderMarkdown(string $view, array $data = []): string
    {
        return app(Markdown::class)->render($view, $data);
    }

    /**
     * @param  array{status: ?string, template_key: ?string, to_email: ?string, date_from: ?string, date_to: ?string}  $filters
     */
    private function recentEmailLogs(array $filters): LengthAwarePaginator
    {
        try {
            return EmailLog::query()
                ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                ->when($filters['template_key'] ?? null, fn ($q, $key) => $q->where('template_key', $key))
                ->when($filters['to_email'] ?? null, fn ($q, $email) => $this->applyToEmailFilter($q, $email))
                ->when($filters['date_from'] ?? null, fn ($q, $from) => $q->whereRaw('date(coalesce(sent_at, created_at)) >= ?', [$from]))
                ->when($filters['date_to'] ?? null, fn ($q, $to) => $q->whereRaw('date(coalesce(sent_at, created_at)) <= ?', [$to]))
                ->latest('id')
                ->paginate(50)
                ->withQueryString()
                ->fragment('ec-recent');
        } catch (\Throwable) {
            return $this->emptyEmailLogPaginator();
        }
    }

    private function emailTemplateStats(): Collection
    {
        try {
            return EmailLog::query()
                ->selectRaw(
                    'template_key, COUNT(*) as sent_count, MAX(CASE WHEN sent_at >= ? AND sent_at <= ? THEN sent_at END) as last_sent_at',
                    [EmailLog::PLAUSIBLE_SQL_DATETIME_FLOOR, EmailLog::PLAUSIBLE_SQL_DATETIME_CEIL]
                )
                ->where('status', EmailLog::STATUS_DELIVERED)
                ->whereNotNull('template_key')
                ->groupBy('template_key')
                ->get()
                ->keyBy('template_key');
        } catch (\Throwable) {
            return collect();
        }
    }

    private function emailNotificationSettingRows(): Collection
    {
        try {
            return EmailNotificationSetting::query()->pluck('enabled', 'type');
        } catch (\Throwable) {
            return collect();
        }
    }

    private function failedEmailLogs(): Collection
    {
        try {
            return EmailLog::failed()->latest('id')->limit(20)->get();
        } catch (\Throwable) {
            return collect();
        }
    }

    private function recentCampaigns(): Collection
    {
        if (! Schema::hasTable('email_campaigns')) {
            return collect();
        }

        try {
            return EmailCampaign::query()->latest('id')->limit(3)->get();
        } catch (\Throwable) {
            return collect();
        }
    }

    private function emptyEmailLogPaginator(): LengthAwarePaginator
    {
        return (new LengthAwarePaginator([], 0, 50))->withQueryString()->fragment('ec-recent');
    }

    private function schemaTableAvailable(string $table): bool
    {
        if (array_key_exists($table, $this->schemaTableAvailable)) {
            return $this->schemaTableAvailable[$table];
        }

        if (! Schema::hasTable($table)) {
            return $this->schemaTableAvailable[$table] = false;
        }

        try {
            DB::table($table)->limit(1)->exists();

            return $this->schemaTableAvailable[$table] = true;
        } catch (\Throwable) {
            return $this->schemaTableAvailable[$table] = false;
        }
    }

    /**
     * Hostinger leftover sent_at strings win SQLite MAX() and 500
     * Carbon::parse() on the template cards.
     */
    private function parseLastSentAt(mixed $value): ?Carbon
    {
        try {
            if ($value instanceof Carbon) {
                return $value;
            }

            if ($value instanceof \DateTimeInterface) {
                return Carbon::parse($value->format('Y-m-d H:i:s'));
            }

            $raw = trim((string) $value);
            if ($raw === '') {
                return null;
            }

            $parsed = Carbon::parse($raw);
            if ($parsed->lt(EmailLog::PLAUSIBLE_SQL_DATETIME_FLOOR)
                || $parsed->gt(EmailLog::PLAUSIBLE_SQL_DATETIME_CEIL)) {
                return null;
            }

            return $parsed;
        } catch (\Throwable) {
            return null;
        }
    }
}
