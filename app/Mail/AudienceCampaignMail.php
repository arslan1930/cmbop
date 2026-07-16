<?php

namespace App\Mail;

use App\Models\EmailCampaign;
use App\Models\User;

class AudienceCampaignMail extends PlatformMailable
{
    public function __construct(
        public EmailCampaign $campaign,
        public User $recipient,
    ) {
        parent::__construct();
        $this->notificationType = 'audience_campaign';
        $this->recipientUser = $recipient;
    }

    public function build()
    {
        return $this->subject($this->campaign->subject)
            ->markdown('emails.campaigns.audience')
            ->with([
                'firstName' => $this->firstName($this->recipient),
                'subject' => $this->campaign->subject,
                'bodyHtml' => $this->campaign->body_html,
                'ctaLabel' => $this->campaign->cta_label,
                'ctaUrl' => $this->campaign->cta_url,
                'brand' => $this->brand(),
            ]);
    }
}
