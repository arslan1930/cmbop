<?php

use App\Models\User;
use App\Support\PublicI18n;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Request;

if (! function_exists('get_language_switcher_url')) {
    function get_language_switcher_url($locale)
    {
        return PublicI18n::switchUrl(Request::instance(), (string) $locale);
    }
}

if (! function_exists('localized_url')) {
    function localized_url($path = '', $locale = null)
    {
        return PublicI18n::urlForLocale((string) $path, $locale);
    }
}

if (! function_exists('public_locale')) {
    function public_locale(): string
    {
        return App::getLocale();
    }
}

if (! function_exists('show_public_language_switcher')) {
    function show_public_language_switcher(): bool
    {
        return PublicI18n::shouldShowLanguageSwitcher(Request::instance());
    }
}

if (! function_exists('get_available_locales')) {
    function get_available_locales()
    {
        return [
            'en' => ['name' => 'English', 'flag' => '🇬🇧', 'code' => 'en'],
            'de' => ['name' => 'Deutsch', 'flag' => '🇩🇪', 'code' => 'de'],
            'fr' => ['name' => 'Français', 'flag' => '🇫🇷', 'code' => 'fr'],
            'nl' => ['name' => 'Nederlands', 'flag' => '🇳🇱', 'code' => 'nl'],
        ];
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

        $root = rtrim((string) config('app.url'), '/');
        $host = strtolower((string) (parse_url($root, PHP_URL_HOST) ?: ''));
        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            $root = 'https://seolinkbuildings.com';
        }

        return $root.'/'.$path.'?v='.$version;
    }
}

if (! function_exists('google_oauth_credentials')) {
    /**
     * Resolve Google OAuth client id/secret.
     *
     * Prefers config (services.google.*), then falls back to process env
     * (getenv / $_ENV) when config:cache baked empty values.
     *
     * @return array{client_id: string, client_secret: string}
     */
    function google_oauth_credentials(): array
    {
        $id = trim((string) config('services.google.client_id', ''));
        $secret = trim((string) config('services.google.client_secret', ''));

        if ($id === '') {
            $id = trim((string) (getenv('GOOGLE_CLIENT_ID') ?: ($_ENV['GOOGLE_CLIENT_ID'] ?? $_SERVER['GOOGLE_CLIENT_ID'] ?? '')));
        }
        if ($secret === '') {
            $secret = trim((string) (getenv('GOOGLE_CLIENT_SECRET') ?: ($_ENV['GOOGLE_CLIENT_SECRET'] ?? $_SERVER['GOOGLE_CLIENT_SECRET'] ?? '')));
        }

        if ($id !== '' || $secret !== '') {
            config([
                'services.google.client_id' => $id,
                'services.google.client_secret' => $secret,
            ]);
        }

        return [
            'client_id' => $id,
            'client_secret' => $secret,
        ];
    }
}

if (! function_exists('google_oauth_configured')) {
    /**
     * True when Google OAuth client credentials look real (non-empty, not placeholders).
     */
    function google_oauth_configured(): bool
    {
        $credentials = google_oauth_credentials();
        $id = $credentials['client_id'];
        $secret = $credentials['client_secret'];

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

if (! function_exists('staff_route_prefix')) {
    /**
     * Route name prefix for the current staff workspace (marketing.* vs admin.*).
     */
    function staff_route_prefix(): string
    {
        $user = auth()->user();
        if ($user && $user->isMarketing() && ! $user->isAdmin()) {
            return 'marketing.';
        }

        return 'admin.';
    }
}

if (! function_exists('staff_base_path')) {
    /**
     * URL path prefix for staff AJAX/forms (/marketing vs /admin).
     */
    function staff_base_path(): string
    {
        $user = auth()->user();
        if ($user && $user->isMarketing() && ! $user->isAdmin()) {
            return '/marketing';
        }

        return '/admin';
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

if (! function_exists('staff_layout')) {
    /**
     * Blade layout for shared staff ops views (marketing panel vs admin panel).
     */
    function staff_layout(): string
    {
        $user = auth()->user();
        if ($user && $user->isMarketing() && ! $user->isAdmin()) {
            return 'marketing.layouts.app';
        }

        return 'admin.layouts.app';
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
            'bulk_request.seeded' => 'Seeded / added sites',
            'bulk_request.sheet_sent' => 'Marked sheet sent',
            'bulk_request.cancelled' => 'Cancelled bulk request',
            'bulk_request.notes_updated' => 'Updated bulk notes',
            'site.deleted_by_marketing' => 'Deleted pending site',
            'site.updated' => 'Edited site',
            'site.image_uploaded' => 'Uploaded site image',
            'site.metrics_refreshed' => 'Refreshed metrics',
            'site.screenshot_refreshed' => 'Refreshed screenshot',
            'site.metrics_manual' => 'Saved manual metrics',
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
