<?php

namespace App\Mail;

use App\Models\BulkSiteRequest;
use App\Models\User;

class BulkSiteRequestSubmitted extends PlatformMailable
{
    public BulkSiteRequest $bulkRequest;

    public function __construct(BulkSiteRequest $bulkRequest, public ?string $openUrl = null, ?User $recipient = null)
    {
        parent::__construct();
        $this->bulkRequest = $bulkRequest;
        $this->recipientUser = $recipient;
        $this->notificationType = 'bulk_site_request_submitted';
        $this->skipUserPreference = true;
        $this->dedupeKey = 'bulk-request-'.$bulkRequest->id.':'.($recipient?->email ?: 'staff');
    }

    public function build()
    {
        $publisher = $this->bulkRequest->publisher;

        return $this->subject('Bulk site request from '.($publisher?->name ?? 'publisher'))
            ->markdown('emails.bulk-site-request-submitted')
            ->with([
                'bulkRequest' => $this->bulkRequest,
                'publisherName' => $publisher?->name ?? 'Unknown',
                'publisherEmail' => $publisher?->email ?? 'Unknown',
                'adminUrl' => $this->openUrl ?: route('admin.bulk-site-requests.show', $this->bulkRequest),
            ]);
    }
}
