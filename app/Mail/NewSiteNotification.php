<?php
// app/Mail/NewSiteNotification.php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Site;
use App\Models\Country;
use App\Models\Language;

class NewSiteNotification extends Mailable
{
    use Queueable, SerializesModels;
    
    public $site;
    public $action;
    
    public function __construct(Site $site, $action = 'create')
    {
        $this->site = $site;
        $this->action = $action;
    }
    
    public function build()
    {
        $subject = $this->action === 'create' 
            ? 'New Site Submitted for Review' 
            : 'Site Updated - Requires Review';
            
        return $this->subject($subject)
                    ->markdown('emails.new-site-notification')
                    ->with([
                        'siteName' => $this->site->site_name,
                        'siteUrl' => $this->site->site_url,
                        'publisherName' => $this->site->publisher->name ?? 'Unknown',
                        'publisherEmail' => $this->site->publisher->email ?? 'Unknown',
                        'action' => $this->action,
                        'adminUrl' => url('/admin/sites/' . $this->site->id . '/review'),
                    ]);
    }
}