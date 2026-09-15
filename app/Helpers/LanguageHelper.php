<?php

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Auth\StaffCapabilityService;
use App\Support\MarketingHistoryDisplay;
use App\Support\PublicI18n;
use App\Support\WelcomeBonusCopy;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Request;

if (! function_exists('get_language_switcher_url')) {
    function get_language_switcher_url($locale)
    {
        if (! class_exists(PublicI18n::class)) {
            return url('/');
        }

        return PublicI18n::switchUrl(Request::instance(), (string) $locale);
    }
}

if (! function_exists('localized_url')) {
    function localized_url($path = '', $locale = null)
    {
        if (! class_exists(PublicI18n::class)) {
            $path = ltrim((string) $path, '/');

            return $path === '' ? url('/') : url($path);
        }

        return PublicI18n::urlForLocale((string) $path, $locale);
    }
}

if (! function_exists('public_locale')) {
    function public_locale(): string
    {
        return App::getLocale();
    }
}

if (! function_exists('welcome_bonus_can_grant')) {
    function welcome_bonus_can_grant(): bool
    {
        return WelcomeBonusCopy::canGrant();
    }
}

if (! function_exists('welcome_bonus_euro')) {
    function welcome_bonus_euro(): string
    {
        return WelcomeBonusCopy::euro();
    }
}

if (! function_exists('welcome_bonus_message')) {
    function welcome_bonus_message(string $key, ?string $offKey = null): string
    {
        return WelcomeBonusCopy::message($key, $offKey);
    }
}

if (! function_exists('show_public_language_switcher')) {
    function show_public_language_switcher(): bool
    {
        if (! class_exists(PublicI18n::class)) {
            return false;
        }

        return PublicI18n::shouldShowLanguageSwitcher(Request::instance());
    }
}

if (! function_exists('get_available_locales')) {
    function get_available_locales()
    {
        $catalog = [
            'en' => ['name' => 'English (UK)', 'flag' => '🇬🇧', 'code' => 'en'],
            'us' => ['name' => 'English (US)', 'flag' => '🇺🇸', 'code' => 'us'],
            'de' => ['name' => 'Deutsch', 'flag' => '🇩🇪', 'code' => 'de'],
            'fr' => ['name' => 'Français', 'flag' => '🇫🇷', 'code' => 'fr'],
            'nl' => ['name' => 'Nederlands', 'flag' => '🇳🇱', 'code' => 'nl'],
            'es' => ['name' => 'Español', 'flag' => '🇪🇸', 'code' => 'es'],
            'it' => ['name' => 'Italiano', 'flag' => '🇮🇹', 'code' => 'it'],
        ];

        $supported = class_exists(PublicI18n::class)
            ? array_flip(PublicI18n::supported())
            : ['en' => 0];

        return array_filter($catalog, fn ($code) => isset($supported[$code]), ARRAY_FILTER_USE_KEY);
    }
}

if (! function_exists('marketplace_languages')) {
    /**
     * Display map for marketplace language codes only.
     */
    function marketplace_languages(): array
    {
        return [
            'en' => 'English',
            'es' => 'Spanish',
            'fr' => 'French',
            'de' => 'German',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'nl' => 'Dutch',
            'zh' => 'Chinese',
            'ar' => 'Arabic',
            'pl' => 'Polish',
            'sv' => 'Swedish',
            'da' => 'Danish',
            'no' => 'Norwegian',
            'fi' => 'Finnish',
            'el' => 'Greek',
            'cs' => 'Czech',
            'hu' => 'Hungarian',
            'ro' => 'Romanian',
            'bg' => 'Bulgarian',
            'hr' => 'Croatian',
            'sk' => 'Slovak',
            'sl' => 'Slovenian',
            'lt' => 'Lithuanian',
            'lv' => 'Latvian',
            'et' => 'Estonian',
            'ca' => 'Catalan',
            'gl' => 'Galician',
            'eu' => 'Basque',
            'cy' => 'Welsh',
            'gd' => 'Scottish Gaelic',
            'ga' => 'Irish',
            'lb' => 'Luxembourgish',
            'rm' => 'Romansh',
            'mt' => 'Maltese',
        ];
    }
}

