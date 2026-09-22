<?php

namespace App\Mail;

use App\Models\Site;
use App\Models\User;

class PublisherListingNudge extends PlatformMailable
{
    public Site $site;

    public string $kind;

    public function __construct(Site $site, string $kind = 'details', ?User $recipient = null)
    {
        parent::__construct();
        $this->site = $site;
        $this->kind = $kind === 'accept' ? 'accept' : 'details';
        $this->recipientUser = $recipient ?? $site->publisher;
        $this->notificationType = 'publisher_listing_nudge';
        $this->dedupeKey = 'publisher-listing-nudge:'.$site->id.':'.$this->kind.':'.now()->toDateString();
    }

    public function build()
    {
        $domain = $this->site->domain ?: $this->site->site_name ?: 'your listing';
        $publisherName = $this->recipientUser->name ?? 'Publisher';
        $ctaUrl = $this->kind === 'accept'
            ? route('publisher.websites', ['status' => 'invites'])
            : route('publisher.websites');

        return $this->subject('Reminder — finish your listing: '.$domain)
            ->markdown('emails.publisher-listing-nudge')
            ->with([
                'site' => $this->site,
                'kind' => $this->kind,
                'domain' => $domain,
                'publisherName' => $publisherName,
                'ctaUrl' => $ctaUrl,
            ]);
    }
}
