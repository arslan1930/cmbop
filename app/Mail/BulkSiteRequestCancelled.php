<?php

namespace App\Mail;

use App\Models\BulkSiteRequest;
use App\Models\User;

/**
 * Staff cancelled a publisher's batch of site submissions.
 *
 * Cancelling used to be silent, so the request just disappeared from the
 * publisher's queue — indistinguishable from the platform losing their work.
 */
class BulkSiteRequestCancelled extends PlatformMailable
{
    public function __construct(
        public BulkSiteRequest $bulkRequest,
        public User $publisher,
        public ?string $reason = null,
    ) {
        parent::__construct();

        $this->notificationType = 'bulk_request_cancelled';
        $this->recipientUser = $publisher;
        $this->dedupeKey = 'bulk_request_cancelled:'.$bulkRequest->id;
    }

    public function build()
    {
        return $this->subject('Your bulk website request was cancelled')
            ->markdown('emails.bulk-site-request-cancelled', [
                'firstName' => $this->firstName($this->publisher),
                'bulkRequest' => $this->bulkRequest,
                'reason' => filled($this->reason) ? trim((string) $this->reason) : null,
                'count' => (int) ($this->bulkRequest->estimated_count ?: 0),
                'websitesUrl' => $this->publicRoute('publisher.websites'),
                'brand' => $this->brand(),
            ]);
    }
}