if (! function_exists('fullLanguage')) {
    function fullLanguage($code)
    {
        $languages = marketplace_languages();
        $key = strtolower((string) $code);

        return $languages[$key] ?? strtoupper((string) $code);
    }
}

if (! function_exists('marketplace_countries')) {
    /**
     * Display map for marketplace country codes only.
     */
    function marketplace_countries(): array
    {
        return [
            // Europe
            'al' => 'Albania',
            'at' => 'Austria',
            'ba' => 'Bosnia and Herzegovina',
            'be' => 'Belgium',
            'bg' => 'Bulgaria',
            'ch' => 'Switzerland',
            'cy' => 'Cyprus',
            'cz' => 'Czech Republic',
            'de' => 'Germany',
            'dk' => 'Denmark',
            'ee' => 'Estonia',
            'es' => 'Spain',
            'fi' => 'Finland',
            'fr' => 'France',
            'gr' => 'Greece',
            'hr' => 'Croatia',
            'hu' => 'Hungary',
            'ie' => 'Ireland',
            'is' => 'Iceland',
            'it' => 'Italy',
            'lt' => 'Lithuania',
            'lu' => 'Luxembourg',
            'lv' => 'Latvia',
            'md' => 'Moldova',
            'me' => 'Montenegro',
            'mk' => 'North Macedonia',
            'mt' => 'Malta',
            'nl' => 'Netherlands',
            'no' => 'Norway',
            'pl' => 'Poland',
            'pt' => 'Portugal',
            'ro' => 'Romania',
            'rs' => 'Serbia',
            'se' => 'Sweden',
            'si' => 'Slovenia',
            'sk' => 'Slovakia',
            'ua' => 'Ukraine',
            'uk' => 'United Kingdom',
            // English regions
            'us' => 'United States',
            'ca' => 'Canada',
            'au' => 'Australia',
            'nz' => 'New Zealand',
            'za' => 'South Africa',
            'sg' => 'Singapore',
            // Latin America
            'ar' => 'Argentina',
            'bo' => 'Bolivia',
            'br' => 'Brazil',
            'cl' => 'Chile',
            'co' => 'Colombia',
            'cr' => 'Costa Rica',
            'cu' => 'Cuba',
            'do' => 'Dominican Republic',
            'ec' => 'Ecuador',
            'sv' => 'El Salvador',
            'gt' => 'Guatemala',
            'hn' => 'Honduras',
            'mx' => 'Mexico',
            'ni' => 'Nicaragua',
            'pa' => 'Panama',
            'py' => 'Paraguay',
            'pe' => 'Peru',
            'pr' => 'Puerto Rico',
            'uy' => 'Uruguay',
            've' => 'Venezuela',
            // Chinese markets
            'cn' => 'China',
            'tw' => 'Taiwan',
            'hk' => 'Hong Kong',
            'mo' => 'Macau',
            // Gulf region
            'ae' => 'United Arab Emirates',
            'sa' => 'Saudi Arabia',
            'qa' => 'Qatar',
            'kw' => 'Kuwait',
            'bh' => 'Bahrain',
            'om' => 'Oman',
        ];
    }
}

if (! function_exists('fullCountry')) {
    function fullCountry($code)
    {
        $countries = marketplace_countries();
        $key = strtolower((string) $code);

        return $countries[$key] ?? strtoupper((string) $code);
    }
}

