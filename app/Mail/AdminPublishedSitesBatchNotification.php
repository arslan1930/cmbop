<?php

namespace App\Mail;

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AdminPublishedSitesBatchNotification extends PlatformMailable
{
    /** @var Collection<int, Site> */
    public Collection $sites;

    /**
     * @param  iterable<int, Site>  $sites
     */
    public function __construct(User $publisher, iterable $sites)
    {
        parent::__construct();
        $this->sites = collect($sites)->values();
        $this->recipientUser = $publisher;
        $this->notificationType = 'admin_published_site';
        $this->dedupeKey = 'admin-published-sites-batch-'.$publisher->id.'-'.Str::uuid();
    }

    public function build()
    {
        $domains = $this->sites
            ->map(fn (Site $site) => $site->domain ?: $site->site_name)
            ->filter()
            ->values();
        $shown = $domains->take(12);
        $extra = max(0, $domains->count() - $shown->count());

        return $this->subject('Websites are live on your account')
            ->markdown('emails.admin-published-sites-batch')
            ->with([
                'count' => $this->sites->count(),
                'domains' => $shown->all(),
                'extra' => $extra,
                'publisherName' => $this->recipientUser->name ?? 'Publisher',
                'sitesUrl' => $this->publicRoute('publisher.websites', ['status' => 'active']),
            ]);
    }
}
