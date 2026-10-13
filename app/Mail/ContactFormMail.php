<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ContactFormMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $name,
        public string $email,
        public string $body,
    ) {
        $connection = (string) config('email_notifications.queue_connection', config('queue.default', 'sync'));
        if ($connection !== '') {
            $this->onConnection($connection);
        }
        $this->onQueue((string) config('email_notifications.queue', 'emails'));
    }

    public function build(): self
    {
        $safeName = trim($this->name) !== '' ? trim($this->name) : 'Website visitor';

        return $this->subject('Contact form: '.$safeName)
            ->replyTo($this->email, $safeName)
            ->markdown('emails.contact-form', [
                'senderName' => $safeName,
                'senderEmail' => $this->email,
                'body' => $this->body,
            ]);
    }
}
