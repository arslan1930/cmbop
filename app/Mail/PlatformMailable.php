<?php

namespace App\Mail;

use App\Models\EmailCampaign;
use App\Models\EmailLog;
use App\Models\EmailNotificationPreference;
use App\Models\EmailNotificationSetting;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Billing\InvoicePdfGenerator;
use App\Support\EmailCatalog;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Base mailable for the platform email layer.
 * Existing Mail::to()->send(new X) call sites keep working; X extends this class
 * to gain queueing, preference gates, admin toggles, and duplicate prevention.
 */
abstract class PlatformMailable extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** Config key under email_notifications.types */
    public ?string $notificationType = null;

    /** Unique key to prevent duplicate sends within the dedupe window */
    public ?string $dedupeKey = null;

    /** Optional recipient user for preference checks */
    public ?User $recipientUser = null;

    /** When true, skip user preference gate (staff / security / system) */
    public bool $skipUserPreference = false;

    /** When this mail was handed to the queue, so stale jobs can be dropped */
    public ?string $queuedAt = null;

    /** Email Center test send: deliver now and skip admin/user/dedupe gates */
    public bool $forceSend = false;

    /** Why send() returned null (stale / disabled / preference / duplicate). */
    public ?string $suppressReason = null;

    public function __construct()
    {
        $this->onConnection(static::resolveQueueConnection());
        $this->onQueue(config('email_notifications.queue', 'emails'));
        $this->queuedAt = now()->toIso8601String();
    }

    /**
     * Queueing onto a backend that cannot store the job loses the mail outright,
     * so fall back to sending inline when the database queue has no jobs table.
     */
    protected static function resolveQueueConnection(): string
    {
        $connection = (string) config('email_notifications.queue_connection', 'sync');

        if (config("queue.connections.{$connection}.driver") !== 'database') {
            return $connection;
        }

        try {
            $table = (string) config("queue.connections.{$connection}.table", 'jobs');

            if (Schema::hasTable($table)) {
                return $connection;
            }
        } catch (\Throwable $e) {
            Log::warning('Mail queue backend unreachable, sending inline', [
                'connection' => $connection,
                'error' => $e->getMessage(),
            ]);

            return 'sync';
        }

        Log::warning('Mail queue table missing, sending inline', ['connection' => $connection]);

        return 'sync';
    }

    /**
     * A backlog that finally gets consumed must not deliver days-old news.
     *
     * "Your order moved to review" arriving on Friday for something that
     * happened on Tuesday is worse than not arriving at all, and a queue that
     * sat unattended can hold hundreds of them.
     */
    protected function isStale(): bool
    {
        $maxHours = (int) config('email_notifications.max_age_hours', 24);

        if ($maxHours <= 0 || blank($this->queuedAt)) {
            return false;
        }

        try {
            return Carbon::parse($this->queuedAt)->addHours($maxHours)->isPast();
        } catch (\Throwable) {
            return false;
        }
    }

    public function send($mailer)
    {
        if ($this->forceSend) {
            $type = $this->notificationType ?: EmailCatalog::keyFromMailable(static::class);
            $this->notificationType = $type;
            if (! $this->dedupeKey) {
                $this->dedupeKey = 'email_center_test:'.($type ?: 'unknown').':'.(string) Str::uuid();
            }
        } elseif ($this->isStale()) {
            $this->suppressReason = 'stale';
            Log::info('Email dropped as stale', [
                'type' => $this->notificationType,
                'queued_at' => $this->queuedAt,
                'to' => $this->recipientUser?->email,
            ]);
            $this->abandonOpenLog($this->suppressErrorMessage());

            return null;
        } elseif (! $this->passesNotificationPolicy()) {
            $this->suppressReason = $this->suppressReason ?: 'policy';
            Log::info('Email suppressed by notification policy', [
                'type' => $this->notificationType,
                'dedupe' => $this->dedupeKey,
                'to' => $this->recipientUser?->email,
                'reason' => $this->suppressReason,
            ]);
            $this->abandonOpenLog($this->suppressErrorMessage());

            return null;
        }

        $this->applyBrandEnvelope();

        // Register headers here (not in __construct): ShouldQueue serializes the
        // mailable before send(), and Closures in $callbacks cannot be serialized.
        $this->withSymfonyMessage(function ($message) {
            $headers = $message->getHeaders();
            if ($this->notificationType) {
                $headers->addTextHeader('X-Platform-Notification-Type', (string) $this->notificationType);
            }
            if ($this->dedupeKey) {
                $headers->addTextHeader('X-Platform-Dedupe-Key', (string) $this->dedupeKey);
            }
            $audience = property_exists($this, 'audience') ? $this->audience : null;
            if (filled($audience)) {
                $headers->addTextHeader('X-Platform-Audience', (string) $audience);
            }
        });

        $meta = [
            'notification_type' => $this->notificationType,
            'dedupe_key' => $this->dedupeKey,
            'audience' => property_exists($this, 'audience') ? $this->audience : null,
            'mailable' => static::class,
            'source' => $this->forceSend ? 'email_center_test' : null,
        ];
        if (isset($this->campaign) && $this->campaign instanceof EmailCampaign && $this->campaign->id) {
            $meta['campaign_id'] = (int) $this->campaign->id;
        }
        $logUser = $this->recipientUser
            ?? ((isset($this->recipient) && $this->recipient instanceof User) ? $this->recipient : null);
        if ($logUser?->id) {
            $meta['user_id'] = (int) $logUser->id;
        }
        app()->instance('platform.mail.meta', $meta);

        try {
            return parent::send($mailer);
        } finally {
            app()->forgetInstance('platform.mail.meta');
        }
    }

    protected function applyBrandEnvelope(): void
    {
        $brand = config('email_notifications.brand', []);
        $replyTo = $brand['reply_to'] ?? null;
        if (filled($replyTo) && empty($this->replyTo)) {
            $this->replyTo($replyTo);
        }

        $from = $brand['sender_email'] ?? null;
        $fromName = $brand['sender_name'] ?? null;
        if (filled($from) && empty($this->from)) {
            $this->from($from, $fromName);
        }
    }

    protected function passesNotificationPolicy(): bool
    {
        $type = $this->notificationType ?: EmailCatalog::keyFromMailable(static::class);
        $this->notificationType = $type;
        $recipient = $this->resolveRecipientUser();
        $this->recipientUser = $recipient;

        if ($type && ! EmailNotificationSetting::isEnabled($type)) {
            $this->suppressReason = 'disabled';

            return false;
        }

        if ($type && ! $this->skipUserPreference) {
            $preference = config("email_notifications.types.{$type}.preference");
            if (! EmailNotificationPreference::allows($recipient, $preference)) {
                $this->suppressReason = 'preference';

                return false;
            }
        }

        if (! $this->dedupeKey) {
            $this->dedupeKey = $this->defaultDedupeKey($type, $recipient);
        }

        if ($this->dedupeKey && $this->isDuplicate($this->dedupeKey)) {
            $this->suppressReason = 'duplicate';

            return false;
        }

        return true;
    }

    protected function resolveRecipientUser(): ?User
    {
        if ($this->recipientUser instanceof User) {
            return $this->recipientUser;
        }

        foreach (['user', 'publisher', 'customer', 'advertiser'] as $prop) {
            if (isset($this->{$prop}) && $this->{$prop} instanceof User) {
                return $this->{$prop};
            }
        }

        $email = data_get($this->to, '0.address') ?? data_get($this->to, '0');
        if (is_string($email) && $email !== '') {
            return User::query()->where('email', $email)->first();
        }

        return null;
    }

    protected function defaultDedupeKey(?string $type, ?User $recipient): ?string
    {
        if (! $type) {
            return null;
        }

        $parts = [
            $type,
            $recipient?->email ?? data_get($this->to, '0.address') ?? 'unknown',
            class_basename(static::class),
        ];

        foreach (['order', 'deposit', 'withdrawal', 'site', 'newUser'] as $prop) {
            if (isset($this->{$prop}) && is_object($this->{$prop}) && isset($this->{$prop}->id)) {
                $parts[] = $prop.':'.$this->{$prop}->id;
            }
        }

        if (filled($variant = $this->dedupeVariant())) {
            $parts[] = 'variant:'.$variant;
        }

        return implode('|', $parts);
    }

    /**
     * What makes this send different from the last one about the same record.
     *
     * The default key identifies "this type of email, about this record, to this
     * person", which is what you want for suppressing a retry. It is wrong
     * whenever the point of the email is *which* transition happened: approving a
     * site is verify plus activate, two clicks seconds apart, and the second one
     * was being dropped as a duplicate of the first. Subclasses whose meaning
     * depends on a status or action return it here.
     */
    protected function dedupeVariant(): ?string
    {
        return null;
    }

    protected function suppressErrorMessage(): string
    {
        return match ($this->suppressReason) {
            'stale' => 'Dropped as stale',
            'disabled' => 'Suppressed: notification type disabled',
            'preference' => 'Suppressed: recipient opted out',
            'duplicate' => 'Suppressed: duplicate of a recent send',
            default => 'Suppressed by notification policy',
        };
    }

    /**
     * A retried job that is dropped (stale/policy) still completes successfully,
     * so close the open Email Center log or it stays pending forever.
     */
    protected function abandonOpenLog(string $error): void
    {
        try {
            $open = EmailLog::openByDedupe($this->dedupeKey);
            if ($open->isEmpty()) {
                return;
            }

            $duplicate = $this->suppressReason === 'duplicate';
            foreach ($open as $existing) {
                $existing->fill([
                    'status' => $duplicate ? EmailLog::STATUS_DELIVERED : EmailLog::STATUS_FAILED,
                    'error' => $duplicate ? null : $error,
                    'sent_at' => $duplicate ? ($existing->sent_at ?? now()) : $existing->sent_at,
                ]);
                $existing->meta = array_filter(array_merge((array) $existing->meta, [
                    'suppressed' => $this->suppressReason ?: 'policy',
                ]));
                $existing->save();
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to close suppressed mail log', [
                'mailable' => static::class,
                'dedupe' => $this->dedupeKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function isDuplicate(string $key): bool
    {
        try {
            if (! Schema::hasTable((new EmailLog)->getTable())) {
                return false;
            }

            $minutes = (int) config('email_notifications.dedupe_window_minutes', 10);

            return EmailLog::query()
                ->where('dedupe_key', $key)
                ->where('status', EmailLog::STATUS_DELIVERED)
                ->where('created_at', '>=', now()->subMinutes($minutes))
                ->exists();
        } catch (\Throwable $e) {
            Log::warning('Email dedupe check failed; allowing send', [
                'dedupe' => $key,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    protected function brand(): array
    {
        $brand = config('email_notifications.brand', []);
        $brand['logo_url'] = mail_brand_logo_url();

        return $brand;
    }

    protected function firstName(?User $user = null): string
    {
        $user = $user ?: $this->recipientUser;
        $name = trim((string) ($user?->name ?? 'there'));
        $parts = preg_split('/\s+/', $name) ?: ['there'];

        return $parts[0] ?: 'there';
    }

    /**
     * Named route resolved against the public app host (not a mismatched APP_URL).
     *
     * @param  array<string, mixed>|int|string|null  $parameters
     */
    protected function publicRoute(string $name, mixed $parameters = [], bool $absolute = true): string
    {
        $path = route($name, $parameters, absolute: false);

        if (! $absolute) {
            return $path;
        }

        return rtrim(app_public_url(), '/').$path;
    }

    /**
     * Advertiser Orders page, optionally focused on one order (matches in-app bells).
     *
     * @param  array<string, mixed>  $extra
     */
    protected function advertiserOrdersUrl(?int $orderId = null, array $extra = []): string
    {
        $params = $extra;
        if ($orderId) {
            $params['focus'] = $params['focus'] ?? 'order';
            $params['order'] = $orderId;
        }

        return $this->publicRoute('advertiser.orders', $params);
    }

    protected function advertiserBillingDownloadUrl(Invoice $invoice): string
    {
        if ((int) $invoice->id === EmailCatalog::PREVIEW_ID) {
            return rtrim(app_public_url(), '/').'/advertiser/billing/preview';
        }

        return $this->publicRoute('advertiser.billing.download', $invoice);
    }

    protected function attachInvoicePdfIfLive($mail, Invoice $invoice, string $as): void
    {
        if ((int) $invoice->id === EmailCatalog::PREVIEW_ID) {
            return;
        }

        $path = app(InvoicePdfGenerator::class)->absolutePath($invoice);
        if ($path && is_readable($path)) {
            $mail->attach($path, [
                'as' => $as,
                'mime' => 'application/pdf',
            ]);
        }
    }

    /**
     * Publisher Tasks page, optionally focused on one order (matches in-app bells).
     *
     * @param  array<string, mixed>  $extra
     */
    protected function publisherTasksUrl(?int $orderId = null, array $extra = []): string
    {
        $params = $extra;
        if ($orderId) {
            $params['focus'] = $params['focus'] ?? 'order';
            $params['order'] = $orderId;
        }

        return $this->publicRoute('publisher.tasks', $params);
    }

    protected function failedJobUuid(): ?string
    {
        if (! isset($this->job) || ! is_object($this->job) || ! method_exists($this->job, 'uuid')) {
            return null;
        }

        try {
            $uuid = $this->job->uuid();

            return is_string($uuid) && $uuid !== '' ? $uuid : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        try {
            $type = $this->notificationType ?: EmailCatalog::keyFromMailable(static::class);
            $envelopeTo = data_get($this->to, '0.address');
            if (! is_string($envelopeTo) || $envelopeTo === '') {
                $first = data_get($this->to, '0');
                $envelopeTo = is_string($first) ? $first : null;
            }

            // Test sends are addressed to the admin; the synthetic recipient
            // must not be written onto the failure log.
            $to = $this->forceSend
                ? ($envelopeTo ?: $this->recipientUser?->email)
                : ($this->recipientUser?->email ?: $envelopeTo);
            $to = is_string($to) && $to !== '' ? $to : 'unknown';

            $meta = array_filter([
                'source' => $this->forceSend ? 'email_center_test' : 'queue',
                'failed_job_uuid' => $this->failedJobUuid(),
            ]);
            if (isset($this->campaign) && $this->campaign instanceof EmailCampaign && $this->campaign->id) {
                $meta['campaign_id'] = (int) $this->campaign->id;
            }
            $logUser = $this->recipientUser
                ?? ((isset($this->recipient) && $this->recipient instanceof User) ? $this->recipient : null);
            if ($logUser?->id) {
                $meta['user_id'] = (int) $logUser->id;
            }

            $payload = [
                'mailable' => static::class,
                'template_key' => $type,
                'notification_type' => $type,
                'dedupe_key' => $this->dedupeKey,
                'to_email' => $to,
                'subject' => $this->subject,
                'status' => EmailLog::STATUS_FAILED,
                'error' => $exception?->getMessage(),
            ];

            $open = EmailLog::openByDedupe($this->dedupeKey);
            if ($open->isNotEmpty()) {
                foreach ($open as $existing) {
                    $rowPayload = $payload;
                    if (($rowPayload['to_email'] ?? '') === 'unknown'
                        && filled($existing->to_email)
                        && $existing->to_email !== 'unknown') {
                        unset($rowPayload['to_email']);
                    }
                    $existing->fill($rowPayload);
                    $existing->meta = array_filter(array_merge((array) $existing->meta, $meta));
                    $existing->attempts = max(1, (int) $existing->attempts) + 1;
                    $existing->save();
                }

                return;
            }

            EmailLog::create(array_merge($payload, [
                'uuid' => (string) Str::uuid(),
                'attempts' => 1,
                'meta' => $meta,
            ]));
        } catch (\Throwable $e) {
            Log::warning('Failed to record mail failure', [
                'mailable' => static::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
