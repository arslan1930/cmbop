<?php

namespace App\Services\Catalog;

use App\Models\Category;

/**
 * Guess catalog filters from an advertiser's own website host.
 * v1: TLD + host keywords only — no crawl.
 */
class CatalogPromotedSiteMatch
{
    /**
     * @return array{host: string, category: string, country: string, language: string}
     */
    public function fromUrl(string $raw): array
    {
        $host = $this->host($raw);

        return [
            'host' => $host,
            'category' => $this->categoryForHost($host),
            'country' => $this->countryForHost($host),
            'language' => $this->languageForHost($host),
        ];
    }

    public function host(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (! preg_match('#^https?://#i', $raw)) {
            $raw = 'https://'.$raw;
        }
        $host = strtolower((string) parse_url($raw, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        return $host;
    }

    private function countryForHost(string $host): string
    {
        $tld = $this->tld($host);
        $map = [
            'no' => 'no', 'de' => 'de', 'at' => 'at', 'ch' => 'ch', 'fr' => 'fr',
            'nl' => 'nl', 'be' => 'be', 'it' => 'it', 'es' => 'es', 'pt' => 'pt',
            'dk' => 'dk', 'se' => 'se', 'fi' => 'fi', 'pl' => 'pl', 'ie' => 'ie',
            'uk' => 'gb', 'gb' => 'gb', 'us' => 'us', 'ca' => 'ca', 'au' => 'au',
        ];

        return $map[$tld] ?? '';
    }

    private function languageForHost(string $host): string
    {
        $country = $this->countryForHost($host);
        $map = [
            'no' => 'no', 'de' => 'de', 'at' => 'de', 'ch' => 'de', 'fr' => 'fr',
            'nl' => 'nl', 'it' => 'it', 'es' => 'es', 'pt' => 'pt', 'dk' => 'da',
            'se' => 'sv', 'fi' => 'fi', 'pl' => 'pl', 'gb' => 'en', 'us' => 'en',
            'ie' => 'en', 'ca' => 'en', 'au' => 'en',
        ];

        return $map[$country] ?? '';
    }

    private function categoryForHost(string $host): string
    {
        $name = str_replace(['.', '-'], ' ', $host);
        $rules = [
            'Automotive' => ['rentacar', 'rent a car', 'car rental', 'auto', 'motor', 'moto', 'bike', 'vehicle'],
            'Travel & Tourism' => ['travel', 'hotel', 'tour', 'camp', 'holiday', 'flight', 'booking'],
        ];
        foreach ($rules as $niche => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($name, $needle) || str_contains(str_replace(' ', '', $name), str_replace(' ', '', $needle))) {
                    return $this->existingNiche($niche);
                }
            }
        }

        return '';
    }

    private function existingNiche(string $name): string
    {
        try {
            $names = Category::catalogFilterNicheNames($name);

            return $names[0] ?? $name;
        } catch (\Throwable) {
            return $name;
        }
    }

    private function tld(string $host): string
    {
        $parts = explode('.', $host);
        if (count($parts) < 2) {
            return '';
        }

        return (string) end($parts);
    }
}
