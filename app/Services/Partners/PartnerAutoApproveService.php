<?php

namespace App\Services\Partners;

use App\Models\Partner;
use App\Models\PartnerAutoApproveRule;
use App\Models\PartnerCatalogItem;

class PartnerAutoApproveService
{
    public function __construct(
        private PartnerPricingService $pricing,
        private PartnerCatalogGoLiveService $goLive,
    ) {}

    /**
     * Run matching rules on a newly imported unused item.
     */
    public function applyToNewItem(PartnerCatalogItem $item, Partner $partner): void
    {
        $match = $this->firstMatch($item, $partner);
        if ($match === null) {
            return;
        }

        $item->matched_rule_id = $match->id;
        $hard = $this->hardBlockReason($item);
        $wantsLive = $match->action === PartnerAutoApproveRule::ACTION_LIVE && $partner->trusted;
        if ($hard !== null || ! $wantsLive) {
            $this->goLive->moveStatus($item, PartnerCatalogItem::STATUS_IN_REVIEW, 'system', null, $hard ?? 'auto_rule');
            $item->save();

            return;
        }

        $this->pricing->applySuggested($item, $match->pricingRule ?: $this->pricing->defaultRule());
        $item->save();
        $this->goLive->goLiveItems([$item->id], null, [
            'from_rule' => true,
            'pricing_rule_id' => $match->pricing_rule_id,
        ]);
    }

    /**
     * @return array{count: int, ids: list<int>}
     */
    public function dryRun(PartnerAutoApproveRule $rule, ?Partner $partner = null): array
    {
        $query = PartnerCatalogItem::query()->where('listing_status', PartnerCatalogItem::STATUS_UNUSED);
        if ($rule->partner_id) {
            $query->where('partner_id', $rule->partner_id);
        } elseif ($partner) {
            $query->where('partner_id', $partner->id);
        }
        $ids = [];
        $wouldLive = 0;
        $wouldReview = 0;
        $query->orderBy('id')->chunkById(200, function ($items) use ($rule, &$ids, &$wouldLive, &$wouldReview) {
            foreach ($items as $item) {
                $owner = $item->partner;
                if ($owner && $this->matches($rule, $item, $owner)) {
                    $ids[] = (int) $item->id;
                    $hard = $this->hardBlockReason($item);
                    $wantsLive = $rule->action === PartnerAutoApproveRule::ACTION_LIVE && $owner->trusted && $hard === null;
                    if ($wantsLive) {
                        $wouldLive++;
                    } else {
                        $wouldReview++;
                    }
                }
            }
        });

        return [
            'count' => count($ids),
            'ids' => $ids,
            'would_go_live' => $wouldLive,
            'would_send_to_review' => $wouldReview,
            'note' => 'go_live only applies to trusted partners; every other match is sent to review',
        ];
    }

    public function firstMatch(PartnerCatalogItem $item, Partner $partner): ?PartnerAutoApproveRule
    {
        $rules = PartnerAutoApproveRule::query()
            ->where('active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            if ($this->matches($rule, $item, $partner)) {
                return $rule;
            }
        }

        return null;
    }

    public function matches(PartnerAutoApproveRule $rule, PartnerCatalogItem $item, Partner $partner): bool
    {
        if ($rule->partner_id && (int) $rule->partner_id !== (int) $partner->id) {
            return false;
        }
        if ($rule->min_dr !== null && (int) $item->dr < (int) $rule->min_dr) {
            return false;
        }
        if ($rule->min_da !== null && (int) $item->da < (int) $rule->min_da) {
            return false;
        }
        if ($rule->min_monthly_traffic !== null && (int) $item->traffic < (int) $rule->min_monthly_traffic) {
            return false;
        }
        $linkTypes = $rule->link_types ?? [];
        if ($linkTypes !== [] && ! in_array($item->link_type, $linkTypes, true)) {
            return false;
        }
        if ($rule->require_sample_post && blank($item->example_url)) {
            return false;
        }
        $countries = array_map('strtolower', $rule->countries ?? []);
        if ($countries !== [] && ! in_array(strtolower((string) $item->country), $countries, true)) {
            return false;
        }
        $languages = array_map('strtolower', $rule->languages ?? []);
        if ($languages !== [] && ! in_array(strtolower((string) $item->language), $languages, true)) {
            return false;
        }
        if ($rule->max_partner_rate_eur !== null && (float) $item->partner_rate_publish_eur > (float) $rule->max_partner_rate_eur) {
            return false;
        }
        $niches = is_array($item->niches) ? $item->niches : [];
        $any = $rule->categories_any ?? [];
        if ($any !== [] && empty(array_intersect($niches, $any))) {
            return false;
        }
        $none = $rule->categories_none ?? [];
        if ($none !== [] && array_intersect($niches, $none) !== []) {
            return false;
        }
        if ($rule->reject_sensitive_only && $this->sensitiveOnly($niches)) {
            return false;
        }

        return true;
    }

    /**
     * @param  list<string>  $niches
     */
    public function sensitiveOnly(array $niches): bool
    {
        if ($niches === []) {
            return false;
        }
        $sensitive = (array) config('partners.sensitive_niches', []);
        foreach ($niches as $niche) {
            if (! in_array($niche, $sensitive, true)) {
                return false;
            }
        }

        return true;
    }

    public function hardBlockReason(PartnerCatalogItem $item): ?string
    {
        if ($item->casino || $item->listing_status === PartnerCatalogItem::STATUS_BLOCKED) {
            return 'casino_not_allowed';
        }
        if ($item->duplicate) {
            return 'duplicate';
        }
        if ($item->unreachable) {
            return 'not_reachable';
        }
        if ($item->slb_price_publish_eur === null || (float) $item->slb_price_publish_eur <= 0) {
            // Pricing is applied just before go-live; missing price is not a rule hard-block until then.
        }

        return null;
    }
}
