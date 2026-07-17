<?php

namespace App\Mail;

use App\Models\User;

class WeeklyActivitySummary extends PlatformMailable
{
    public function __construct(public User $user, public array $payload = [])
    {
        parent::__construct();
        $this->notificationType = 'weekly_activity_summary';
        $this->recipientUser = $user;
    }

    public function build()
    {
        return $this->subject('Your weekly activity summary')
            ->markdown('emails.summaries.weekly-activity')
            ->with([
                'firstName' => $this->firstName($this->user),
                'payload' => $this->payload,
                'ctaUrl' => url('/advertiser/analytics'),
                'ctaLabel' => 'View Spending History',
                'brand' => $this->brand(),
            ]);
    }
}
