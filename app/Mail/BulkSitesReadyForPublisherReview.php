<?php

namespace App\Mail;

use App\Models\BulkSiteRequest;
use App\Models\User;

class BulkSitesReadyForPublisherReview extends PlatformMailable
{
    public BulkSiteRequest $bulkRequest;

    public int $createdCount;

    /**
     * @param  list<string>  $domains
     */
    public function __construct(BulkSiteRequest $bulkRequest, int $createdCount, ?User $recipient = null, array $domains = [])
    {
        parent::__construct();
        $this->bulkRequest = $bulkRequest;
        $this->createdCount = $createdCount;
        $this->recipientUser = $recipient ?? $bulkRequest->publisher;
        $this->notificationType = 'bulk_sites_publisher_review';
        $sorted = $domains !== [] ? array_values(array_filter(array_map(
            static fn ($domain) => trim((string) $domain),
            $domains
        ))) : [(string) $createdCount];
        sort($sorted);
        $this->dedupeKey = 'bulk-publisher-review-'.$bulkRequest->id.':'.sha1(implode(',', $sorted));
    }

    public function build()
    {
        return $this->subject('Please review websites from your bulk request')
            ->markdown('emails.bulk-sites-publisher-review')
            ->with([
                'bulkRequest' => $this->bulkRequest,
                'createdCount' => $this->createdCount,
                'publisherName' => $this->bulkRequest->publisher?->name ?? 'Publisher',
                'reviewUrl' => route('publisher.bulk-sites.review'),
            ]);
    }
}
