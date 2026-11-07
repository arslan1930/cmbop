<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\User;
use App\Support\MailNotificationPresenter;

class OrderStatusChanged extends PlatformMailable
{
    public function __construct(
        public Order $order,
        public User $recipient,
        public string $audience, // advertiser|publisher|admin
        public string $changeKind, // status|payment_status|created
        public ?string $previousValue,
        public string $newValue,
        public ?string $description = null,
    ) {
        parent::__construct();
        $this->notificationType = 'order_status_changed';
        $this->recipientUser = $recipient;
    }

    protected function dedupeVariant(): ?string
    {
        return $this->audience.':'.$this->changeKind.':'.$this->newValue;
    }

    public function build()
    {
        $order = $this->order->loadMissing(['user', 'items.site.publisher']);
        $firstName = $this->firstName($this->recipient);
        $presenter = MailNotificationPresenter::class;

        $prevLabel = $this->previousValue
            ? $this->labelFor($this->previousValue)
            : '—';
        $newLabel = $this->labelFor($this->newValue);

        $advertiserCompleted = $this->audience === 'advertiser'
            && $this->changeKind === 'status'
            && $this->newValue === 'completed';

        $reviewReady = $presenter::isReviewReady($this->changeKind, $this->newValue, $order);
        $context = $this->summaryContext($reviewReady);
        $placements = $presenter::placements(
            $order,
            $context,
            $this->changeKind === 'status' ? $this->newValue : (string) $order->status
        );

        $subject = match (true) {
            $advertiserCompleted => 'Order #'.$order->order_number.' is now complete',
            $reviewReady => 'Ready for review — order #'.$order->order_number,
            $this->changeKind === 'created' => 'New order #'.$order->order_number.' created',
            $this->changeKind === 'payment_status' => 'Payment update for order #'.$order->order_number.' — '.$newLabel,
            default => 'Order #'.$order->order_number.' is now '.$newLabel,
        };

        [$ctaUrl, $ctaLabel] = $this->ctaForAudience();
        $greetingName = trim((string) ($this->recipient->name ?? ''));

        $copy = $this->bodyCopy($reviewReady, $newLabel, $placements);

        $heading = match (true) {
            $this->changeKind === 'created' => 'New order created',
            $this->changeKind === 'payment_status' => 'Payment status updated',
            $reviewReady => 'Ready for review',
            $this->newValue === 'review' => 'In review',
            default => 'Order is now '.$newLabel,
        };

        return $this->subject($subject)
            ->markdown('emails.orders.status-changed')
            ->with([
                'firstName' => $firstName,
                'greetingName' => $greetingName !== '' ? $greetingName : 'there',
                'advertiserCompleted' => $advertiserCompleted,
                'reviewReady' => $reviewReady,
                'catalogUrl' => $this->customerFacingRoute('advertiser.catalog'),
                'audience' => $this->audience,
                'changeKind' => $this->changeKind,
                'heading' => $heading,
                'order' => $order,
                'placements' => $placements,
                'summaryRows' => $presenter::summaryRows(
                    $order,
                    $this->audience,
                    $context,
                    $newLabel,
                    null,
                    $this->changeKind === 'status' ? $this->newValue : (string) $order->status
                ),
                'previousLabel' => $prevLabel,
                'newLabel' => $newLabel,
                'previousValue' => $this->previousValue,
                'newValue' => $this->newValue,
                'copy' => $copy,
                'ctaUrl' => $ctaUrl,
                'ctaLabel' => $ctaLabel,
                'updatedAt' => $presenter::formatDateTime(now()),
                'supportLine' => $presenter::supportLine('order'),
                'brand' => $this->brand(),
            ]);
    }

    protected function ctaForAudience(): array
    {
        return match ($this->audience) {
            'advertiser' => [
                $this->advertiserOrdersUrl((int) $this->order->id),
                'View Order Details',
            ],
            'publisher' => [
                $this->publisherTasksUrl((int) $this->order->id),
                'View Order Details',
            ],
            'admin' => [
                $this->publicRoute('admin.orders.show', $this->order->id),
                'View Order Details',
            ],
            default => [$this->publicRoute('home'), 'Open Platform'],
        };
    }

    protected function labelFor(string $value): string
    {
        if ($this->changeKind === 'payment_status') {
            return MailNotificationPresenter::paymentStatusLabel($value);
        }

        return MailNotificationPresenter::orderStatusLabel($value);
    }

    protected function summaryContext(bool $reviewReady): string
    {
        if ($reviewReady) {
            return MailNotificationPresenter::CONTEXT_REVIEW_READY;
        }

        return match ($this->changeKind) {
            'created' => MailNotificationPresenter::CONTEXT_CREATED,
            'payment_status' => MailNotificationPresenter::CONTEXT_PAYMENT,
            default => $this->newValue === 'completed'
                ? MailNotificationPresenter::CONTEXT_COMPLETED
                : MailNotificationPresenter::CONTEXT_STATUS,
        };
    }

    /**
     * @param  list<array{live_url?: ?string}>  $placements
     */
    protected function bodyCopy(bool $reviewReady, string $newLabel, array $placements): string
    {
        if ($reviewReady) {
            return 'The guest post is live and ready for your review. Please check the published article, then approve it or request corrections from the order page.';
        }

        if ($this->changeKind === 'status' && $this->newValue === 'review') {
            return 'This order is in review. A live article URL has not been submitted yet — open the order for the latest status.';
        }

        if (filled($this->description) && ! str_contains(strtolower($this->description), 'live url was submitted')) {
            return $this->description;
        }

        return match ($this->changeKind) {
            'created' => 'A new order has been created and is ready for tracking.',
            'payment_status' => "The payment status for this order is now {$newLabel}.",
            default => "The order status has been updated to {$newLabel}.",
        };
    }
}
