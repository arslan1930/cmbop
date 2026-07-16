<?php

namespace App\Services\SiteEnrichment;

use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CountryDetectionService
{
    /** Common ccTLD → ISO country code */
    private array $tldMap = [
        'uk' => 'gb', 'gb' => 'gb', 'de' => 'de', 'fr' => 'fr', 'es' => 'es',
        'it' => 'it', 'nl' => 'nl', 'be' => 'be', 'ch' => 'ch', 'at' => 'at',
        'pl' => 'pl', 'se' => 'se', 'no' => 'no', 'dk' => 'dk', 'fi' => 'fi',
        'ie' => 'ie', 'pt' => 'pt', 'cz' => 'cz', 'ro' => 'ro', 'hu' => 'hu',
        'gr' => 'gr', 'tr' => 'tr', 'ru' => 'ru', 'ua' => 'ua', 'au' => 'au',
        'nz' => 'nz', 'ca' => 'ca', 'mx' => 'mx', 'br' => 'br', 'ar' => 'ar',
        'cl' => 'cl', 'co' => 'co', 'in' => 'in', 'jp' => 'jp', 'kr' => 'kr',
        'cn' => 'cn', 'sg' => 'sg', 'hk' => 'hk', 'tw' => 'tw', 'za' => 'za',
        'ae' => 'ae', 'il' => 'il', 'us' => 'us',
    ];

    /**
     * Detect primary country when missing. Never overwrite an existing country.
     */
    public function detectAndApply(Site $site): ?string
    {
        if (filled($site->country) || ! empty($site->countries)) {
            return $site->country ?: (is_array($site->countries) ? ($site->countries[0] ?? null) : null);
        }

        $code = $this->fromTld((string) ($site->domain ?: $site->site_url));
        if (! $code) {
            $code = $this->fromHtmlLang($site);
        }

        if ($code) {
            $site->forceFill(['country' => strtolower($code)])->save();
        }

        return $code;
    }

    public function fromTld(string $hostOrUrl): ?string
    {
        $host = parse_url(preg_match('#^https?://#i', $hostOrUrl) ? $hostOrUrl : 'https://'.$hostOrUrl, PHP_URL_HOST);
        $host = strtolower((string) ($host ?: $hostOrUrl));
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        $parts = explode('.', $host);
        $tld = end($parts) ?: '';

        if ($tld === 'com' || $tld === 'net' || $tld === 'org' || $tld === 'io' || $tld === 'ai') {
            // Ambiguous gTLD — prefer US only as soft default for .com when no other signal.
            return $tld === 'com' ? 'us' : null;
        }

        // co.uk style
        if (count($parts) >= 3 && $parts[count($parts) - 2] === 'co' && $tld === 'uk') {
            return 'gb';
        }

        return $this->tldMap[$tld] ?? null;
    }

    private function fromHtmlLang(Site $site): ?string
    {
        try {
            $url = $site->site_url ?: ('https://'.$site->domain);
            if (! preg_match('#^https?://#i', $url)) {
                $url = 'https://'.$url;
            }

            $response = Http::timeout(8)->withHeaders([
                'User-Agent' => 'SEOLinkBuildingsBot/1.0',
            ])->get($url);

            if (! $response->successful()) {
                return null;
            }

            $html = $response->body();
            if (preg_match('/<html[^>]+lang=["\']([a-z]{2})(?:-[a-zA-Z]{2})?["\']/i', $html, $m)) {
                $lang = strtolower($m[1]);
                // Weak mapping from language to likely country (only when country missing).
                return match ($lang) {
                    'en' => 'us',
                    'de' => 'de',
                    'fr' => 'fr',
                    'es' => 'es',
                    'it' => 'it',
                    'pt' => 'br',
                    'nl' => 'nl',
                    'pl' => 'pl',
                    'sv' => 'se',
                    'ja' => 'jp',
                    default => null,
                };
            }
        } catch (\Throwable $e) {
            Log::debug('Country HTML detection failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }
}
