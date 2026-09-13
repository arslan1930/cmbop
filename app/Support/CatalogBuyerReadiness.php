<?php

namespace App\Support;

use App\Models\Site;

/**
 * Publisher-facing checklist: fields the publisher owns.
 * Cover / homepage screenshot is staff-uploaded — not listed here.
 */
class CatalogBuyerReadiness
{
    /**
     * @return list<array{key: string, label: string, ok: bool, hint: string, cta: ?string, actionable: bool, wizard_step: ?int}>
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
                'cta' => 'Set country',
                'actionable' => true,
                'wizard_step' => 2,
            ],
            [
                'key' => 'niches',
                'label' => 'Niches',
                'ok' => $niches !== [],
                'hint' => 'Pick at least one niche so the listing appears in catalog filters.',
                'cta' => 'Set niches',
                'actionable' => true,
                'wizard_step' => 2,
            ],
            [
                'key' => 'brief',
                'label' => 'Description',
                'ok' => $briefOk,
                'hint' => 'Write a real site description advertisers can trust.',
                'cta' => 'Edit description',
                'actionable' => true,
                'wizard_step' => 1,
            ],
            [
                'key' => 'example_url',
                'label' => 'Sample article URL',
                'ok' => $site->hasExampleUrl(),
                'hint' => 'Buyers open this from Details to judge content quality.',
                'cta' => 'Add sample URL',
                'actionable' => true,
                'wizard_step' => 1,
            ],
            [
                'key' => 'quality',
                'label' => 'Quality bar (DA/DR/traffic)',
                'ok' => $site->hasGoodMetrics(),
                'hint' => 'Staff set DA, DR, and traffic. DA ≥ '.Site::GOOD_MIN_DA.', DR ≥ '.Site::GOOD_MIN_DR.', traffic ≥ '.number_format(Site::GOOD_MIN_TRAFFIC).'.',
                'cta' => null,
                'actionable' => false,
                'wizard_step' => null,
            ],
            [
                'key' => 'tag',
                'label' => 'Listing tag',
                'ok' => $site->tagValue() !== null,
                'hint' => 'Sponsored, Partner article, or As you prefer — advertisers filter on this.',
                'cta' => 'Set listing tag',
                'actionable' => true,
                'wizard_step' => 3,
            ],
        ];
    }

    /**
     * Gaps first, then ready items, plus counts for the View summary.
     *
     * @return array{
     *     gaps: list<array<string, mixed>>,
     *     ready: list<array<string, mixed>>,
     *     gap_count: int,
     *     ready_count: int,
     *     total: int
     * }
     */
    public static function grouped(Site $site): array
    {
        $items = self::checklist($site);
        $gaps = [];
        $ready = [];
        foreach ($items as $item) {
            if ($item['ok']) {
                $ready[] = $item;
            } else {
                $gaps[] = $item;
            }
        }

        return [
            'gaps' => $gaps,
            'ready' => $ready,
            'gap_count' => count($gaps),
            'ready_count' => count($ready),
            'total' => count($items),
        ];
    }
}
