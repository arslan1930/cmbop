<?php

namespace App\Mail;

use App\Models\BulkSiteRequest;
use App\Models\User;

class BulkSiteRequestPublisherReminder extends PlatformMailable
{
    public function __construct(
        public BulkSiteRequest $bulkRequest,
        public User $publisher,
        public bool $automatic = false,
    ) {
        parent::__construct();

        $this->notificationType = 'bulk_request_publisher_reminder';
        $this->recipientUser = $publisher;
        $this->dedupeKey = 'bulk_request_publisher_reminder:'.$bulkRequest->id.':'.now()->format('Y-m-d');
    }

    public function build()
    {
        $count = max(
            $this->bulkRequest->cachedPendingPublisherCount(),
            $this->bulkRequest->sitesCountCached(),
            1
        );

        return $this->subject('Reminder: websites from your bulk request still need you')
            ->markdown('emails.bulk-site-request-publisher-reminder', [
                'publisherName' => $this->publisher->name ?? 'Publisher',
                'bulkRequest' => $this->bulkRequest,
                'nextStep' => $this->bulkRequest->nextStepText(),
                'count' => $count,
                'reviewUrl' => $this->publicRoute('publisher.bulk-sites.review'),
                'websitesUrl' => $this->publicRoute('publisher.websites'),
            ]);
    }
}
