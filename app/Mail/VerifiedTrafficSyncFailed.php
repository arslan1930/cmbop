<?php

namespace App\Mail;

use App\Models\Site;
use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class VerifiedTrafficSyncFailed extends PlatformMailable
{
    public function __construct(
        public Site $site,
        User $publisher,
        public bool $authFailure = false,
    ) {
        parent::__construct();
        $this->notificationType = 'verified_traffic_sync_failed';
        $this->recipientUser = $publisher;
        $this->dedupeKey = 'verified-traffic-failed:'.$site->id.':'.now()->format('Y-m-d');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Verified traffic needs attention — '.$this->site->site_name,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.verified-traffic-sync-failed',
            with: [
                'site' => $this->site,
                'publisher' => $this->recipientUser,
                'authFailure' => $this->authFailure,
            ],
        );
    }
}
