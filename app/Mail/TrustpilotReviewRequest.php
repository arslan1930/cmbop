<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TrustpilotReviewRequest extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public ?Order $order = null)
    {
    }

    public function build()
    {
        $reviewUrl = config('services.trustpilot.review_url', 'https://www.trustpilot.com');

        return $this->subject('How was your experience with ' . config('app.name', 'SEOLinkBuildings') . '?')
            ->markdown('emails.trustpilot-review')
            ->with([
                'user' => $this->user,
                'order' => $this->order,
                'reviewUrl' => $reviewUrl,
            ]);
    }
}