if (! function_exists('getCountryFlag')) {
    /**
     * Convert ISO country code to emoji flag (uk → gb).
     */
    function getCountryFlag($countryCode)
    {
        $code = strtolower(trim((string) $countryCode));
        if ($code === '' || $code === 'xx') {
            return '';
        }
        if ($code === 'uk') {
            $code = 'gb';
        }
        $code = strtoupper($code);
        if (strlen($code) !== 2) {
            return '';
        }

        return mb_convert_encoding('&#'.(127397 + ord($code[0])).';', 'UTF-8', 'HTML-ENTITIES')
            .mb_convert_encoding('&#'.(127397 + ord($code[1])).';', 'UTF-8', 'HTML-ENTITIES');
    }
}

if (! function_exists('app_public_url')) {
    /**
     * Public site root for outbound signed links (emails).
     *
     * Priority:
     * 1) Current HTTP request origin (including localhost) — so local register
     *    emails point at 127.0.0.1 / localhost, not production.
     * 2) APP_URL when it is a real host.
     * 3) PUBLIC_APP_URL only in production when APP_URL is still loopback
     *    (misconfigured deploy sending mail from the queue).
     */
    function app_public_url(): string
    {
        // Prefer the live request origin whenever we have one (includes
        // localhost during `php artisan serve` registration).
        try {
            $request = request();
            if ($request && filled($request->getHost())) {
                return rtrim($request->getSchemeAndHttpHost(), '/');
            }
        } catch (Throwable) {
            // fall through to config
        }

        $root = rtrim((string) config('app.url'), '/');
        $host = strtolower((string) (parse_url($root, PHP_URL_HOST) ?: ''));
        $isLoopback = $host === ''
            || in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.localhost');

        if ($isLoopback && app()->environment('production')) {
            $fallback = rtrim((string) config('app.public_url', 'https://seolinkbuildings.com'), '/');

            return $fallback !== '' ? $fallback : 'https://seolinkbuildings.com';
        }

        if ($root !== '') {
            return $root;
        }

        $fallback = rtrim((string) config('app.public_url', 'https://seolinkbuildings.com'), '/');

        return $fallback !== '' ? $fallback : 'https://seolinkbuildings.com';
    }
}

if (! function_exists('signed_url_ignored_query_params')) {
    /**
     * Query params email clients / scanners often append that must not
     * invalidate Laravel signed verification links.
     *
     * @return list<string>
     */
    function signed_url_ignored_query_params(): array
    {
        return [
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_term',
            'utm_content',
            'utm_id',
            'fbclid',
            'gclid',
            'mc_cid',
            'mc_eid',
            'msclkid',
            '_hsenc',
            '_hsmi',
        ];
    }
}

if (! function_exists('role_home_path')) {
    /**
     * Host-relative post-auth landing path for the user's active role.
     * Advertisers land on the catalog (activation); others on their dashboard.
     */
    function role_home_path(?User $user): string
    {
        if (! $user) {
            return '/';
        }

        return match ($user->activeRole()) {
            'advertiser' => '/advertiser/catalog',
            'publisher' => route('publisher.dashboard', absolute: false),
            'admin' => route('admin.dashboard', absolute: false),
            'marketing' => route('marketing.dashboard', absolute: false),
            default => '/',
        };
    }
}

if (! function_exists('billing_company_logo_path')) {
    /**
     * Absolute filesystem path to the company logo used on invoices/PDFs.
     */
    function billing_company_logo_path(): ?string
    {
        $path = ltrim((string) config('billing.company.logo_path', 'assets/img/email-logo.png'), '/');
        $full = public_path($path);

        return is_file($full) ? $full : null;
    }
}

