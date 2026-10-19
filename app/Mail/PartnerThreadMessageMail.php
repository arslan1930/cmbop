<?php

namespace App\Mail;

use App\Models\OrderItem;
use App\Models\User;

class PartnerThreadMessageMail extends PartnerDashboardMail
{
    public function __construct(
        User $recipient,
        public OrderItem $item,
        public string $preview,
        public string $actionUrl,
    ) {
        parent::__construct($recipient);
        $this->notificationType = 'partner_thread_message';
        $this->dedupeKey = 'partner-thread-'.$item->id.'-'.md5($preview);
        $this->item->loadMissing(['order', 'site']);
    }

    public function build()
    {
        $number = $this->orderNumber($this->item);
        $domain = $this->siteDomain($this->item);

        return $this->subject('New message on order '.$number.' · '.$domain)
            ->markdown('emails.partner.thread-message')
            ->with([
                'partnerName' => $this->recipientUser?->name ?: 'Partner',
                'orderNumber' => $number,
                'domain' => $domain,
                'preview' => $this->preview,
                'actionUrl' => $this->openOrderUrl($this->actionUrl),
            ]);
    }
}
