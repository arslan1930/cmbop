<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\PlatformMailable;
use App\Models\EmailCampaign;
use App\Models\EmailCampaignRecipient;
use App\Models\EmailLog;
use App\Models\EmailNotificationSetting;
use App\Models\User;
use App\Support\EmailCatalog;
use App\Support\MailJobPayload;
use App\Support\UserFacingError;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EmailCenterController extends Controller
{
    public function index(Request $request)
    {
        $stats = EmailLog::dashboardKpis();
        $filters = $this->recentLogFilters($request);

        $recentLogs = EmailLog::query()
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['template_key'] ?? null, fn ($q, $key) => $q->where('template_key', $key))
            ->when($filters['to_email'] ?? null, fn ($q, $email) => $this->applyToEmailFilter($q, $email))
            ->when($filters['date_from'] ?? null, fn ($q, $from) => $q->whereRaw('date(coalesce(sent_at, created_at)) >= ?', [$from]))
            ->when($filters['date_to'] ?? null, fn ($q, $to) => $q->whereRaw('date(coalesce(sent_at, created_at)) <= ?', [$to]))
            ->latest('id')
            ->paginate(50)
            ->withQueryString()
            ->fragment('ec-recent');

        $templateStats = EmailLog::query()
            ->selectRaw('template_key, COUNT(*) as sent_count, MAX(sent_at) as last_sent_at')
            ->where('status', EmailLog::STATUS_DELIVERED)
            ->whereNotNull('template_key')
            ->groupBy('template_key')
            ->get()
            ->keyBy('template_key');

        $settingRows = EmailNotificationSetting::query()->pluck('enabled', 'type');
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
            $meta['last_sent_at'] = $row?->last_sent_at;
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

        $queue = [
            'connection' => config('queue.default'),
            'mail_connection' => config('email_notifications.queue_connection', config('queue.default')),
            'mail_queue' => config('email_notifications.queue', 'emails'),
            'auto_drain' => (bool) config('email_notifications.auto_drain'),
            'pending_jobs' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0,
            'failed_jobs' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0,
            'mail_pending_jobs' => $this->queuedMailJobsCount(),
            'mail_failed_jobs' => $this->failedMailJobsCount(),
        ];

        $failedLogs = EmailLog::failed()->latest('id')->limit(20)->get();

        $recentCampaigns = Schema::hasTable('email_campaigns')
            ? EmailCampaign::query()->latest('id')->limit(3)->get()
            : collect();

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

        DB::transaction(function () use ($editable, $data) {
            foreach ($editable as $type) {
                EmailNotificationSetting::updateOrCreate(
                    ['type' => $type],
                    ['enabled' => (string) $data['enabled'][$type] === '1']
                );
            }
        });

        EmailNotificationSetting::flushCache();

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
    }

    public function retryFailed(Request $request)
    {
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

        $this->markRetriedMailLogsPending($this->actuallyRetriedJobUuids($uuids), $payloads);

        return back()->with('success', 'Retried '.count($uuids).' failed mail job(s). Other failed jobs were left untouched.');
    }

    protected function retryFailedLog(int $logId)
    {
        $log = EmailLog::query()->findOrFail($logId);
        if ($log->status !== EmailLog::STATUS_FAILED) {
            return back()->with('error', 'That email log is not failed.');
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
                return back()->with('error', 'Cannot rebuild production payload — retry the queue job.');
            }

            $log->update([
                'status' => EmailLog::STATUS_PENDING,
                'error' => null,
                'attempts' => max(1, (int) $log->attempts) + 1,
            ]);
            $this->requeueFailedCampaignRecipient($log);

            return back()->with('success', 'Re-queued the failed mail job for this log.');
        }

        return back()->with('error', 'Cannot rebuild production payload — retry the queue job.');
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
            $payload = (string) ($payloadsByUuid[$stored] ?? '');
            // Legacy unique-class stamps can point at someone else's job.
            if ($stored !== ''
                && $payload !== ''
                && empty($claimedUuids[$stored])
                && in_array($stored, $uuids, true)
                && $this->failedJobMatchesLog($payload, $log)) {
                $this->pendingMarkRetriedLog($log);
                $marked[$log->id] = true;
                $claimedUuids[$stored] = true;
            }
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
            $this->pendingMarkRetriedLog($log);
            $marked[$log->id] = true;
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
                ->where('status', EmailCampaignRecipient::STATUS_FAILED)
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
                    $campaign->update([
                        'status' => EmailCampaign::STATUS_SENDING,
                        'sent_at' => null,
                    ]);
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

        $to = (string) $log->to_email;
        $dedupe = (string) $log->dedupe_key;
        $tight = array_values(array_filter($candidates, function ($job) use ($to, $dedupe) {
            $payload = (string) $job->payload;

            return MailJobPayload::containsToken($payload, $to)
                || MailJobPayload::containsToken($payload, $dedupe);
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
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        return (int) DB::table('jobs')->where($this->mailJobPayloadConstraint())->count();
    }

    protected function failedMailJobsCount(): int
    {
        if (! Schema::hasTable('failed_jobs')) {
            return 0;
        }

        return (int) DB::table('failed_jobs')->where($this->mailJobPayloadConstraint())->count();
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
            ->where($this->mailJobPayloadConstraint())
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
        return function ($q) {
            $q->where('payload', 'like', '%SendQueuedMailable%');
        };
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
}
