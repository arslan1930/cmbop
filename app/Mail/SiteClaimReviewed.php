<?php

namespace App\Mail;

use App\Models\SiteClaim;

class SiteClaimReviewed extends PlatformMailable
{
    public SiteClaim $claim;

    public function __construct(SiteClaim $claim)
    {
        parent::__construct();
        $this->claim = $claim->loadMissing(['site', 'claimer']);
        $this->notificationType = 'site_claim_reviewed';
        $this->recipientUser = $claim->claimer;
        $this->dedupeKey = 'site-claim-reviewed-'.$claim->id.'-'.$claim->status;
    }

    public function build()
    {
        $approved = $this->claim->status === 'approved';
        $claimer = $this->claim->claimer;
        $siteName = (string) ($this->claim->website_name ?: 'your claim');
        if ($claimer && ! $claimer->inCatalogHideMode() && $this->claim->site?->site_name) {
            $siteName = (string) $this->claim->site->site_name;
        }

        return $this->subject(
            ($approved ? 'Claim approved' : 'Claim update').': '.$siteName
        )
            ->markdown('emails.site-claim-reviewed')
            ->with([
                'claim' => $this->claim,
                'approved' => $approved,
                'siteName' => $siteName,
                'actionUrl' => $approved
                    ? $this->publicRoute('publisher.websites')
                    : ($claimer?->hasRole('advertiser')
                        ? $this->publicRoute('advertiser.site-claims')
                        : $this->publicRoute('site-claims.index')),
            ]);
    }
}
