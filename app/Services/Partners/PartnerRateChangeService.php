<?php

namespace App\Services\Partners;

use App\Models\PartnerCatalogItem;
use App\Models\PartnerSiteRateChange;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

class PartnerRateChangeService
{
    public function __construct(
        private PartnerPricingService $pricing,
        private PartnerCatalogGoLiveService $goLive,
    ) {}

    /**
     * Option C: cuts apply at once; rises apply at once if the floor still holds,
     * otherwise queue for admin. The live partner rate does not change while pending.
     *
     * @param  array{publish: float, writing: ?float, sensitive_extra: ?float, currency: string, publish_eur: float, writing_eur: ?float, sensitive_extra_eur: ?float, fx_rate: ?float, fx_rate_date: ?string}  $new
     */
    /**
     * Unused / in-review items take the new partner rate immediately (no Option C queue).
     *
     * @param  array{publish: float, writing: ?float, sensitive_extra: ?float, currency: string, publish_eur: float, writing_eur: ?float, sensitive_extra_eur: ?float, fx_rate: ?float, fx_rate_date: ?string}  $new
     */
    public function applyImmediately(PartnerCatalogItem $item, array $new, string $source): PartnerSiteRateChange
    {
        $change = new PartnerSiteRateChange(['item_id' => $item->id]);
        $change->fill([
            'old_values' => $this->snapshot($item),
            'new_values' => $new,
            'source' => $source,
        ]);
        $this->applyNewRate($item, $new, raiseSlb: true);
        $change->status = PartnerSiteRateChange::STATUS_APPLIED;
        $change->effective_from = now();
        $change->save();
        $item->save();

        return $change;
    }

    public function handle(PartnerCatalogItem $item, array $new, string $source): PartnerSiteRateChange
    {
        $oldPublish = round((float) $item->partner_rate_publish_eur, 2);
        $newPublish = round((float) $new['publish_eur'], 2);
        $pending = PartnerSiteRateChange::query()
            ->where('item_id', $item->id)
            ->where('status', PartnerSiteRateChange::STATUS_PENDING)
            ->first();

        $payload = [
            'old_values' => $this->snapshot($item),
            'new_values' => $new,
            'source' => $source,
        ];

        if ($newPublish <= $oldPublish + 0.001) {
            $change = $pending ?? new PartnerSiteRateChange(['item_id' => $item->id]);
            $change->fill($payload);
            $this->applyNewRate($item, $new, raiseSlb: false);
            $change->status = PartnerSiteRateChange::STATUS_APPLIED;
            $change->effective_from = now();
            $change->save();
            $item->save();
            if ($item->isLive()) {
                $this->goLive->syncLiveSite($item);
            }

            return $change;
        }

        $slb = (float) ($item->slb_price_publish_eur ?? 0);
        if ($this->pricing->holdsFloor($slb, $newPublish, (bool) $item->margin_override)) {
            $change = $pending ?? new PartnerSiteRateChange(['item_id' => $item->id]);
            $change->fill($payload);
            $this->applyNewRate($item, $new, raiseSlb: false);
            $change->status = PartnerSiteRateChange::STATUS_APPLIED;
            $change->effective_from = now();
            $change->save();
            $this->pricing->refreshFloorFlag($item);
            $item->save();
            if ($item->isLive()) {
                $this->goLive->syncLiveSite($item);
            }

            return $change;
        }

        $limit = max(0, (int) config('partners.rate_increase_limit_per_year', 4));
        $approvedThisYear = PartnerSiteRateChange::query()
            ->where('item_id', $item->id)
            ->whereIn('status', [PartnerSiteRateChange::STATUS_APPROVED, PartnerSiteRateChange::STATUS_APPLIED])
            ->where('created_at', '>=', now()->startOfYear())
            ->get()
            ->filter(function (PartnerSiteRateChange $row) {
                $old = (float) data_get($row->old_values, 'publish_eur', 0);
                $new = (float) data_get($row->new_values, 'publish_eur', 0);

                return $new > $old + 0.001;
            })
            ->count();
        if ($limit > 0 && $approvedThisYear >= $limit) {
            $payload['decision_reason'] = 'annual_increase_limit';
        }

        $change = $pending ?? new PartnerSiteRateChange(['item_id' => $item->id]);
        $change->fill($payload);
        $change->status = PartnerSiteRateChange::STATUS_PENDING;
        $change->save();

        return $change;
    }

