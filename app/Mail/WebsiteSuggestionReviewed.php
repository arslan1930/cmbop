<?php

namespace App\Mail;

use App\Models\WebsiteSuggestion;

class WebsiteSuggestionReviewed extends PlatformMailable
{
    public function __construct(public WebsiteSuggestion $suggestion)
    {
        parent::__construct();
        $this->suggestion->loadMissing(['user']);
        $this->notificationType = 'website_suggestion_reviewed';
        $this->recipientUser = $this->suggestion->user;
        $this->skipUserPreference = $this->recipientUser === null;
        $this->dedupeKey = 'website-suggestion-reviewed-'.$this->suggestion->id.'-'.$this->suggestion->status;
    }

    public function build()
    {
        $accepted = $this->suggestion->status === 'accepted';
        $name = $this->suggestion->website_name ?: ($this->suggestion->domain ?: 'the website');

        return $this->subject(
            ($accepted ? 'Website suggestion accepted' : 'Website suggestion update').': '.$name
        )
            ->markdown('emails.website-suggestion-reviewed')
            ->with([
                'suggestion' => $this->suggestion,
                'accepted' => $accepted,
                'siteName' => $name,
                'notes' => trim((string) ($this->suggestion->admin_notes ?? '')),
                'actionUrl' => $this->publicRoute('advertiser.catalog'),
            ]);
    }
}
