<?php

namespace App\Mail;

use App\Models\SiteClaim;

class SiteClaimSubmitted extends PlatformMailable
{
    public SiteClaim $claim;

    public function __construct(SiteClaim $claim)
    {
        parent::__construct();
        $this->claim = $claim->loadMissing(['site', 'claimer']);
        $this->notificationType = 'site_claim_submitted';
        $this->skipUserPreference = true;
        // Per-recipient dedupeKey is set by SiteClaimTransferService::notifySubmitted
        // so every admin gets the mail (a shared claim-only key would suppress the rest).
    }

    public function build()
    {
        $siteName = $this->claim->site?->site_name ?: $this->claim->website_name;
        $claimer = $this->claim->claimer;

        return $this->subject('Site claim: '.$siteName)
            ->markdown('emails.site-claim-submitted')
            ->with([
                'claim' => $this->claim,
                'siteName' => $siteName,
                'claimerName' => $claimer?->name ?? 'Unknown',
                'claimerEmail' => $this->claim->contact_email ?: ($claimer?->email ?? 'Unknown'),
                'adminUrl' => $this->publicRoute('admin.community.index', ['tab' => 'claims', 'status' => 'pending']),
            ]);
    }
}