if (! function_exists('billing_company_logo_data_uri')) {
    /**
     * Data-URI for DomPDF / print invoices (avoids remote URL fetches).
     */
    function billing_company_logo_data_uri(): ?string
    {
        $full = billing_company_logo_path();
        if ($full === null) {
            return null;
        }

        $mime = match (strtolower(pathinfo($full, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/png',
        };

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($full));
    }
}

if (! function_exists('url_is_loopback')) {
    /**
     * True when a URL (or host) is localhost / loopback — leftover APP_URL.
     */
    function url_is_loopback(?string $urlOrHost): bool
    {
        $raw = trim((string) $urlOrHost);
        if ($raw === '') {
            return true;
        }

        $host = strtolower((string) (parse_url($raw, PHP_URL_HOST) ?: ''));
        if ($host === '') {
            $host = strtolower(preg_replace('#^https?://#i', '', explode('/', $raw, 2)[0]));
            $host = explode(':', $host)[0];
        }

        return $host === ''
            || in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.localhost');
    }
}

if (! function_exists('brand_public_origin')) {
    /**
     * Public site origin for customer documents and invoice emails.
     * Never returns leftover loopback APP_URL (localhost / 127.0.0.1).
     */
    function brand_public_origin(): string
    {
        $fallback = 'https://seolinkbuildings.com';

        foreach ([
            config('billing.company.website_url'),
            config('app.public_url'),
            config('app.url'),
            $fallback,
        ] as $candidate) {
            $url = rtrim(trim((string) $candidate), '/');
            if ($url !== '' && ! url_is_loopback($url)) {
                return $url;
            }
        }

        return $fallback;
    }
}

if (! function_exists('billing_company_for_documents')) {
    /**
     * Seller block for PDF / HTML invoices. Leftover APP_NAME casing and
     * leftover APP_URL (localhost) must not print on a receipt a customer keeps.
     *
     * @return array<string, mixed>
     */
    function billing_company_for_documents(): array
    {
        $company = config('billing.company', []);
        $name = trim((string) ($company['name'] ?? ''));
        if ($name === '' || ($name !== 'SEOLinkBuildings' && strcasecmp($name, 'SEOLinkBuildings') === 0)) {
            $company['name'] = 'SEOLinkBuildings';
        }
        $company['website_url'] = brand_public_origin();

        return $company;
    }
}

if (! function_exists('mail_brand_name')) {
    /**
     * Visible brand in mail chrome. Leftover APP_NAME casing is not a new name.
     */
    function mail_brand_name(): string
    {
        $name = trim((string) config('email_notifications.brand.name', ''));
        if ($name === '' || ($name !== 'SEOLinkBuildings' && strcasecmp($name, 'SEOLinkBuildings') === 0)) {
            return 'SEOLinkBuildings';
        }

        return $name;
    }
}

if (! function_exists('mail_brand_website_url')) {
    /**
     * Header / footer link in HTML emails. Leftover APP_URL is not a site.
     */
    function mail_brand_website_url(): string
    {
        $configured = rtrim(trim((string) config('email_notifications.brand.website_url', '')), '/');
        if ($configured !== '' && ! url_is_loopback($configured)) {
            return $configured;
        }

        return brand_public_origin();
    }
}

if (! function_exists('mail_brand_logo_url')) {
    /**
     * Absolute logo URL for HTML emails (Final B wordmark).
     * Uses MAIL_LOGO_URL when set, otherwise APP_URL + email-logo asset.
     * Always cache-busts so CDN clients pick up logo refreshes.
     */
    function mail_brand_logo_url(): string
    {
        $path = (string) config('email_notifications.brand.logo_path', 'assets/img/email-logo.png');
        $path = ltrim($path, '/');
        $absolutePath = public_path($path);
        $version = is_file($absolutePath) ? (string) filemtime($absolutePath) : (string) time();

        $explicit = trim((string) config('email_notifications.brand.logo_url', ''));

        // Stale overrides that still point at logo1/logo2 → migrate to email-logo.
        if ($explicit !== '' && preg_match('#/assets/img/logo[12]\.png(\?.*)?$#i', $explicit)) {
            $explicit = '';
        }

        if ($explicit !== '') {
            $base = preg_replace('/([?&])v=[^&]*&?/', '$1', $explicit) ?? $explicit;
            $base = rtrim($base, '?&');
            $sep = str_contains($base, '?') ? '&' : '?';

            return $base.$sep.'v='.$version;
        }

        $root = brand_public_origin();

        return $root.'/'.$path.'?v='.$version;
    }
}

if (! function_exists('google_oauth_configured')) {
    /**
     * True when Google OAuth client credentials look real (non-empty, not placeholders).
     */
    function google_oauth_configured(): bool
    {
        $id = trim((string) config('services.google.client_id', ''));
        $secret = trim((string) config('services.google.client_secret', ''));

        if ($id === '' || $secret === '') {
            return false;
        }

        $placeholders = [
            'your-id',
            'your-secret',
            'your_client_id',
            'your_client_secret',
            'changeme',
            'xxx',
            'null',
            'undefined',
        ];

        return ! in_array(strtolower($id), $placeholders, true)
            && ! in_array(strtolower($secret), $placeholders, true);
    }
}

if (! function_exists('staff_uses_marketing_workspace')) {
    /**
     * True when this user should see /marketing links (active marketing, not admin).
     */
    function staff_uses_marketing_workspace(?User $user): bool
    {
        return $user && $user->isMarketing() && ! $user->isAdmin();
    }
}

if (! function_exists('staff_route_prefix_for')) {
    /**
     * Route name prefix for a staff user's active workspace.
     */
    function staff_route_prefix_for(?User $user): string
    {
        return staff_uses_marketing_workspace($user) ? 'marketing.' : 'admin.';
    }
}

if (! function_exists('staff_route_prefix')) {
    /**
     * Route name prefix for the current staff workspace (marketing.* vs admin.*).
     */
    function staff_route_prefix(): string
    {
        return staff_route_prefix_for(auth()->user());
    }
}

if (! function_exists('staff_base_path')) {
    /**
     * URL path prefix for staff AJAX/forms (/marketing vs /admin).
     */
    function staff_base_path(): string
    {
        return staff_uses_marketing_workspace(auth()->user()) ? '/marketing' : '/admin';
    }
}

if (! function_exists('staff_route')) {
    /**
     * Named route helper that resolves to marketing.* or admin.* for the active staff role.
     */
    function staff_route(string $name, mixed $parameters = [], bool $absolute = true): string
    {
        return route(staff_route_prefix().ltrim($name, '.'), $parameters, $absolute);
    }
}

if (! function_exists('staff_can')) {
    /**
     * Current user may use any of the listed admin capabilities.
     */
    function staff_can(string ...$capabilities): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->staffCan(...$capabilities);
    }
}

