<?php

namespace App\Services\Partners;

use App\Models\Partner;
use App\Models\PartnerCatalogItem;
use App\Models\PartnerCatalogItemEvent;
use App\Models\PartnerPricingRule;
use App\Models\Site;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\SiteDescriptionSanitizer;
use App\Support\CatalogSensitiveTopics;
use App\Support\StaffListingPublishMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PartnerCatalogGoLiveService
{
    public function __construct(private PartnerPricingService $pricing) {}

    /**
     * @param  list<int>  $itemIds
     * @param  array<string, mixed>  $options
     * @return array{live: list<int>, refused: list<array{id: int, reason: string}>}
     */
    public function goLiveItems(array $itemIds, ?User $actor, array $options = []): array
    {
        $live = [];
        $refused = [];
        $overrideMargin = (bool) ($options['override_margin'] ?? false);
        $overrideDuplicate = (bool) ($options['override_duplicate'] ?? false);
        $reason = (string) ($options['override_reason'] ?? '');

        $items = PartnerCatalogItem::query()->with('partner.user')->whereIn('id', $itemIds)->get();
        foreach ($items as $item) {
            $block = $this->goLiveBlockReason($item, $overrideMargin, $overrideDuplicate);
            if ($block !== null) {
                $refused[] = ['id' => (int) $item->id, 'reason' => $block];

                continue;
            }

            DB::transaction(function () use ($item, $actor, $overrideMargin, $reason, $options, &$live) {
                $item = PartnerCatalogItem::query()->lockForUpdate()->findOrFail($item->id);
                if (empty($item->slb_price_publish_eur) || (float) $item->slb_price_publish_eur <= 0) {
                    $ruleId = $options['pricing_rule_id'] ?? $item->pricing_rule_id;
                    $rule = $ruleId ? PartnerPricingRule::query()->find($ruleId) : $this->pricing->defaultRule();
                    $this->pricing->applySuggested($item, $rule);
                }
                if ($overrideMargin) {
                    $item->margin_override = true;
                    $item->margin_override_reason = $reason !== '' ? $reason : 'admin_override';
                    $this->pricing->refreshFloorFlag($item);
                }
                $site = $this->persistMarketplaceSite($item);
                $item->site_id = $site->id;
                $this->moveStatus($item, PartnerCatalogItem::STATUS_LIVE, $actor ? 'admin' : 'system', $actor?->id, 'go_live');
                $item->pause_reason = null;
                $item->removed_at_source_at = null;
                $item->save();
                $live[] = (int) $item->id;
            });
        }

        if ($actor && $live !== []) {
            ActivityLogger::tryLog('partner.catalog.go_live', 'Put '.count($live).' partner site(s) live', null, [
                'item_ids' => $live,
            ], null, $actor);
        }

        return ['live' => $live, 'refused' => $refused];
    }

    /**
     * @param  list<int>  $itemIds
     * @return array{unlisted: list<int>}
     */
    public function unlistItems(array $itemIds, ?User $actor, string $reason = 'unlisted'): array
    {
        $unlisted = [];
        $items = PartnerCatalogItem::query()->whereIn('id', $itemIds)->get();
        foreach ($items as $item) {
            DB::transaction(function () use ($item, $actor, $reason, &$unlisted) {
                $item = PartnerCatalogItem::query()->lockForUpdate()->findOrFail($item->id);
                if ($item->site_id && Schema::hasColumn('sites', 'active')) {
                    $site = Site::query()->find($item->site_id);
                    if ($site) {
                        $site->active = false;
                        $site->save();
                    }
                }
                $target = $reason === 'removed_at_source'
                    ? PartnerCatalogItem::STATUS_REMOVED
                    : PartnerCatalogItem::STATUS_UNUSED;
                if ($reason === 'paused' || $item->listing_status === PartnerCatalogItem::STATUS_LIVE) {
                    $target = $reason === 'removed_at_source'
                        ? PartnerCatalogItem::STATUS_REMOVED
                        : PartnerCatalogItem::STATUS_PAUSED;
                }
                $this->moveStatus($item, $target, $actor ? 'admin' : 'system', $actor?->id, $reason);
                $item->pause_reason = $reason;
                $item->save();
                $unlisted[] = (int) $item->id;
            });
        }

        return ['unlisted' => $unlisted];
    }

    public function pauseLive(PartnerCatalogItem $item, string $reason, ?User $actor = null): void
    {
        if ($item->site_id) {
            $site = Site::query()->find($item->site_id);
            if ($site && Schema::hasColumn('sites', 'active')) {
                $site->active = false;
                $site->save();
            }
        }
        $this->moveStatus($item, PartnerCatalogItem::STATUS_PAUSED, $actor ? 'admin' : 'system', $actor?->id, $reason);
        $item->pause_reason = $reason;
        $item->save();
    }

    public function sendToReview(array $itemIds, ?User $actor): int
    {
        $n = 0;
        foreach (PartnerCatalogItem::query()->whereIn('id', $itemIds)->get() as $item) {
            if (in_array($item->listing_status, [PartnerCatalogItem::STATUS_BLOCKED, PartnerCatalogItem::STATUS_LIVE], true)) {
                continue;
            }
            $this->moveStatus($item, PartnerCatalogItem::STATUS_IN_REVIEW, $actor ? 'admin' : 'system', $actor?->id, 'send_to_review');
            $item->save();
            $n++;
        }

        return $n;
    }

    public function keepUnused(array $itemIds, ?User $actor): int
    {
        $n = 0;
        foreach (PartnerCatalogItem::query()->whereIn('id', $itemIds)->get() as $item) {
            if ($item->listing_status === PartnerCatalogItem::STATUS_LIVE) {
                $this->unlistItems([$item->id], $actor, 'keep_unused');

                continue;
            }
            $this->moveStatus($item, PartnerCatalogItem::STATUS_UNUSED, $actor ? 'admin' : 'system', $actor?->id, 'keep_unused');
            $item->save();
            $n++;
        }

        return $n;
    }

    public function reject(array $itemIds, ?User $actor, string $reason = 'other'): int
    {
        $n = 0;
        foreach (PartnerCatalogItem::query()->whereIn('id', $itemIds)->get() as $item) {
            if ($item->listing_status === PartnerCatalogItem::STATUS_LIVE) {
                $this->pauseLive($item, 'rejected', $actor);
            }
            $this->moveStatus($item, PartnerCatalogItem::STATUS_REJECTED, $actor ? 'admin' : 'system', $actor?->id, $reason);
            $item->save();
            $n++;
        }

        return $n;
    }

    public function goLiveBlockReason(PartnerCatalogItem $item, bool $overrideMargin = false, bool $overrideDuplicate = false): ?string
    {
        if ($item->casino || $item->listing_status === PartnerCatalogItem::STATUS_BLOCKED) {
            return 'blocked';
        }
        $partner = $item->partner;
        if (! $partner || ! $partner->isActive()) {
            return 'partner_inactive';
        }
        if ($item->duplicate && ! $overrideDuplicate) {
            return 'duplicate';
        }
        if ($item->unreachable) {
            return 'not_reachable';
        }
        if ($item->slb_price_publish_eur === null || (float) $item->slb_price_publish_eur <= 0) {
            $this->pricing->applySuggested($item, $item->pricingRule ?: $this->pricing->defaultRule());
        }
        if ($item->slb_price_publish_eur === null || (float) $item->slb_price_publish_eur <= 0) {
            return 'missing_slb_price';
        }
        if (! $this->pricing->holdsFloor((float) $item->slb_price_publish_eur, (float) $item->partner_rate_publish_eur, $overrideMargin || $item->margin_override)) {
            return 'below_margin_floor';
        }

        return null;
    }

    public function moveStatus(PartnerCatalogItem $item, string $to, string $actorType, ?int $actorId, ?string $reason): void
    {
        $from = $item->listing_status;
        if ($from === $to) {
            return;
        }
        $item->listing_status = $to;
        PartnerCatalogItemEvent::query()->create([
            'item_id' => $item->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'reason' => $reason,
        ]);
    }

    public function syncLiveSite(PartnerCatalogItem $item): ?Site
    {
        if (! $item->site_id && $item->listing_status !== PartnerCatalogItem::STATUS_LIVE) {
            return null;
        }

        return $this->persistMarketplaceSite($item);
    }

    public function persistMarketplaceSite(PartnerCatalogItem $item): Site
    {
        $partner = $item->relationLoaded('partner') ? $item->partner : Partner::query()->with('user')->findOrFail($item->partner_id);
        $publisherId = (int) $partner->user_id;
        $site = $item->site_id ? Site::query()->find($item->site_id) : null;
        if (! $site) {
            $site = Site::query()
                ->where('publisher_id', $publisherId)
                ->where('domain', $item->domain)
                ->first();
        }
        if (! $site) {
            $site = new Site;
        }

        $niches = is_array($item->niches) ? $item->niches : [];
        $description = app(SiteDescriptionSanitizer::class)->sanitize((string) $item->description);
        $mode = StaffListingPublishMode::staffAssignAttributes(StaffListingPublishMode::PUBLISH);
        $sensitive = $this->sensitiveMap($item);
        $attrs = array_merge([
            'publisher_id' => $publisherId,
            'owner_id' => $publisherId,
            'site_name' => $item->site_name,
            'site_url' => $item->site_url,
            'domain' => $item->domain,
            'example_url' => $item->example_url,
            'da' => $item->da,
            'dr' => $item->dr,
            'traffic' => $item->traffic,
            'metrics_manual' => true,
            'metrics_provider' => 'manual',
            'metrics_fetched_at' => now(),
            'country' => $item->country,
            'countries' => $item->country ? [$item->country] : [],
            'language' => $item->language,
            'languages' => $item->language ? [$item->language] : [],
            'category' => implode('|', $niches),
            'categories' => $niches,
            'price' => (float) $item->partner_rate_publish_eur,
            'listing_currency' => 'EUR',
            'listing_price' => (float) $item->partner_rate_publish_eur,
            'turnaround_time' => $item->turnaround_time ?: '7days',
            'publication_time' => $item->publication_time ?: 'permanent',
            'link_type' => $item->link_type ?: 'dofollow',
            'description' => $description,
            'writing_enabled' => $item->partner_rate_writing_eur !== null,
            'writing_fee' => $item->partner_rate_writing_eur,
            'listing_writing_fee' => $item->partner_rate_writing_eur,
            'sensitive_prices' => $sensitive,
            'listing_sensitive_prices' => $sensitive,
            'sponsored' => in_array($item->sponsored_label, ['always', 'sometimes', 'sponsored'], true),
        ], $mode);

        if (Site::hasSitesColumn('partner_id')) {
            $attrs['partner_id'] = $partner->id;
        }
        if (Site::hasSitesColumn('partner_catalog_item_id')) {
            $attrs['partner_catalog_item_id'] = $item->id;
        }
        if (Site::hasSitesColumn('slb_price_publish_eur')) {
            $attrs['slb_price_publish_eur'] = $item->slb_price_publish_eur;
        }
        if (Site::hasSitesColumn('slb_price_writing_eur')) {
            $attrs['slb_price_writing_eur'] = $item->slb_price_writing_eur;
        }
        if (Site::hasSitesColumn('slb_price_sensitive_extra_eur')) {
            $attrs['slb_price_sensitive_extra_eur'] = $item->slb_price_sensitive_extra_eur;
        }
        if (Site::hasSitesColumn('partner_rate_version')) {
            $attrs['partner_rate_version'] = $item->partner_rate_version;
        }

        $site->applyMarketplaceListing($attrs);
        foreach (['slb_price_publish_eur', 'slb_price_writing_eur', 'slb_price_sensitive_extra_eur', 'partner_id', 'partner_catalog_item_id', 'partner_rate_version', 'writing_fee', 'listing_writing_fee', 'writing_enabled'] as $column) {
            if (array_key_exists($column, $attrs) && Site::hasSitesColumn($column)) {
                $site->setAttribute($column, $attrs[$column]);
            }
        }
        $site->active = true;
        $site->save();

        return $site;
    }

    /**
     * @return array<string, float>
     */
    private function sensitiveMap(PartnerCatalogItem $item): array
    {
        $extra = $item->partner_rate_sensitive_extra_eur;
        if ($extra === null || (float) $extra <= 0) {
            return [];
        }
        $map = [];
        foreach (CatalogSensitiveTopics::FILTER_TYPES as $type) {
            $map[$type] = round((float) $extra, 2);
        }

        return $map;
    }
}
