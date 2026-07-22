<?php

namespace App\Mail;

use App\Models\User;

class PayoutProfileUpdatedBySupport extends PlatformMailable
{
    public ?string $notificationType = 'payout_profile_updated';

    public function __construct(
        public User $user,
        public string $method,
    ) {
        parent::__construct();
        $this->dedupeKey = 'payout-profile-updated:'.$user->id.':'.$method.':'.now()->format('YmdHi');
    }

    public function build()
    {
        return $this->subject('Your payout details were updated')
            ->markdown('emails.publisher.payout-profile-updated')
            ->with([
                'userName' => $this->user->name,
                'method' => $this->method,
                'supportEmail' => config('email_notifications.brand.support_email', config('mail.from.address')),
            ]);
    }
}
