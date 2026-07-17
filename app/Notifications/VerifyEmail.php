<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail as BaseVerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

/**
 * Branded email-verification notification used on signup / resend.
 * Kept synchronous (not queued) so it does not depend on queue workers.
 */
class VerifyEmail extends BaseVerifyEmail
{
    public function toMail($notifiable): MailMessage
    {
        $verificationUrl = $this->verificationUrl($notifiable);
        $appName = config('app.name', 'SEOLinkBuildings');
        $name = trim((string) ($notifiable->name ?? 'there'));
        $firstName = explode(' ', $name)[0] ?: 'there';

        return (new MailMessage)
            ->subject("Verify your {$appName} email")
            ->greeting("Hi {$firstName},")
            ->line('Thanks for creating your account. Please verify your email address to activate login and start using the marketplace.')
            ->action('Verify Email Address', $verificationUrl)
            ->line('This verification link expires in '.Config::get('auth.verification.expire', 60).' minutes.')
            ->line('If you did not create an account, no further action is required.');
    }

    protected function verificationUrl($notifiable): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );
    }
}
