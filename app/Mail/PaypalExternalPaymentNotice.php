<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\User;

class PaypalExternalPaymentNotice extends PlatformMailable
{
    public const AUDIENCE_ADVERTISER = 'advertiser';

    public const AUDIENCE_PUBLISHER = 'publisher';

    public const KIND_COMPLETED_REFUND = 'completed_refund';

    public const KIND_PARTIAL_REFUND = 'partial_refund';

    public const KIND_REVERSED = 'reversed';

    public const KIND_DISPUTE_CREATED = 'dispute_created';

    public const KIND_DISPUTE_RESOLVED = 'dispute_resolved';

    public function __construct(
        public User $user,
        public string $audience,
        public string $kind,
        public string $referenceCode,
        public float $amount = 0.0,
        public ?Order $order = null,
        public string $eventKey = '',
    ) {
        parent::__construct();

        $this->notificationType = 'paypal_external_payment_notice';
        $this->recipientUser = $user;
        $this->skipUserPreference = $audience === 'publisher';
        $orderId = (int) ($order?->id ?? 0);
        $suffix = $eventKey !== '' ? $eventKey : $kind;
        $this->dedupeKey = 'paypal_external:'.$kind.':'.$orderId.':'.$suffix.':'.$user->id;
    }

    public function build()
    {
        $amount = $this->amount >= 0.01 ? '€'.number_format($this->amount, 2) : 'a PayPal payment';
        $orderLabel = $this->order?->order_number
            ? '#'.$this->order->order_number
            : ($this->referenceCode !== '' ? 'REF '.$this->referenceCode : 'this order');

        $subject = match ($this->kind) {
            self::KIND_PARTIAL_REFUND => 'Partial PayPal refund on '.$orderLabel,
            self::KIND_REVERSED => 'PayPal reversed '.$orderLabel,
            self::KIND_DISPUTE_CREATED => 'PayPal buyer dispute on '.$orderLabel,
            self::KIND_DISPUTE_RESOLVED => 'PayPal dispute update on '.$orderLabel,
            default => 'PayPal refunded a completed order '.$orderLabel,
        };

        $cta = $this->audience === self::AUDIENCE_PUBLISHER
            ? $this->publicRoute('publisher.tasks')
            : $this->publicRoute('advertiser.orders', $this->order?->id ? [
                'focus' => 'order',
                'order' => $this->order->id,
            ] : []);

        return $this->subject($subject)
            ->markdown('emails.billing.paypal-external-payment-notice', [
                'user' => $this->user,
                'audience' => $this->audience,
                'kind' => $this->kind,
                'amountLabel' => $amount,
                'orderLabel' => $orderLabel,
                'referenceCode' => $this->referenceCode,
                'ctaUrl' => $cta,
            ]);
    }
}
