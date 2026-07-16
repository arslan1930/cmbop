<?php

namespace App\Mail;

use App\Models\EmailLog;
use App\Models\EmailNotificationPreference;
use App\Models\EmailNotificationSetting;
use App\Models\User;
use App\Support\EmailCatalog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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

    public function __construct()
    {
        $this->onConnection(config('email_notifications.queue_connection', 'sync'));
        $this->onQueue(config('email_notifications.queue', 'emails'));
    }

    public function send($mailer)
    {
        if (!$this->passesNotificationPolicy()) {
            Log::info('Email suppressed by notification policy', [
                'type' => $this->notificationType,
                'dedupe' => $this->dedupeKey,
                'to' => $this->recipientUser?->email,
            ]);

            return null;
        }

        $this->applyBrandEnvelope();

        return parent::send($mailer);
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

        if ($type && !EmailNotificationSetting::isEnabled($type)) {
            return false;
        }

        if ($type) {
            $preference = config("email_notifications.types.{$type}.preference");
            if (!EmailNotificationPreference::allows($recipient, $preference)) {
                return false;
            }
        }

        if (!$this->dedupeKey) {
            $this->dedupeKey = $this->defaultDedupeKey($type, $recipient);
        }

        if ($this->dedupeKey && $this->isDuplicate($this->dedupeKey)) {
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
        if (!$type) {
            return null;
        }

        $parts = [
            $type,
            $recipient?->email ?? data_get($this->to, '0.address') ?? 'unknown',
            class_basename(static::class),
        ];

        foreach (['order', 'deposit', 'withdrawal', 'site', 'newUser'] as $prop) {
            if (isset($this->{$prop}) && is_object($this->{$prop}) && isset($this->{$prop}->id)) {
                $parts[] = $prop . ':' . $this->{$prop}->id;
            }
        }

        return implode('|', $parts);
    }

    protected function isDuplicate(string $key): bool
    {
        $minutes = (int) config('email_notifications.dedupe_window_minutes', 10);

        return EmailLog::query()
            ->where('dedupe_key', $key)
            ->where('status', EmailLog::STATUS_DELIVERED)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->exists();
    }

    protected function brand(): array
    {
        return config('email_notifications.brand', []);
    }

    protected function firstName(?User $user = null): string
    {
        $user = $user ?: $this->recipientUser;
        $name = trim((string) ($user?->name ?? 'there'));
        $parts = preg_split('/\s+/', $name) ?: ['there'];

        return $parts[0] ?: 'there';
    }
}
