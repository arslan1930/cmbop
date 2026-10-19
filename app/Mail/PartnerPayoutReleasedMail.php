<?php

namespace App\Mail;

use App\Models\User;
use App\Models\Withdrawal;

class PartnerPayoutReleasedMail extends PartnerDashboardMail
{
    public function __construct(
        User $recipient,
        public Withdrawal $withdrawal,
    ) {
        parent::__construct($recipient);
        $this->notificationType = 'partner_payout_released';
        $this->dedupeKey = 'partner-payout-'.$withdrawal->id;
        $this->skipUserPreference = true;
    }

    public function build()
    {
        $amount = number_format((float) ($this->withdrawal->net_amount ?? $this->withdrawal->amount), 2);
        $currency = strtoupper((string) ($this->withdrawal->currency ?: 'EUR'));
        $label = $currency === 'EUR' ? '€'.$amount : $currency.' '.$amount;

        return $this->subject('Payout released: '.$label)
            ->markdown('emails.partner.payout-released')
            ->with([
                'partnerName' => $this->recipientUser?->name ?: 'Partner',
                'amountLabel' => $label,
                'actionUrl' => $this->loginUrl(),
                'moneyUrl' => $this->moneyUrl(),
            ]);
    }
}
