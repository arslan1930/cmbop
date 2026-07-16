<?php

namespace App\Listeners;

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
        $mailableClass = $event->data['__laravel_notification']
            ?? ($event->data['mailable'] ?? null);

        // Prefer Symfony message headers set by our catalog / mailable
        $mailable = null;
        if (isset($event->data) && is_array($event->data)) {
            foreach ($event->data as $value) {
                if (is_object($value) && is_subclass_of($value, \Illuminate\Mail\Mailable::class)) {
                    $mailable = $value::class;
                    break;
                }
            }
        }

        $subject = $message->getSubject() ?: '(no subject)';
        $templateKey = EmailCatalog::keyFromMailable($mailable) ?? EmailCatalog::keyFromSubject($subject);

        EmailLog::create([
            'uuid' => (string) Str::uuid(),
            'mailable' => $mailable,
            'template_key' => $templateKey,
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
