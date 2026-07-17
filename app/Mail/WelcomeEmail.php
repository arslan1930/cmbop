<?php

namespace App\Mail;

use App\Models\User;

class WelcomeEmail extends PlatformMailable
{
    public function __construct(public User $user)
    {
        parent::__construct();
        $this->notificationType = 'welcome';
        $this->recipientUser = $user;
    }

    public function build()
    {
        $needsVerification = ! $this->user->hasVerifiedEmail();

        return $this->subject('Welcome to '.config('app.name', 'SEOLinkBuildings'))
            ->markdown('emails.welcome')
            ->with([
                'user' => $this->user,
                'firstName' => $this->firstName($this->user),
                'catalogUrl' => url('/advertiser/catalog'),
                'dashboardUrl' => url('/advertiser/dashboard'),
                'ctaUrl' => $needsVerification ? url('/email/verify') : url('/advertiser/catalog'),
                'ctaLabel' => $needsVerification ? 'Verify your email' : 'Browse Websites',
                'needsVerification' => $needsVerification,
                'loginUrl' => url('/login'),
                'brand' => $this->brand(),
            ]);
    }
}
