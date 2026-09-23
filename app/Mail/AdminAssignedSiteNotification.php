<?php

namespace App\Mail;

use App\Models\Site;
use App\Models\User;

class AdminAssignedSiteNotification extends PlatformMailable
{
    public Site $site;

    public function __construct(Site $site, ?User $recipient = null)
    {
        parent::__construct();
        $this->site = $site;
        $this->recipientUser = $recipient ?? $site->publisher;
        $this->notificationType = 'admin_assigned_site';
        $this->dedupeKey = 'admin-assigned-site-'.$site->id;
    }

    public function build()
    {
        $domain = $this->site->domain ?: $this->site->site_name;

        return $this->subject('Please accept a website we added for you')
            ->markdown('emails.admin-assigned-site')
            ->with([
                'site' => $this->site,
                'domain' => $domain,
                'publisherName' => $this->recipientUser->name ?? 'Publisher',
                'acceptUrl' => $this->publicRoute('publisher.websites', ['status' => 'invites']),
            ]);
    }
}
