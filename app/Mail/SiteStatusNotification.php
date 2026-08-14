<?php

namespace App\Mail;

use App\Models\Site;

class SiteStatusNotification extends PlatformMailable
{
    public $site;

    public $action;

    public $oldData;

    public ?string $reason;

    public function __construct(Site $site, $action, $oldData = null, ?string $reason = null)
    {
        parent::__construct();
        $this->site = $site;
        $this->action = $action;
        $this->oldData = $oldData;
        $this->reason = $reason ? trim($reason) : null;
        if ($this->reason === '') {
            $this->reason = null;
        }
        $this->notificationType = 'site_status';
        $this->recipientUser = $site->publisher;
    }

    protected function dedupeVariant(): ?string
    {
        return (string) $this->action;
    }

    public function build()
    {
        $subject = match ($this->action) {
            'update' => 'Your Site Has Been Updated - '.$this->site->site_name,
            'activated' => 'Your Site Has Been Activated - '.$this->site->site_name,
            'deactivated' => 'Your Site Has Been Deactivated - '.$this->site->site_name,
            'verified' => 'Your Site Has Been Verified - '.$this->site->site_name,
            'unverified' => 'Your Site Verification Status Changed - '.$this->site->site_name,
            'removed' => 'Your Site Submission Was Not Accepted - '.$this->site->site_name,
            'archived' => 'Your Site Was Archived - '.$this->site->site_name,
            default => 'Site Status Update - '.$this->site->site_name,
        };

        return $this->subject($subject)
            ->markdown('emails.site-status-notification')
            ->with([
                'site' => $this->site,
                'action' => $this->action,
                'oldData' => $this->oldData,
                'reason' => $this->reason,
            ]);
    }
}
