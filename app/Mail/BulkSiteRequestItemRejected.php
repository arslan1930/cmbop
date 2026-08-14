<?php

namespace App\Mail;

use App\Models\BulkSiteRequest;
use App\Models\BulkSiteRequestItem;
use App\Models\User;

/**
 * Staff rejected one URL+price row from a publisher bulk request.
 */
class BulkSiteRequestItemRejected extends PlatformMailable
{
    public function __construct(
        public BulkSiteRequest $bulkRequest,
        public BulkSiteRequestItem $item,
        public User $publisher,
        public string $reason,
    ) {
        parent::__construct();

        $this->notificationType = 'bulk_request_item_rejected';
        $this->recipientUser = $publisher;
        $this->dedupeKey = 'bulk_request_item_rejected:'.$item->id;
    }

    public function build()
    {
        return $this->subject('One website was not added from your bulk request')
            ->markdown('emails.bulk-site-request-item-rejected', [
                'firstName' => $this->firstName($this->publisher),
                'bulkRequest' => $this->bulkRequest,
                'item' => $this->item,
                'reason' => $this->reason,
                'websitesUrl' => route('publisher.websites'),
            ]);
    }
}
