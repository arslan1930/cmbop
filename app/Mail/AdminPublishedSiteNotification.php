<?php

namespace App\Mail;

use App\Models\Site;
use App\Models\User;

class AdminPublishedSiteNotification extends PlatformMailable
{
    public Site $site;

    public function __construct(Site $site, ?User $recipient = null)
    {
        parent::__construct();
        $this->site = $site;
        $this->recipientUser = $recipient ?? $site->publisher;
        $this->notificationType = 'admin_published_site';
        $this->dedupeKey = 'admin-published-site-'.$site->id;
    }

    public function build()
    {
        $domain = $this->site->domain ?: $this->site->site_name;

        return $this->subject('A website is live on your account')
            ->markdown('emails.admin-published-site')
            ->with([
                'site' => $this->site,
                'domain' => $domain,
                'publisherName' => $this->recipientUser->name ?? 'Publisher',
                'sitesUrl' => $this->publicRoute('publisher.websites', ['status' => 'active']),
            ]);
    }
}
