<?php

namespace App\Mail;

use App\Models\PartnerEmailChangeRequest;
use App\Models\User;
use App\Services\Partners\PartnerEmailChangeService;

class PartnerEmailChangeConfirmMail extends PartnerDashboardMail
{
    public function __construct(
        User $recipient,
        public PartnerEmailChangeRequest $change,
        public string $plainToken,
    ) {
        parent::__construct($recipient);
        $this->notificationType = 'partner_email_change_confirm';
        $this->dedupeKey = 'partner-email-confirm-'.$change->id;
        $this->skipUserPreference = true;
    }

    public function build()
    {
        return $this->subject('Confirm your new SEOLinkBuildings partner email')
            ->markdown('emails.partner.email-change-confirm')
            ->with([
                'partnerName' => $this->recipientUser?->name ?: 'Partner',
                'newEmail' => $this->change->new_email,
                'actionUrl' => app(PartnerEmailChangeService::class)->confirmUrl($this->plainToken),
            ]);
    }
}