if (! function_exists('staff_is_unrestricted')) {
    /**
     * Current user is a full admin (no capability overlay rows).
     */
    function staff_is_unrestricted(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->staffIsUnrestricted();
    }
}

if (! function_exists('staff_capability_label')) {
    function staff_capability_label(?User $user = null): string
    {
        $user ??= auth()->user();
        if (! $user instanceof User) {
            return '';
        }

        $service = app(StaffCapabilityService::class);

        return $service->label($service->storedCapabilities($user));
    }
}

if (! function_exists('staff_layout')) {
    /**
     * Blade layout for shared staff ops views (marketing panel vs admin panel).
     */
    function staff_layout(): string
    {
        return staff_uses_marketing_workspace(auth()->user())
            ? 'marketing.layouts.app'
            : 'admin.layouts.app';
    }
}

if (! function_exists('marketing_task_labels')) {
    /**
     * Friendly labels for marketing / staff task history actions.
     *
     * @return array<string, string>
     */
    function marketing_task_labels(): array
    {
        return [
            'bulk_request.done' => 'Done',
            'bulk_request.seeded' => 'Seed',
            'bulk_request.sheet_sent' => 'Marked sheet sent',
            'bulk_request.cancelled' => 'Cancelled bulk request',
            'bulk_request.items_rejected' => 'Removed bulk sites',
            'bulk_request.notes_updated' => 'Updated bulk notes',
            'site.deleted_by_marketing' => 'Deleted pending site',
            'site.updated' => 'Edited site',
            'site.activated' => 'Activated site',
            'site.approved' => 'Approved site',
            'site.deactivated' => 'Deactivated site',
            'site.assigned_for_acceptance' => 'Assigned site to publisher',
            'site.image_uploaded' => 'Uploaded site image',
            'site.metrics_refreshed' => 'Refreshed metrics',
            'site.metrics_refresh_queued' => 'Queued metrics refresh',
            'site.screenshot_refreshed' => 'Refreshed screenshot',
            'site.screenshot_refresh_queued' => 'Queued screenshot refresh',
            'site.enrichment_queued' => 'Queued site enrichment',
            'site.enrichment_refreshed' => 'Enriched site',
            'site.enrichment_batch_queued' => 'Queued stale site enrichment',
            'site.enrichment_rerun_queued' => 'Re-queued failed site enrichment',
            'site.metrics_manual' => 'Saved manual metrics',
            'site.metrics_api_unlocked' => 'Allowed API overwrite',
        ];
    }
}

