<?php

namespace App\Listeners;

use App\Mail\PlatformMailable;
use App\Models\EmailLog;
use App\Support\EmailCatalog;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Str;

class LogSentEmail
{
    public function handle(MessageSent $event): void
    {
        $message = $event->message;
        $to = $this->firstAddress($message->getTo());
        $from = $this->firstAddress($message->getFrom());

        $mailable = null;
        $mailableInstance = null;
        if (isset($event->data) && is_array($event->data)) {
            foreach ($event->data as $value) {
                if (is_object($value) && is_subclass_of($value, \Illuminate\Mail\Mailable::class)) {
                    $mailable = $value::class;
                    $mailableInstance = $value;
                    break;
                }
            }
        }

        $meta = app()->bound('platform.mail.meta') ? (array) app('platform.mail.meta') : [];
        $headers = $message->getHeaders();

        $notificationType = $meta['notification_type']
            ?? $this->header($headers, 'X-Platform-Notification-Type');
        $dedupeKey = $meta['dedupe_key']
            ?? $this->header($headers, 'X-Platform-Dedupe-Key');
        $audience = $meta['audience']
            ?? $this->header($headers, 'X-Platform-Audience');

        if ($mailableInstance instanceof PlatformMailable) {
            $notificationType = $notificationType ?: $mailableInstance->notificationType;
            $dedupeKey = $dedupeKey ?: $mailableInstance->dedupeKey;
            $mailable = $mailable ?: $mailableInstance::class;
            if (!$audience && property_exists($mailableInstance, 'audience')) {
                $audience = $mailableInstance->audience;
            }
        }
        $mailable = $mailable ?: ($meta['mailable'] ?? null);

        $subject = $message->getSubject() ?: '(no subject)';
        $templateKey = $notificationType
            ?: (EmailCatalog::keyFromMailable($mailable) ?? EmailCatalog::keyFromSubject($subject));

        if (!$audience && $notificationType) {
            $audience = config("email_notifications.types.{$notificationType}.audience");
        }

        EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'mailable' => $mailable,
            'template_key' => $templateKey,
            'notification_type' => $notificationType ?: $templateKey,
            'dedupe_key' => $dedupeKey,
            'audience' => $audience,
            'to_email' => $to['email'] ?? 'unknown',
            'to_name' => $to['name'] ?? null,
            'from_email' => $from['email'] ?? config('mail.from.address'),
            'subject' => $subject,
            'status' => EmailLog::STATUS_DELIVERED,
            'attempts' => 1,
            'meta' => [
                'mailer' => config('mail.default'),
            ],
            'sent_at' => now(),
        ]);
    }

    protected function header($headers, string $name): ?string
    {
        if (!$headers || !$headers->has($name)) {
            return null;
        }

        return $headers->get($name)?->getBodyAsString();
    }

    protected function firstAddress(?array $addresses): array
    {
        if (empty($addresses)) {
            return [];
        }

        $address = $addresses[array_key_first($addresses)];
        if (is_object($address) && method_exists($address, 'getAddress')) {
            return [
                'email' => $address->getAddress(),
                'name' => method_exists($address, 'getName') ? $address->getName() : null,
            ];
        }

        if (is_string($address)) {
            return ['email' => $address, 'name' => null];
        }

        return [];
    }
}
