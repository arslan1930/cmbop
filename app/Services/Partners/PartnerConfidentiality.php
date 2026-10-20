<?php

namespace App\Services\Partners;

use App\Models\Partner;
use App\Models\Site;
use App\Models\User;

class PartnerConfidentiality
{
    /** @var list<string> */
    public const FORBIDDEN_KEYS = [
        'advertiser_price',
        'slb_price',
        'slb_price_publish_eur',
        'slb_price_writing_eur',
        'slb_price_sensitive_extra_eur',
        'commission',
        'commission_eur',
        'commission_eur_minor',
        'margin',
        'markup',
        'platform_fee',
        'platform_fee_amount',
        'platform_fee_percent',
        'slb_revenue',
        'advertiser_count',
        'order_volume',
        'suggested_slb_price',
        'advertiser_eur_amount',
        'slb_price_eur_internal',
        'slb_revenue',
        'buyer_email',
        'advertiser_email',
    ];

    /**
     * @param  array<mixed>  $payload
     * @return list<string>
     */
    public function scan(array $payload): array
    {
        $hits = [];
        $this->walk($payload, $hits);

        return array_values(array_unique($hits));
    }

    public function scanText(string $text): array
    {
        $hits = [];
        $visible = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $text) ?? $text;
        $visible = preg_replace('/\s(?:style|class|id)="[^"]*"/i', ' ', $visible) ?? $visible;
        $lower = strtolower(html_entity_decode(strip_tags($visible), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        foreach (self::FORBIDDEN_KEYS as $key) {
            $needle = strtolower($key);
            if (preg_match('/\b'.preg_quote($needle, '/').'\b/', $lower)) {
                $hits[] = $key;
            }
        }

        return $hits;
    }

    /**
     * Distinctive SLB amounts that must not appear on partner surfaces.
     *
     * @param  list<float|int|string>  $amounts
     * @return list<string>
     */
    public function scanAmounts(string $text, array $amounts): array
    {
        $hits = [];
        foreach ($amounts as $amount) {
            $needle = number_format((float) $amount, 2, '.', '');
            $alt = (string) ((int) $amount);
            if ($needle !== '0.00' && (str_contains($text, $needle) || preg_match('/\b'.preg_quote($alt, '/').'\b/', $text))) {
                $hits[] = $needle;
            }
        }

        return $hits;
    }

    public function userIsPartner(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return Partner::forUser($user) !== null;
    }

    public function siteIsPartnerListing(Site $site): bool
    {
        if (! Site::hasSitesColumn('partner_catalog_item_id')) {
            return false;
        }

        return $site->partner_catalog_item_id !== null && (int) $site->partner_catalog_item_id > 0;
    }

    /**
     * @param  array<mixed>|object  $node
     * @param  list<string>  $hits
     */
    private function walk(mixed $node, array &$hits, string $path = ''): void
    {
        if (is_object($node)) {
            $node = (array) $node;
        }
        if (! is_array($node)) {
            return;
        }
        foreach ($node as $key => $value) {
            $name = strtolower((string) $key);
            if (in_array($name, self::FORBIDDEN_KEYS, true) || str_contains($name, 'slb_price') || str_contains($name, 'commission') || $name === 'markup' || $name === 'margin') {
                $hits[] = $name;
            }
            if (is_array($value) || is_object($value)) {
                $this->walk($value, $hits, $path.'.'.$name);
            }
        }
    }
}
