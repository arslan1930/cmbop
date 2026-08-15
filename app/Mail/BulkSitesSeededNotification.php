<?php

namespace App\Mail;

use App\Models\BulkSiteRequest;
use App\Models\User;

class BulkSitesSeededNotification extends PlatformMailable
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
        $this->notificationType = 'bulk_sites_seeded';
        $sorted = $domains !== [] ? array_values(array_filter(array_map(
            static fn ($domain) => trim((string) $domain),
            $domains
        ))) : [(string) $createdCount];
        sort($sorted);
        // Count alone collided when staff Done'd two same-size batches
        // within the dedupe window — the publisher never heard about the second.
        $this->dedupeKey = 'bulk-seeded-'.$bulkRequest->id.':'.sha1(implode(',', $sorted));
    }

    public function build()
    {
        return $this->subject('Your sites were added to Pending sites')
            ->markdown('emails.bulk-sites-seeded')
            ->with([
                'bulkRequest' => $this->bulkRequest,
                'createdCount' => $this->createdCount,
                'publisherName' => $this->recipientUser?->name
                    ?? $this->bulkRequest->publisher?->name
                    ?? 'Publisher',
                'completeUrl' => route('publisher.websites'),
            ]);
    }
}
