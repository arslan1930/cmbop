<?php

namespace App\Mail;

use App\Models\User;
use App\Support\PartnerDashboard;

abstract class PartnerDashboardMail extends PlatformMailable
{
    public function __construct(?User $recipient = null)
    {
        parent::__construct();
        $this->recipientUser = $recipient;
        $this->skipUserPreference = true;
    }

    protected function openOrderUrl(string $url): string
    {
        return $url !== '' ? $url : PartnerDashboard::origin().'/';
    }

    protected function loginUrl(): string
    {
        return PartnerDashboard::origin().'/login';
    }

    protected function moneyUrl(): string
    {
        return PartnerDashboard::origin().'/money';
    }

    protected function siteDomain($item): string
    {
        return (string) ($item->site?->domain ?: $item->site_name ?: $item->site_url ?: 'your site');
    }

    protected function orderNumber($item): string
    {
        return (string) ($item->order?->order_number ?? $item->id);
    }
}
