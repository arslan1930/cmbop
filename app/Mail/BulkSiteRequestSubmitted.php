<?php

namespace App\Mail;

use App\Models\BulkSiteRequest;

class BulkSiteRequestSubmitted extends PlatformMailable
{
    public BulkSiteRequest $bulkRequest;

    public function __construct(BulkSiteRequest $bulkRequest)
    {
        parent::__construct();
        $this->bulkRequest = $bulkRequest;
        $this->notificationType = 'bulk_site_request_submitted';
        $this->skipUserPreference = true;
        $this->dedupeKey = 'bulk-request-'.$bulkRequest->id;
    }

    public function build()
    {
        $publisher = $this->bulkRequest->publisher;

        return $this->subject('Bulk site request from '.($publisher->name ?? 'publisher'))
            ->markdown('emails.bulk-site-request-submitted')
            ->with([
                'bulkRequest' => $this->bulkRequest,
                'publisherName' => $publisher->name ?? 'Unknown',
                'publisherEmail' => $publisher->email ?? 'Unknown',
                'adminUrl' => route('admin.bulk-site-requests.show', $this->bulkRequest),
            ]);
    }
}
