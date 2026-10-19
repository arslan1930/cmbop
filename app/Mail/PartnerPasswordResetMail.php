<?php

namespace App\Mail;

use App\Models\User;
use App\Support\PartnerDashboard;

class PartnerPasswordResetMail extends PartnerDashboardMail
{
    public function __construct(
        User $recipient,
        public string $token,
    ) {
        parent::__construct($recipient);
        $this->notificationType = 'partner_password_reset';
        $this->dedupeKey = 'partner-password-reset-'.$recipient->id.'-'.sha1($token);
        $this->skipUserPreference = true;
    }

    public function build()
    {
        $resetUrl = PartnerDashboard::origin().'/reset-password/'.$this->token.'?email='.urlencode((string) $this->recipientUser?->email);

        return $this->subject('Reset your SEOLinkBuildings partner password')
            ->markdown('emails.partner.password-reset')
            ->with([
                'partnerName' => $this->recipientUser?->name ?: 'Partner',
                'actionUrl' => $resetUrl,
            ]);
    }
}
