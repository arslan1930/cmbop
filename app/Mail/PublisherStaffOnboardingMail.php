<?php

namespace App\Mail;

use App\Models\Site;
use App\Models\User;
use App\Support\EmailCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\URL;

class PublisherStaffOnboardingMail extends PlatformMailable
{
    /** @var Collection<int, Site> */
    public Collection $sites;

    public function __construct(User $recipient, array|Collection $sites = [])
    {
        parent::__construct();
        $this->recipientUser = $recipient;
        $this->notificationType = 'publisher_staff_onboarding';
        $this->sites = collect($sites)->filter(fn ($site) => $site instanceof Site)->values();
        $this->dedupeKey = 'publisher-staff-onboarding-'.$recipient->id.'-'.($this->sites->pluck('id')->sort()->implode('-') ?: 'none');
        $this->skipUserPreference = true;
    }

    public function build()
    {
        $locale = $this->recipientLocale();
        $previous = App::getLocale();
        App::setLocale($locale);

        $user = $this->recipientUser;
        $domains = $this->sites->map(fn (Site $site) => $site->domain ?: $site->site_name)->filter()->values();
        $count = $domains->count();
        $extra = 0;
        if ($count > 12) {
            $extra = $count - 12;
            $domains = $domains->take(12);
        }

        $setPasswordUrl = $this->setPasswordUrl($user);
        $invitesUrl = $this->publicRoute('publisher.websites', ['status' => 'invites']);
        $payoutUrl = $this->publicRoute('publisher.withdraw');
        $needsPassword = $user?->needsStaffPasswordSetup() ?? true;
        $appName = config('app.name', 'SEOLinkBuildings');

        $mail = $this->subject(__('messages.publisher_onboard_subject', ['app' => $appName]))
            ->markdown('emails.publisher-staff-onboarding')
            ->with([
                'publisherName' => $this->firstName($user),
                'brand' => $this->brand(),
                'domains' => $domains,
                'count' => $count,
                'extra' => $extra,
                'setPasswordUrl' => $setPasswordUrl,
                'invitesUrl' => $invitesUrl,
                'payoutUrl' => $payoutUrl,
                'needsPassword' => $needsPassword,
                'expireDays' => self::expireDays(),
                'heading' => __('messages.publisher_onboard_heading'),
                'hiLine' => __('messages.publisher_onboard_hi', ['name' => $this->firstName($user)]),
                'intro' => $needsPassword
                    ? __('messages.publisher_onboard_intro_new', ['app' => $appName])
                    : __('messages.publisher_onboard_intro_existing', ['app' => $appName]),
                'sitesLine' => trans_choice('messages.publisher_onboard_sites', $count, ['count' => $count]),
                'moreLine' => $extra > 0 ? __('messages.publisher_onboard_more', ['count' => $extra]) : '',
                'afterLine' => __('messages.publisher_onboard_after'),
                'ctaPassword' => __('messages.publisher_onboard_cta_password'),
                'ctaAccept' => __('messages.publisher_onboard_cta_accept'),
                'expiresLine' => __('messages.publisher_onboard_expires', ['days' => self::expireDays()]),
                'googleLine' => __('messages.publisher_onboard_google'),
                'payoutLine' => __('messages.publisher_onboard_payout', ['url' => $payoutUrl]),
            ]);

        App::setLocale($previous);

        return $mail;
    }

    public static function expireDays(): int
    {
        return max(1, (int) config('auth.staff_onboarding.expire_days', 7));
    }

    public static function signedSetPasswordUrl(User $user): string
    {
        if (EmailCatalog::isPreviewUser($user)) {
            return rtrim(app_public_url(), '/').'/onboard/password/preview-id/preview-hash';
        }

        $relative = URL::temporarySignedRoute(
            'publisher.onboard.password',
            now()->addDays(self::expireDays()),
            [
                'user' => $user->getKey(),
                'hash' => sha1($user->getEmailForVerification()),
            ],
            absolute: false
        );

        return rtrim(app_public_url(), '/').$relative;
    }

    private function setPasswordUrl(?User $user): string
    {
        if (! $user) {
            return rtrim(app_public_url(), '/').'/onboard/password/preview-id/preview-hash';
        }

        return self::signedSetPasswordUrl($user);
    }

    private function recipientLocale(): string
    {
        $supported = config('i18n.supported', ['en']);
        $country = strtolower(trim((string) ($this->recipientUser?->country ?? '')));
        $map = [
            'gb' => 'en',
            'uk' => 'en',
            'us' => 'us',
            'de' => 'de',
            'at' => 'at',
            'ch' => 'ch',
            'fr' => 'fr',
            'nl' => 'nl',
            'be' => 'nl',
            'es' => 'es',
            'it' => 'it',
            'pt' => 'pt',
            'pl' => 'pl',
            'ro' => 'ro',
            'bg' => 'bg',
            'dk' => 'dk',
            'se' => 'se',
            'no' => 'no',
            'hu' => 'hu',
            'ee' => 'ee',
            'gr' => 'gr',
        ];
        $locale = $map[$country] ?? 'en';

        return in_array($locale, $supported, true) ? $locale : 'en';
    }
}
