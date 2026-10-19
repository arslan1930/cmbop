<?php

namespace App\Mail;

use App\Models\OrderItem;
use App\Models\User;

class PartnerCorrectionMail extends PartnerDashboardMail
{
    public function __construct(
        User $recipient,
        public OrderItem $item,
        public string $reason,
        public string $actionUrl,
    ) {
        parent::__construct($recipient);
        $this->notificationType = 'partner_correction';
        $this->dedupeKey = 'partner-correction-'.$item->id.'-'.md5($reason);
        $this->item->loadMissing(['order', 'site']);
    }

    public function build()
    {
        $number = $this->orderNumber($this->item);
        $domain = $this->siteDomain($this->item);

        return $this->subject('Correction requested for order '.$number.' on '.$domain)
            ->markdown('emails.partner.correction')
            ->with([
                'partnerName' => $this->recipientUser?->name ?: 'Partner',
                'orderNumber' => $number,
                'domain' => $domain,
                'reason' => mb_substr(trim($this->reason), 0, 140),
                'actionUrl' => $this->openOrderUrl($this->actionUrl),
            ]);
    }
}
