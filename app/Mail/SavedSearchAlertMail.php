<?php

namespace App\Mail;

use App\Models\SavedSearch;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Collection;

class SavedSearchAlertMail extends PlatformMailable
{
    /**
     * @param  Collection<int, Site>  $sites
     */
    public function __construct(
        public User $advertiser,
        public SavedSearch $search,
        public Collection $sites,
    ) {
        parent::__construct();

        $this->notificationType = 'saved_search_alert';
        $this->recipientUser = $advertiser;
        $this->dedupeKey = 'saved_search_alert:'.$search->id.':'.now()->toDateString();
    }

    public function build()
    {
        $names = $this->sites->map(fn ($site) => $site->site_name ?: 'Listing')->take(12)->values();

        return $this->subject('New sites match “'.$this->search->name.'”')
            ->markdown('emails.advertiser.saved-search-alert', [
                'firstName' => $this->firstName($this->advertiser),
                'searchName' => $this->search->name,
                'siteNames' => $names,
                'catalogUrl' => $this->search->catalogUrl(),
                'savedSearchesUrl' => route('advertiser.saved-searches'),
                'brand' => $this->brand(),
            ]);
    }
}