    public function approve(PartnerSiteRateChange $change, User $actor, bool $raiseSlb = true, ?string $reason = null): PartnerCatalogItem
    {
        return DB::transaction(function () use ($change, $actor, $raiseSlb, $reason) {
            $item = PartnerCatalogItem::query()->lockForUpdate()->findOrFail($change->item_id);
            $new = $change->new_values ?? [];
            $this->applyNewRate($item, $new, raiseSlb: $raiseSlb);
            if (! $raiseSlb) {
                $item->margin_override = true;
                $item->margin_override_reason = $reason ?: 'admin_accepted_thinner_margin';
            }
            $this->pricing->refreshFloorFlag($item);
            $item->save();
            $change->status = PartnerSiteRateChange::STATUS_APPROVED;
            $change->decided_by = $actor->id;
            $change->decided_at = now();
            $change->effective_from = now();
            $change->slb_price_action = $raiseSlb ? 'raise' : 'keep';
            $change->decision_reason = $reason;
            $change->save();
            if ($item->isLive()) {
                $this->goLive->syncLiveSite($item);
            }
            ActivityLogger::tryLog('partner.rate_change.approved', 'Approved partner rate change for '.$item->domain, $item, [
                'raise_slb' => $raiseSlb,
            ], null, $actor);

            return $item->fresh() ?? $item;
        });
    }

    public function decline(PartnerSiteRateChange $change, User $actor, ?string $reason = null): PartnerCatalogItem
    {
        return DB::transaction(function () use ($change, $actor, $reason) {
            $item = PartnerCatalogItem::query()->lockForUpdate()->findOrFail($change->item_id);
            $change->status = PartnerSiteRateChange::STATUS_DECLINED;
            $change->decided_by = $actor->id;
            $change->decided_at = now();
            $change->decision_reason = $reason;
            $change->save();
            ActivityLogger::tryLog('partner.rate_change.declined', 'Declined partner rate change for '.$item->domain, $item, [], null, $actor);

            return $item;
        });
    }

    /**
     * @param  array<string, mixed>  $new
     */
    private function applyNewRate(PartnerCatalogItem $item, array $new, bool $raiseSlb): void
    {
        $item->partner_rate_currency = strtoupper((string) ($new['currency'] ?? $item->partner_rate_currency));
        $item->partner_rate_publish = (float) ($new['publish'] ?? $item->partner_rate_publish);
        $item->partner_rate_writing = $new['writing'] ?? null;
        $item->partner_rate_sensitive_extra = $new['sensitive_extra'] ?? null;
        $item->partner_rate_publish_eur = (float) ($new['publish_eur'] ?? $item->partner_rate_publish_eur);
        $item->partner_rate_writing_eur = $new['writing_eur'] ?? null;
        $item->partner_rate_sensitive_extra_eur = $new['sensitive_extra_eur'] ?? null;
        $item->fx_rate = $new['fx_rate'] ?? $item->fx_rate;
        $item->fx_rate_date = $new['fx_rate_date'] ?? $item->fx_rate_date;
        $item->partner_rate_version = (int) $item->partner_rate_version + 1;
        $item->partner_rate_effective_from = now();
        if ($raiseSlb) {
            $this->pricing->applySuggested($item, $item->pricingRule ?: $this->pricing->defaultRule(), overwriteManual: false);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(PartnerCatalogItem $item): array
    {
        return [
            'currency' => $item->partner_rate_currency,
            'publish' => (float) $item->partner_rate_publish,
            'writing' => $item->partner_rate_writing !== null ? (float) $item->partner_rate_writing : null,
            'sensitive_extra' => $item->partner_rate_sensitive_extra !== null ? (float) $item->partner_rate_sensitive_extra : null,
            'publish_eur' => (float) $item->partner_rate_publish_eur,
            'writing_eur' => $item->partner_rate_writing_eur !== null ? (float) $item->partner_rate_writing_eur : null,
            'sensitive_extra_eur' => $item->partner_rate_sensitive_extra_eur !== null ? (float) $item->partner_rate_sensitive_extra_eur : null,
            'fx_rate' => $item->fx_rate !== null ? (float) $item->fx_rate : null,
            'fx_rate_date' => optional($item->fx_rate_date)?->toDateString(),
            'partner_rate_version' => (int) $item->partner_rate_version,
        ];
    }
}