if (! function_exists('marketing_task_label')) {
    /**
     * Human-readable task title for an activity action code.
     */
    function marketing_task_label(?string $action): string
    {
        $action = (string) $action;
        $labels = marketing_task_labels();

        return $labels[$action] ?? $action;
    }
}

if (! function_exists('activity_action_labels')) {
    /**
     * Friendly labels for the admin activity history (includes marketing tasks).
     *
     * @return array<string, string>
     */
    function activity_action_labels(): array
    {
        return marketing_task_labels() + [
            'site.approved' => 'Approved site',
            'site.rejected' => 'Rejected site',
            'site.verified_file' => 'Verified site (file)',
            'site.verified_manual' => 'Verified site (manual)',
            'site.archived' => 'Archived site',
            'site.unarchived' => 'Restored archived site',
            'site.rereview_requested' => 'Requested site re-review',
            'site.deleted' => 'Deleted site',
            'site.claim_submitted' => 'Submitted site claim',
            'site.claim_approved' => 'Approved site claim',
            'site.claim_rejected' => 'Rejected site claim',
            'site.assignment_accepted' => 'Accepted site assignment',
            'site.featured' => 'Featured site',
            'site.featured_stripe' => 'Featured site (Stripe)',
            'site.feature_stripe_credited' => 'Credited Stripe feature payment',
            'site.discount_set' => 'Set site discount',
            'site.discount_cleared' => 'Cleared site discount',
            'site.bulk_discount_joined' => 'Joined bulk discount',
            'site.bulk_discount_updated' => 'Updated bulk discount',
            'site.bulk_discount_left' => 'Left bulk discount',
            'site.bulk_imported' => 'Bulk-imported site',
            'site.rating_saved' => 'Saved site rating',
            'site.rating_updated' => 'Updated site rating',
            'site.rating_deleted' => 'Deleted site rating',
            'site.metrics_api_unlocked' => 'Allowed API overwrite',
            'agency_import.submitted' => 'Submitted agency import',
            'bulk_request.created' => 'Created bulk request',
            'user.company_updated' => 'Updated company name',
            'user.payout_profile_updated' => 'Updated payout profile',
            'user.marketing_granted' => 'Granted marketing access',
            'user.marketing_revoked' => 'Revoked marketing access',
            'deposit.approved' => 'Approved deposit',
            'deposit.rejected' => 'Rejected deposit',
            'withdrawal.status_updated' => 'Updated withdrawal',
            'withdrawal.exported' => 'Exported withdrawals',
            'withdrawal.batch_processing' => 'Batch marked withdrawals processing',
            'withdrawal.batch_completed' => 'Batch marked withdrawals paid',
            'withdrawal.batch_cancelled' => 'Batch cancelled withdrawals',
            'payment.status_updated' => 'Updated payment',
            'payment.exported' => 'Exported payments',
            'dispute.opened' => 'Opened order dispute',
            'dispute.dismissed' => 'Dismissed order dispute',
            'dispute.upheld' => 'Upheld order dispute',
            'order.status_overridden' => 'Overrode order status',
            'order.publisher_reminded' => 'Reminded publisher',
            'finance.debt_cleared' => 'Cleared wallet debt',
            'finance.ledger_exported' => 'Exported wallet ledger',
            'finance.period_exported' => 'Exported finance period',
            'wallet.leftover_card_credited' => 'Credited leftover checkout payment',
            'invoice.generated' => 'Generated invoice',
            'invoice.cancelled' => 'Cancelled invoice',
            'invoice.resent' => 'Resent invoice',
            'invoice.backfill_run' => 'Backfilled tax invoices',
            'invoice.pdfs_regenerated' => 'Regenerated missing invoice PDFs',
            'invoice.pdf_regenerated' => 'Regenerated invoice PDF',
            'campaign.queued' => 'Queued campaign',
            'audience.exported' => 'Exported audience',
            'welcome_bonus.toggled' => 'Toggled welcome bonus',
            'welcome_bonus.amount_changed' => 'Changed welcome bonus',
            'moderation.overridden' => 'Overrode moderation',
            'moderation.override_reverted' => 'Reverted moderation override',
            'moderation.settings_updated' => 'Updated moderation settings',
            'feedback.problem' => 'Reported a problem',
            'feedback.suggestion' => 'Sent a suggestion',
            'website.suggested' => 'Suggested a website',
            'problem.report_updated' => 'Updated problem report',
            'suggestion.updated' => 'Updated suggestion',
            'website.suggestion_updated' => 'Updated website suggestion',
            'catalog_activity.exempt_toggled' => 'Toggled catalog pace exemption',
            'catalog_activity.copy_hide_cleared' => 'Cleared catalog copy hide',
            'catalog_hide_lifted' => 'Lifted catalog hide mode',
            'catalog_hide_applied' => 'Applied catalog hide',
            'catalog_copy_warned' => 'Warned for catalog copy harvesting',
            'catalog_strikes_reset' => 'Reset catalog copy strikes',
            'blog.published' => 'Published blog',
            'blog.unpublished' => 'Unpublished blog',
            'blog.deleted' => 'Deleted blog',
            'email_center.test_sent' => 'Sent test email',
            'email.settings_updated' => 'Updated email notification settings',
            'email.retried' => 'Retried failed mail',
            'sites.records_exported' => 'Exported website records',
            'content.archived' => 'Archived library article',
            'content.restored' => 'Restored library article',
            'content.re_evaluated' => 'Re-evaluated library article',
            'announcement.created' => 'Created announcement',
            'announcement.updated' => 'Updated announcement',
            'announcement.deleted' => 'Deleted announcement',
            'announcement.restored' => 'Restored announcement',
            'announcement.toggled' => 'Toggled announcement',
            'announcement.duplicated' => 'Duplicated announcement',
            'banner.created' => 'Created banner',
            'banner.updated' => 'Updated banner',
            'banner.deleted' => 'Deleted banner',
            'banner.restored' => 'Restored banner',
            'banner.toggled' => 'Toggled banner',
            'banner.duplicated' => 'Duplicated banner',
        ];
    }
}

