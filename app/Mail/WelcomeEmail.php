<?php

namespace App\Mail;

use App\Models\User;
use App\Notifications\VerifyEmail;
use App\Support\EmailCatalog;

class WelcomeEmail extends PlatformMailable
{
    public function __construct(public User $user)
    {
        parent::__construct();
        $this->notificationType = 'welcome';
        $this->recipientUser = $user;
    }

    public function build()
    {
        $this->user->loadMissing('roles');

        $workspace = $this->workspaceRole();
        $needsVerification = ! $this->user->hasVerifiedEmail();
        $catalogUrl = $this->publicRoute('advertiser.catalog');
        $publisherSitesUrl = $this->publicRoute('publisher.websites');
        $dashboardUrl = $workspace === 'publisher'
            ? $this->publicRoute('publisher.dashboard')
            : $this->publicRoute('advertiser.dashboard');

        // Must be the signed /email/verify/{id}/{hash} URL — NOT /email/verify
        // (that notice route requires auth and never verifies the account).
        if ($needsVerification) {
            $ctaUrl = EmailCatalog::isPreviewUser($this->user)
                ? EmailCatalog::previewVerificationUrl()
                : VerifyEmail::signedUrlFor($this->user);
            $ctaLabel = 'Click to verify';
        } elseif ($workspace === 'publisher') {
            $ctaUrl = $publisherSitesUrl;
            $ctaLabel = 'Add your first website';
        } else {
            $ctaUrl = $catalogUrl;
            $ctaLabel = 'Browse Websites';
        }

        $welcomeCredit = $this->mentionableWelcomeCredit($this->user);

        return $this->subject('Welcome to '.config('app.name', 'SEOLinkBuildings'))
            ->markdown('emails.welcome')
            ->with([
                'user' => $this->user,
                'firstName' => $this->firstName($this->user),
                'workspace' => $workspace,
                'catalogUrl' => $catalogUrl,
                'dashboardUrl' => $dashboardUrl,
                'ctaUrl' => $ctaUrl,
                'ctaLabel' => $ctaLabel,
                'needsVerification' => $needsVerification,
                'welcomeBonusEuro' => $welcomeCredit['euro'],
                'loginUrl' => $this->publicRoute('login'),
                'brand' => $this->brand(),
            ]);
    }

    /**
     * Starting workspace for welcome copy/CTA.
     *
     * Prefers active_role_id (registration starting workspace), then falls back
     * to the first attached role. Unknown roles default to advertiser copy.
     */
    protected function workspaceRole(): string
    {
        if (EmailCatalog::isPreviewUser($this->user)) {
            $preview = strtolower((string) $this->user->getAttribute('preview_workspace'));

            return $preview === 'publisher' ? 'publisher' : 'advertiser';
        }

        $active = strtolower((string) ($this->user->activeRole() ?? ''));

        if (in_array($active, ['publisher', 'advertiser'], true)) {
            return $active;
        }

        $names = $this->user->roles->pluck('name')->map(fn ($n) => strtolower((string) $n));

        if ($names->contains('publisher') && ! $names->contains('advertiser')) {
            return 'publisher';
        }

        return 'advertiser';
    }
}
