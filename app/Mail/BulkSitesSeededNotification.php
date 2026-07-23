<?php

namespace App\Mail;

use App\Models\BulkSiteRequest;
use App\Models\User;

class BulkSitesSeededNotification extends PlatformMailable
{
    public BulkSiteRequest $bulkRequest;

    public int $createdCount;

    public function __construct(BulkSiteRequest $bulkRequest, int $createdCount, ?User $recipient = null)
    {
        parent::__construct();
        $this->bulkRequest = $bulkRequest;
        $this->createdCount = $createdCount;
        $this->recipientUser = $recipient ?? $bulkRequest->publisher;
        $this->notificationType = 'bulk_sites_seeded';
        $this->dedupeKey = 'bulk-seeded-'.$bulkRequest->id.'-'.$createdCount;
    }

    public function build()
    {
        return $this->subject('Complete details for your '.$this->createdCount.' website(s)')
            ->markdown('emails.bulk-sites-seeded')
            ->with([
                'bulkRequest' => $this->bulkRequest,
                'createdCount' => $this->createdCount,
                'publisherName' => $this->bulkRequest->publisher->name ?? 'Publisher',
                'completeUrl' => route('publisher.bulk-sites.complete'),
            ]);
    }
}
