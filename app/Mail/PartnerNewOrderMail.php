<?php

namespace App\Mail;

use App\Models\OrderItem;
use App\Models\User;

class PartnerNewOrderMail extends PartnerDashboardMail
{
    public function __construct(
        User $recipient,
        public OrderItem $item,
        public string $actionUrl,
    ) {
        parent::__construct($recipient);
        $this->notificationType = 'partner_new_order';
        $this->dedupeKey = 'partner-new-order-'.$item->id;
        $this->item->loadMissing(['order', 'site']);
    }

    public function build()
    {
        $number = $this->orderNumber($this->item);
        $domain = $this->siteDomain($this->item);

        return $this->subject('New order '.$number.' on '.$domain)
            ->markdown('emails.partner.new-order')
            ->with([
                'partnerName' => $this->recipientUser?->name ?: 'Partner',
                'orderNumber' => $number,
                'domain' => $domain,
                'actionUrl' => $this->openOrderUrl($this->actionUrl),
                'fromName' => 'SEOLinkBuildings',
            ]);
    }
}