if (! function_exists('activity_action_aliases')) {
    /**
     * Retired writer codes that must still filter and label with the live code.
     *
     * @return array<string, string>
     */
    function activity_action_aliases(): array
    {
        return [
            'catalog_pace_exempted' => 'catalog_activity.exempt_toggled',
        ];
    }
}

if (! function_exists('activity_action_canonical')) {
    function activity_action_canonical(?string $action): string
    {
        $action = (string) $action;

        return activity_action_aliases()[$action] ?? $action;
    }
}

if (! function_exists('activity_action_equivalent_codes')) {
    /**
     * @return list<string>
     */
    function activity_action_equivalent_codes(?string $action): array
    {
        $canonical = activity_action_canonical($action);
        if ($canonical === '') {
            return [];
        }

        $codes = [$canonical];
        foreach (activity_action_aliases() as $alias => $target) {
            if ($target === $canonical) {
                $codes[] = $alias;
            }
        }

        return array_values(array_unique($codes));
    }
}

if (! function_exists('activity_action_label')) {
    /**
     * Human-readable title for an admin activity action code.
     */
    function activity_action_label(?string $action): string
    {
        $action = activity_action_canonical($action);
        $labels = activity_action_labels();
        if (isset($labels[$action])) {
            return $labels[$action];
        }

        if (str_starts_with($action, 'site.verified_')) {
            $method = substr($action, strlen('site.verified_'));

            return $method !== '' ? 'Verified site ('.$method.')' : 'Verified site';
        }

        return $action;
    }
}

