<?php

namespace App\Mail;

use App\Models\Site;
use App\Models\User;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

class SiteDiscountEnded extends PlatformMailable
{
    public function __construct(
        public Site $site,
        User $publisher,
        public ?float $percent = null,
        public ?Carbon $endedAt = null,
    ) {
        parent::__construct();
        $this->notificationType = 'site_discount_ended';
        $this->recipientUser = $publisher;
        $this->percent = $percent ?? (float) ($site->custom_discount_percent ?? 0);
        $this->endedAt = $endedAt ?? $site->custom_discount_ends_at;
        $this->dedupeKey = 'site-discount-ended:'.$site->id.':'.optional($this->endedAt)->timestamp;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your site discount has ended — '.$this->site->site_name,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.site-discount-ended',
            with: [
                'site' => $this->site,
                'publisher' => $this->recipientUser,
                'percent' => $this->percent,
                'endedAt' => $this->endedAt,
            ],
        );
    }
}
