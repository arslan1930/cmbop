<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WelcomeEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user)
    {
    }

    public function build()
    {
        return $this->subject('Welcome to ' . config('app.name', 'SEOLinkBuildings'))
            ->markdown('emails.welcome')
            ->with([
                'user' => $this->user,
                'catalogUrl' => url('/advertiser/catalog'),
                'dashboardUrl' => url('/advertiser/dashboard'),
            ]);
    }
}
