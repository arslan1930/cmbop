<?php

// app/Mail/NewSiteNotification.php

namespace App\Mail;

use App\Models\Site;
use App\Models\User;

class NewSiteNotification extends PlatformMailable
{
    public $site;

    public $action;

    public function __construct(Site $site, $action = 'create', public ?string $openUrl = null)
    {
        parent::__construct();
        $this->site = $site;
        $this->action = $action;
    }

    /**
     * Needs-review queue URL for this staff member's active workspace.
     */
    public static function reviewUrl(Site $site, ?User $staff = null): string
    {
        $publisherId = (int) ($site->publisher_id ?? 0);

        $path = route(staff_route_prefix_for($staff).'sites.index', array_filter([
            'needs_review' => 1,
            'publisher' => $publisherId > 0 ? $publisherId : null,
            'site' => $site->id,
        ]), false);

        return rtrim(app_public_url(), '/').$path;
    }

    public function build()
    {
        $subject = $this->action === 'create'
            ? 'New Site Submitted for Review'
            : 'Site Updated - Requires Review';

        $this->site->loadMissing('publisher');

        return $this->subject($subject)
            ->markdown('emails.new-site-notification')
            ->with([
                'siteName' => $this->site->site_name,
                'siteUrl' => $this->site->site_url,
                'publisherName' => $this->site->publisher?->name ?? 'Unknown',
                'publisherEmail' => $this->site->publisher?->email ?? 'Unknown',
                'action' => $this->action,
                'adminUrl' => $this->openUrl ?: self::reviewUrl($this->site),
            ]);
    }
}
