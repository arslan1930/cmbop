<?php

namespace App\Mail;

use App\Models\BulkSiteRequest;
use App\Models\User;

/**
 * Staff removed some pending URL+price rows from a bulk request on Done.
 *
 * One mail per submit (not per domain) so the publisher gets a single note
 * covering every site dropped in that action.
 */
class BulkSiteItemsRejected extends PlatformMailable
{
    /**
     * @param  list<string>  $domains
     * @param  list<int>  $itemIds
     */
    public function __construct(
        public BulkSiteRequest $bulkRequest,
        public User $publisher,
        public array $domains,
        public string $note,
        array $itemIds = [],
    ) {
        parent::__construct();

        $this->notificationType = 'bulk_request_items_rejected';
        $this->recipientUser = $publisher;
        $sorted = $itemIds !== [] ? $itemIds : $domains;
        sort($sorted);
        $this->dedupeKey = 'bulk_request_items_rejected:'.$bulkRequest->id.':'.sha1(implode(',', $sorted));
    }

    public function build()
    {
        $count = count($this->domains);

        return $this->subject(
            $count === 1
                ? 'We did not add a site from bulk request #'.$this->bulkRequest->id
                : 'We did not add some sites from bulk request #'.$this->bulkRequest->id
        )->markdown('emails.bulk-site-items-rejected', [
            'firstName' => $this->firstName($this->publisher),
            'bulkRequest' => $this->bulkRequest,
            'domains' => $this->domains,
            'note' => trim($this->note),
            'count' => $count,
            'websitesUrl' => $this->publicRoute('publisher.websites'),
            'brand' => $this->brand(),
        ]);
    }
}
