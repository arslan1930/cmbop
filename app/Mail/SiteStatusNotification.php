<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Site;

class SiteStatusNotification extends Mailable
{
    use Queueable, SerializesModels;
    
    public $site;
    public $action;
    public $oldData;
    
    public function __construct(Site $site, $action, $oldData = null)
    {
        $this->site = $site;
        $this->action = $action;
        $this->oldData = $oldData;
    }
    
    public function build()
    {
        $subject = match($this->action) {
            'update' => 'Your Site Has Been Updated - ' . $this->site->site_name,
            'activated' => 'Your Site Has Been Activated - ' . $this->site->site_name,
            'deactivated' => 'Your Site Has Been Deactivated - ' . $this->site->site_name,
            'verified' => 'Your Site Has Been Verified - ' . $this->site->site_name,
            'unverified' => 'Your Site Verification Status Changed - ' . $this->site->site_name,
            default => 'Site Status Update - ' . $this->site->site_name,
        };
        
        return $this->subject($subject)
                    ->markdown('emails.site-status-notification')
                    ->with([
                        'site' => $this->site,
                        'action' => $this->action,
                        'oldData' => $this->oldData,
                    ]);
    }
}