if (! function_exists('activity_action_actions_matching')) {
    /**
     * Action codes whose friendly label or raw code starts with the search needle as a word.
     *
     * @return list<string>
     */
    function activity_action_actions_matching(?string $q): array
    {
        $needle = mb_strtolower(trim((string) $q));
        if ($needle === '' || mb_strlen($needle) < 2) {
            return [];
        }

        // Word-start only: "activate" hits Activated, not Deactivated.
        $pattern = '/\b'.preg_quote($needle, '/').'/u';
        $matched = [];
        foreach (activity_action_labels() as $code => $label) {
            $codeRaw = strtolower((string) $code);
            $codeWords = str_replace(['.', '_'], ' ', $codeRaw);
            if (
                preg_match($pattern, strtolower((string) $label))
                || preg_match($pattern, $codeRaw)
                || preg_match($pattern, $codeWords)
            ) {
                foreach (activity_action_equivalent_codes($code) as $equiv) {
                    $matched[] = $equiv;
                }
            }
        }

        foreach (activity_action_aliases() as $alias => $canonical) {
            $aliasRaw = strtolower((string) $alias);
            $aliasWords = str_replace(['.', '_'], ' ', $aliasRaw);
            if (preg_match($pattern, $aliasRaw) || preg_match($pattern, $aliasWords)) {
                foreach (activity_action_equivalent_codes($canonical) as $equiv) {
                    $matched[] = $equiv;
                }
            }
        }

        return array_values(array_unique($matched));
    }
}

if (! function_exists('marketing_task_actions_matching')) {
    /**
     * Action codes whose friendly label or raw code starts with the search needle as a word.
     *
     * @return list<string>
     */
    function marketing_task_actions_matching(?string $q): array
    {
        $needle = strtolower(trim((string) $q));
        if ($needle === '') {
            return [];
        }

        // Word-start only: "activate" hits Activated, not Deactivated; "Seed" hits Seeded.
        $pattern = '/\b'.preg_quote($needle, '/').'/u';
        $matched = [];
        foreach (marketing_task_labels() as $code => $label) {
            $codeRaw = strtolower((string) $code);
            $codeWords = str_replace(['.', '_'], ' ', $codeRaw);
            if (
                preg_match($pattern, strtolower($label))
                || preg_match($pattern, $codeRaw)
                || preg_match($pattern, $codeWords)
            ) {
                $matched[] = $code;
            }
        }

        return $matched;
    }
}

if (! function_exists('marketing_history_subject_url')) {
    /**
     * Deep link for a marketing history row subject, or null when it should stay plain text.
     *
     * @param  ?array<string, mixed>  $lookup
     */
    function marketing_history_subject_url(?ActivityLog $log, ?array $lookup = null): ?string
    {
        return MarketingHistoryDisplay::subjectUrl($log, $lookup);
    }
}

if (! function_exists('marketing_history_bulk_url')) {
    /**
     * Extra bulk-request link when the primary subject is a site on a bulk batch.
     *
     * @param  ?array<string, mixed>  $lookup
     */
    function marketing_history_bulk_url(?ActivityLog $log, ?array $lookup = null): ?string
    {
        return MarketingHistoryDisplay::bulkUrl($log, $lookup);
    }
}
