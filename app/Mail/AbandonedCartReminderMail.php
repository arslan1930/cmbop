<?php

namespace App\Mail;

use App\Models\User;
use App\Support\EmailUnsubscribeLink;
use Illuminate\Mail\Mailables\Headers;
use InvalidArgumentException;

class AbandonedCartReminderMail extends PlatformMailable
{
    public const STAGE_1 = 1;

    public const STAGE_2 = 2;

    protected ?string $cachedUnsubscribeUrl = null;

    /**
     * @param  list<array{domain: string, quantity: int, price: float, was: ?float, discount: ?float}>  $lines
     */
    public function __construct(
        public User $user,
        public int $stage,
        public array $lines,
        public string $cartFingerprint = '',
    ) {
        parent::__construct();

        if (! in_array($stage, [self::STAGE_1, self::STAGE_2], true)) {
            throw new InvalidArgumentException('Invalid abandoned cart reminder stage: '.$stage);
        }

        $this->notificationType = 'abandoned_cart_reminder';
        $this->recipientUser = $user;
        $this->dedupeKey = 'abandoned_cart_reminder:'.$stage.':'.$user->id.':'.($cartFingerprint !== '' ? $cartFingerprint : 'none');
    }

    public function unsubscribeUrl(): string
    {
        return $this->cachedUnsubscribeUrl ??= EmailUnsubscribeLink::url($this->user);
    }

    public function build()
    {
        $cartUrl = $this->publicRoute('advertiser.catalog');

        $subject = $this->stage === self::STAGE_1
            ? 'You still have sites in your cart'
            : 'Your cart is still waiting — pick up where you left off';

        return $this->subject($subject)
            ->markdown('emails.advertiser.abandoned-cart-reminder')
            ->with([
                'firstName' => $this->firstName($this->user),
                'stage' => $this->stage,
                'lines' => $this->lines,
                'cartUrl' => $cartUrl,
                'brand' => $this->brand(),
                'unsubscribeUrl' => $this->unsubscribeUrl(),
            ]);
    }

    public function headers(): Headers
    {
        $url = $this->unsubscribeUrl();

        return new Headers(
            text: [
                'List-Unsubscribe' => '<'.$url.'>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
        );
    }
}
