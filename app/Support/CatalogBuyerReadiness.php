<?php

namespace App\Support;

use App\Models\Site;

/**
 * Publisher-facing checklist: what advertisers see as incomplete on a listing.
 */
class CatalogBuyerReadiness
{
    /**
     * @return list<array{key: string, label: string, ok: bool, hint: string}>
     */
    public static function checklist(Site $site): array
    {
        $niches = collect($site->categories_array ?? [])
            ->map(fn ($v) => trim((string) $v))
            ->filter(fn ($v) => $v !== '' && strtolower($v) !== 'pending')
            ->values()
            ->all();

        $briefOk = SiteDescriptionRules::isValid((string) ($site->description ?? ''))
            && ! CatalogPlaceholderListing::descriptionLooksPlaceholder($site->description);

        return [
            [
                'key' => 'country',
                'label' => 'Marketplace country',
                'ok' => $site->hasMarketplaceCountry(),
                'hint' => 'Advertisers filter by country. Set one before going live.',
            ],
            [
                'key' => 'niches',
                'label' => 'Niches',
                'ok' => $niches !== [],
                'hint' => 'Pick at least one niche so the listing appears in catalog filters.',
            ],
            [
                'key' => 'brief',
                'label' => 'Advertiser brief',
                'ok' => $briefOk,
                'hint' => 'Write a real site description advertisers can trust.',
            ],
            [
                'key' => 'cover',
                'label' => 'Cover or screenshot',
                'ok' => $site->hasCatalogCover(),
                'hint' => 'Without a cover, Site Details shows an empty preview.',
            ],
            [
                'key' => 'example_url',
                'label' => 'Sample article URL',
                'ok' => $site->hasExampleUrl(),
                'hint' => 'Buyers open this from Details to judge content quality.',
            ],
            [
                'key' => 'quality',
                'label' => 'Quality bar (DA/DR/traffic)',
                'ok' => $site->hasGoodMetrics(),
                'hint' => 'DA ≥ '.Site::GOOD_MIN_DA.', DR ≥ '.Site::GOOD_MIN_DR.', traffic ≥ '.number_format(Site::GOOD_MIN_TRAFFIC).'.',
            ],
            [
                'key' => 'tag',
                'label' => 'Listing tag',
                'ok' => $site->tagValue() !== null,
                'hint' => 'Sponsored, Partner article, or As you prefer — advertisers filter on this.',
            ],
        ];
    }
}
