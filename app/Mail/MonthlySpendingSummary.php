<?php

namespace App\Mail;

use App\Models\User;

class MonthlySpendingSummary extends PlatformMailable
{
    public function __construct(public User $user, public array $payload = [])
    {
        parent::__construct();
        $this->notificationType = 'monthly_spending_summary';
        $this->recipientUser = $user;
    }

    public function build()
    {
        $month = $this->payload['month_label'] ?? now()->format('F Y');

        return $this->subject('Your spending summary for ' . $month)
            ->markdown('emails.summaries.monthly-spending')
            ->with([
                'firstName' => $this->firstName($this->user),
                'payload' => $this->payload,
                'monthLabel' => $month,
                'ctaUrl' => url('/advertiser/analytics'),
                'ctaLabel' => 'Open Analytics',
                'brand' => $this->brand(),
            ]);
    }
}
