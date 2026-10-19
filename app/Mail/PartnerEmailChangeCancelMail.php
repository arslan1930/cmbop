<?php

namespace App\Mail;

use App\Models\PartnerEmailChangeRequest;
use App\Models\User;
use App\Services\Partners\PartnerEmailChangeService;

class PartnerEmailChangeCancelMail extends PartnerDashboardMail
{
    public function __construct(
        User $recipient,
        public PartnerEmailChangeRequest $change,
        public string $plainToken,
    ) {
        parent::__construct($recipient);
        $this->notificationType = 'partner_email_change_cancel';
        $this->dedupeKey = 'partner-email-cancel-'.$change->id;
        $this->skipUserPreference = true;
    }

    public function build()
    {
        return $this->subject('A partner email change was requested on your account')
            ->markdown('emails.partner.email-change-cancel')
            ->with([
                'partnerName' => $this->recipientUser?->name ?: 'Partner',
                'newEmail' => $this->change->new_email,
                'actionUrl' => app(PartnerEmailChangeService::class)->cancelUrl($this->plainToken),
            ]);
    }
}
