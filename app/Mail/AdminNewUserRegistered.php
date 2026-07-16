<?php

namespace App\Mail;

use App\Models\User;

class AdminNewUserRegistered extends PlatformMailable
{
    public function __construct(public User $newUser, public ?User $admin = null)
    {
        parent::__construct();
        $this->notificationType = 'admin_new_user';
        $this->recipientUser = $admin;
    }

    public function build()
    {
        $first = $this->firstName($this->admin);
        $cta = url('/admin/users');

        return $this->subject('New user registered — ' . $this->newUser->name)
            ->markdown('emails.admin.new-user-registered')
            ->with([
                'adminFirstName' => $first,
                'newUser' => $this->newUser,
                'ctaUrl' => $cta,
                'ctaLabel' => 'View Users',
                'brand' => $this->brand(),
            ]);
    }
}
